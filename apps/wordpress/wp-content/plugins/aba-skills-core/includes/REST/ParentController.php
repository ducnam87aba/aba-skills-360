<?php

namespace ABA\Core\REST;

use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Services\SkillsProfileService;
use ABA\Core\Services\RecommendationService;
use ABA\Core\Services\ParentOverviewService;
use ABA\Core\Services\ReportService;
use ABA\Core\Auth\Authorization;
use WP_REST_Request;
use WP_REST_Response;

class ParentController {
    private StudentRepository $studentRepo;
    private SkillsProfileService $skillsService;
    private RecommendationService $recService;
    private ParentOverviewService $overviewService;
    private ReportService $reportService;

    public function __construct() {
        $this->studentRepo = new StudentRepository();
        $this->skillsService = new SkillsProfileService();
        $this->recService = new RecommendationService();
        $this->overviewService = new ParentOverviewService();
        $this->reportService = new ReportService();
    }

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        // GET /aba/v1/parent/children
        register_rest_route($namespace, '/parent/children', [
            'methods' => 'GET',
            'callback' => [$this, 'getChildren'],
            'permission_callback' => [Authorization::class, 'requireParent'],
        ]);

        // GET /aba/v1/students/{id}/reports  (stored history, falls back to a live composition)
        register_rest_route($namespace, '/students/(?P<id>\d+)/reports', [
            'methods' => 'GET',
            'callback' => [$this, 'getStudentReports'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // POST /aba/v1/students/{id}/reports/generate  (persist an immutable snapshot)
        register_rest_route($namespace, '/students/(?P<id>\d+)/reports/generate', [
            'methods' => 'POST',
            'callback' => [$this, 'generateStudentReport'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // PA21 — GET /aba/v1/students/{id}/reports/{reportId} (one snapshot + derived delivery).
        register_rest_route($namespace, '/students/(?P<id>\d+)/reports/(?P<reportId>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getStudentReport'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // PA21 — POST /aba/v1/students/{id}/reports/{reportId}/read (record a read receipt).
        register_rest_route($namespace, '/students/(?P<id>\d+)/reports/(?P<reportId>\d+)/read', [
            'methods' => 'POST',
            'callback' => [$this, 'markStudentReportRead'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/parent/children/{id}/overview  (api-contract §37)
        register_rest_route($namespace, '/parent/children/(?P<id>\d+)/overview', [
            'methods' => 'GET',
            'callback' => [$this, 'getChildOverview'],
            'permission_callback' => [Authorization::class, 'requireParent'],
        ]);

        // GET /aba/v1/parent/children/{id}/skills  (api-contract §38)
        register_rest_route($namespace, '/parent/children/(?P<id>\d+)/skills', [
            'methods' => 'GET',
            'callback' => [$this, 'getChildSkills'],
            'permission_callback' => [Authorization::class, 'requireParent'],
        ]);

        // PA40 — GET /aba/v1/parent/children/{id}/activities  (child's real activity feed)
        register_rest_route($namespace, '/parent/children/(?P<id>\d+)/activities', [
            'methods' => 'GET',
            'callback' => [$this, 'getChildActivities'],
            'permission_callback' => [Authorization::class, 'requireParent'],
        ]);
    }

    public function getChildOverview(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        // Server-side parent-child verification (never trust the client's id).
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $overview = $this->overviewService->build($studentId);
        if ($overview === null) {
            return new \WP_Error('ABA_STUDENT_NOT_FOUND', 'Student not found.', ['status' => 404]);
        }
        return rest_ensure_response($overview);
    }

    public function getChildSkills(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        return rest_ensure_response([
            'studentId' => $studentId,
            'skills' => $this->skillsService->getCurrentScores($studentId),
        ]);
    }

    /**
     * PA40 — the parent's view of a child's recent activity. Permission is the
     * server-side parent-child check (never the client's id, §8); the feed is
     * derived from the progress + XP ledgers and the achievement timeline (§12).
     */
    public function getChildActivities(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $feed = $this->overviewService->buildActivityFeed($studentId);
        if ($feed === null) {
            return new \WP_Error('ABA_STUDENT_NOT_FOUND', 'Student not found.', ['status' => 404]);
        }
        return rest_ensure_response($feed);
    }

    public function getChildren(): WP_REST_Response|\WP_Error {
        $auth = Authorization::requireParent();
        if (is_wp_error($auth)) {
            return $auth;
        }
        $parentUserId = \get_current_user_id();
        $children = $this->studentRepo->getChildrenForParent($parentUserId);
        // PA26: add each child's class + teacher context (composition in the
        // service, not here, so the controller stays a thin transport layer §4).
        $children = $this->overviewService->decorateChildren($children);
        return rest_ensure_response($children);
    }

    public function getStudentReports(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        if (!$this->studentRepo->findById($studentId)) {
            return new \WP_Error('ABA_STUDENT_NOT_FOUND', 'Student not found.', ['status' => 404]);
        }
        // Stored snapshots (newest first); a learner with none yet gets a live composition.
        return rest_ensure_response($this->reportService->listOrCompose($studentId));
    }

    public function generateStudentReport(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $report = $this->reportService->generate($studentId);
        if ($report === null) {
            return new \WP_Error('ABA_STUDENT_NOT_FOUND', 'Student not found.', ['status' => 404]);
        }
        return rest_ensure_response($report);
    }

    public function getStudentReport(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $report = $this->reportService->find((int)$request->get_param('reportId'), $studentId);
        if ($report === null) {
            return new \WP_Error('ABA_REPORT_NOT_FOUND', 'Report not found.', ['status' => 404]);
        }
        return rest_ensure_response($report);
    }

    public function markStudentReportRead(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        // Confirm the snapshot belongs to this learner before recording a receipt.
        $reportId = (int)$request->get_param('reportId');
        if ($this->reportService->find($reportId, $studentId) === null) {
            return new \WP_Error('ABA_REPORT_NOT_FOUND', 'Report not found.', ['status' => 404]);
        }
        $delivery = $this->reportService->markRead($studentId, $reportId, \get_current_user_id() ?: null);
        return rest_ensure_response(['reportId' => $reportId, 'delivery' => $delivery]);
    }
}
