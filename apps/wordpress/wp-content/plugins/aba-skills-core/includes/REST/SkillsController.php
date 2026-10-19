<?php

namespace ABA\Core\REST;

use ABA\Core\Services\SkillsProfileService;
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
            'permission_callback' => '__return_true',
        ]);

        // GET /aba/v1/skills/current
        register_rest_route($namespace, '/skills/current', [
            'methods' => 'GET',
            'callback' => [$this, 'getCurrentSkillsDefault'],
            'permission_callback' => '__return_true',
        ]);

        // GET /aba/v1/students/{id}/skills/history
        register_rest_route($namespace, '/students/(?P<id>\d+)/skills/history', [
            'methods' => 'GET',
            'callback' => [$this, 'getSkillHistory'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function getCurrentSkills(WP_REST_Request $request): WP_REST_Response {
        $studentId = (int)$request->get_param('id');
        $scores = $this->skillsService->getCurrentScores($studentId);
        return rest_ensure_response($scores);
    }

    public function getCurrentSkillsDefault(): WP_REST_Response {
        $scores = $this->skillsService->getCurrentScores(1);
        return rest_ensure_response($scores);
    }

    public function getSkillHistory(WP_REST_Request $request): WP_REST_Response {
        $studentId = (int)$request->get_param('id');
        $history = $this->skillsService->getHistory($studentId);
        return rest_ensure_response($history);
    }
}
