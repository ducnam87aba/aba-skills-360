<?php

namespace ABA\Core\REST;

use ABA\Core\Services\SkillsProfileService;
use ABA\Core\Auth\Authorization;
use WP_REST_Request;
use WP_REST_Response;

class SkillsController {
    private SkillsProfileService $skillsService;

    public function __construct() {
        $this->skillsService = new SkillsProfileService();
    }

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        // GET /aba/v1/students/{id}/skills/current
        register_rest_route($namespace, '/students/(?P<id>\d+)/skills/current', [
            'methods' => 'GET',
            'callback' => [$this, 'getCurrentSkills'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/skills/current
        register_rest_route($namespace, '/skills/current', [
            'methods' => 'GET',
            'callback' => [$this, 'getCurrentSkillsDefault'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/students/{id}/skills/history
        register_rest_route($namespace, '/students/(?P<id>\d+)/skills/history', [
            'methods' => 'GET',
            'callback' => [$this, 'getSkillHistory'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/skills/history (self, resolved from the authenticated login)
        register_rest_route($namespace, '/skills/history', [
            'methods' => 'GET',
            'callback' => [$this, 'getSkillHistoryDefault'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/students/{id}/skills/benchmark  (anonymized class average, §11)
        register_rest_route($namespace, '/students/(?P<id>\d+)/skills/benchmark', [
            'methods' => 'GET',
            'callback' => [$this, 'getClassBenchmark'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/skills/benchmark (self)
        register_rest_route($namespace, '/skills/benchmark', [
            'methods' => 'GET',
            'callback' => [$this, 'getClassBenchmarkDefault'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/students/{id}/skills/class-trend  (longitudinal class average, §11)
        register_rest_route($namespace, '/students/(?P<id>\d+)/skills/class-trend', [
            'methods' => 'GET',
            'callback' => [$this, 'getClassTrend'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // GET /aba/v1/skills/class-trend (self)
        register_rest_route($namespace, '/skills/class-trend', [
            'methods' => 'GET',
            'callback' => [$this, 'getClassTrendDefault'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public function getCurrentSkills(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $scores = $this->skillsService->getCurrentScores($studentId, $this->resolveSource($request));
        return rest_ensure_response($scores);
    }

    public function getCurrentSkillsDefault(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = Authorization::currentStudentId();
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $scores = $this->skillsService->getCurrentScores($studentId, $this->resolveSource($request));
        return rest_ensure_response($scores);
    }

    /**
     * Measurement source selector (§54). Unknown or omitted values fall back to
     * the learner's own self-report so the default projection is unchanged.
     */
    private function resolveSource(WP_REST_Request $request): string {
        $allowed = ['student', 'teacher', 'parent'];
        $source = (string)($request->get_param('source') ?? 'student');
        return in_array($source, $allowed, true) ? $source : 'student';
    }

    public function getSkillHistory(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $history = $this->skillsService->getHistory($studentId);
        return rest_ensure_response($history);
    }

    /**
     * Self longitudinal history (§11). Identity comes from the login, never a
     * client-supplied id, so a learner cannot read another student's trend.
     */
    public function getSkillHistoryDefault(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = Authorization::currentStudentId();
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $history = $this->skillsService->getHistory($studentId);
        return rest_ensure_response($history);
    }

    /**
     * Class benchmark for a specific learner (PA31). Same view permission as the
     * score itself — a caller who can read this student may see the anonymized
     * class mean; the aggregate never exposes any individual peer (§8).
     */
    public function getClassBenchmark(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        return rest_ensure_response($this->skillsService->getClassBenchmark($studentId, $this->resolveSource($request)));
    }

    /** Self benchmark, resolved from the login (never a client-supplied id). */
    public function getClassBenchmarkDefault(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = Authorization::currentStudentId();
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        return rest_ensure_response($this->skillsService->getClassBenchmark($studentId, $this->resolveSource($request)));
    }

    /** Longitudinal class average for a learner (PA32). View permission, aggregate only (§8). */
    public function getClassTrend(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int)$request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        return rest_ensure_response($this->skillsService->getClassTrend($studentId));
    }

    /** Self class trend, resolved from the login. */
    public function getClassTrendDefault(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = Authorization::currentStudentId();
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        return rest_ensure_response($this->skillsService->getClassTrend($studentId));
    }
}
