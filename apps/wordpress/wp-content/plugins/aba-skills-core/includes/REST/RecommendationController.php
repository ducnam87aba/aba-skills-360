<?php

namespace ABA\Core\REST;

use ABA\Core\Services\SkillsProfileService;
use ABA\Core\Services\RecommendationService;
use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Auth\Authorization;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Recommendation Engine endpoints (AGENTS.md §13). Presentation stays thin: the
 * controller only resolves scope + grade group and delegates the deterministic
 * rules to RecommendationService.
 */
class RecommendationController {
    private SkillsProfileService $skillsService;
    private RecommendationService $recService;
    private StudentRepository $studentRepo;

    public function __construct() {
        $this->skillsService = new SkillsProfileService();
        $this->recService = new RecommendationService();
        $this->studentRepo = new StudentRepository();
    }

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        // GET /aba/v1/recommendations  (current learner)
        register_rest_route($namespace, '/recommendations', [
            'methods' => 'GET',
            'callback' => [$this, 'getCurrent'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/students/{id}/recommendations
        register_rest_route($namespace, '/students/(?P<id>\d+)/recommendations', [
            'methods' => 'GET',
            'callback' => [$this, 'getForStudent'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public function getCurrent(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = Authorization::currentStudentId();
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        return $this->respond($studentId, $this->resolveSource($request));
    }

    public function getForStudent(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int) $request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        return $this->respond($studentId, $this->resolveSource($request));
    }

    private function respond(int $studentId, string $source): WP_REST_Response|\WP_Error {
        $student = $this->studentRepo->findById($studentId);
        if (!$student) {
            return \ABA\Core\Auth\aba_error('ABA_STUDENT_NOT_FOUND', 'Student not found.', 404);
        }

        $scores = $this->skillsService->getCurrentScores($studentId, $source);
        $gradeGroup = (string) ($student['grade_group'] ?? '4_6');

        $envelope = $this->recService->build($scores, $gradeGroup);
        $envelope['studentId'] = $studentId;
        $envelope['source'] = $source;

        return rest_ensure_response($envelope);
    }

    /** Recommendation basis defaults to the learner's self-report (§54). */
    private function resolveSource(WP_REST_Request $request): string {
        $allowed = ['student', 'teacher', 'parent'];
        $source = (string) ($request->get_param('source') ?? 'student');
        return in_array($source, $allowed, true) ? $source : 'student';
    }
}
