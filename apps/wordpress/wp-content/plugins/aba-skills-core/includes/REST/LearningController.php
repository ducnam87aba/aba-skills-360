<?php

namespace ABA\Core\REST;

use ABA\Core\Repositories\LessonProgressRepository;
use ABA\Core\Repositories\XpTransactionRepository;
use ABA\Core\Repositories\StreakRepository;
use ABA\Core\Services\LearningPathService;
use ABA\Core\Services\LessonService;
use ABA\Core\Auth\Authorization;
use function ABA\Core\Auth\aba_error;
use WP_REST_Request;
use WP_REST_Response;

class LearningController {
    private LessonProgressRepository $progressRepo;
    private XpTransactionRepository $xpRepo;
    private StreakRepository $streakRepo;
    private LearningPathService $pathService;
    private LessonService $lessonService;

    public function __construct() {
        $this->progressRepo = new LessonProgressRepository();
        $this->xpRepo = new XpTransactionRepository();
        $this->streakRepo = new StreakRepository();
        $this->pathService = new LearningPathService();
        $this->lessonService = new LessonService();
    }

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        // GET /aba/v1/learning/path
        register_rest_route($namespace, '/learning/path', [
            'methods' => 'GET',
            'callback' => [$this, 'getLearningPath'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/learning/lessons — browsable Content Library catalog (PA17)
        register_rest_route($namespace, '/learning/lessons', [
            'methods' => 'GET',
            'callback' => [$this, 'getLessons'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/lessons/(?P<id>\d+) — a single lesson's real body (PA17).
        // Numeric id is a distinct route from the string-node /complete below.
        register_rest_route($namespace, '/lessons/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getLesson'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // POST /aba/v1/lessons/(?P<id>[a-zA-Z0-9_\-]+)/complete
        register_rest_route($namespace, '/lessons/(?P<id>[a-zA-Z0-9_\-]+)/complete', [
            'methods' => 'POST',
            'callback' => [$this, 'completeLessonNode'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);
    }

    public function getLearningPath(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        // Own student by default; allow an explicit studentId only when permitted.
        $studentId = Authorization::currentStudentId();
        $requested = $request->get_param('studentId');
        if ($requested !== null && (int)$requested !== $studentId) {
            $studentId = (int)$requested;
        }
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        // Path is derived from measurement: RecommendationService orders the units
        // by the learner's biggest growth gaps, progress gates node unlocking (PA7).
        $source = $this->resolveSource($request);

        return rest_ensure_response($this->pathService->build($studentId, $source));
    }

    /** Ordering basis defaults to the learner's self-report (§54). */
    private function resolveSource(WP_REST_Request $request): string {
        $allowed = ['student', 'teacher', 'parent'];
        $source = (string) ($request->get_param('source') ?? 'student');
        return in_array($source, $allowed, true) ? $source : 'student';
    }

    /**
     * GET /learning/lessons — editorial catalog, optionally scoped to one
     * competency. Content is non-sensitive editorial material, so login is the
     * gate (§8); no per-student authorization applies to reading a lesson.
     */
    public function getLessons(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $competency = $request->get_param('competency');
        $competency = is_string($competency) && $competency !== '' ? strtoupper($competency) : null;
        $lessons = $this->lessonService->catalog($competency);
        return rest_ensure_response([
            'count' => count($lessons),
            'lessons' => $lessons,
        ]);
    }

    /** GET /lessons/{id} — the real body behind a learning-path node's contentId. */
    public function getLesson(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $lesson = $this->lessonService->get((int) $request->get_param('id'));
        if ($lesson === null) {
            return aba_error('LESSON_NOT_FOUND', 'Lesson not found.', 404);
        }
        return rest_ensure_response($lesson);
    }

    public function completeLessonNode(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $nodeId = (string)$request->get_param('id');
        $params = $request->get_json_params() ?: [];
        $xpReward = (int)($params['xpReward'] ?? 40);

        // Write progress only for the authenticated student's own record.
        $studentId = Authorization::currentStudentId();
        $scope = Authorization::requireStudentScope($studentId);
        if (is_wp_error($scope)) {
            return $scope;
        }

        // Record progress in custom table
        $this->progressRepo->recordProgress($studentId, $nodeId, 'completed', 100.0, $xpReward);

        // Record XP transaction
        $this->xpRepo->recordTransaction(
            $studentId,
            $xpReward,
            'lesson',
            $nodeId,
            "Hoàn thành bài học {$nodeId}"
        );

        // Mark today active so the streak is derived from the activity calendar
        // (§12). Idempotent per (student, date); a second lesson the same day does
        // not extend the run.
        $this->streakRepo->recordActivity($studentId);

        return rest_ensure_response([
            'success' => true,
            'nodeId' => $nodeId,
            'xpAwarded' => $xpReward,
            'status' => 'completed',
        ]);
    }
}
