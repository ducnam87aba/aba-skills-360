<?php

namespace ABA\Core\REST;

use ABA\Core\Services\AssessmentService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class AssessmentController {
    private AssessmentService $assessmentService;

    public function __construct() {
        $this->assessmentService = new AssessmentService();
    }

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        // POST /aba/v1/assessments/{id}/start
        register_rest_route($namespace, '/assessments/(?P<id>\d+)/start', [
            'methods' => 'POST',
            'callback' => [$this, 'startAssessment'],
            'permission_callback' => '__return_true',
        ]);

        // POST /aba/v1/assessment-sessions/{id}/responses
        register_rest_route($namespace, '/assessment-sessions/(?P<id>\d+)/responses', [
            'methods' => 'POST',
            'callback' => [$this, 'recordResponse'],
            'permission_callback' => '__return_true',
        ]);

        // POST /aba/v1/assessment-sessions/{id}/complete
        register_rest_route($namespace, '/assessment-sessions/(?P<id>\d+)/complete', [
            'methods' => 'POST',
            'callback' => [$this, 'completeAssessment'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function startAssessment(WP_REST_Request $request): WP_REST_Response {
        $assessmentId = (int)$request->get_param('id');
        $params = $request->get_json_params() ?: [];
        $studentId = (int)($params['studentId'] ?? 1);

        $session = $this->assessmentService->startSession($assessmentId, $studentId);

        return rest_ensure_response([
            'id' => (int)$session['id'],
            'assessmentId' => (int)$session['assessment_id'],
            'studentId' => (int)$session['student_id'],
            'version' => $session['assessment_version'],
            'status' => $session['status'],
            'currentQuestionIndex' => (int)$session['current_step'],
            'totalQuestions' => (int)$session['total_steps'],
            'startedAt' => $session['started_at'],
            'responses' => [],
        ]);
    }

    public function recordResponse(WP_REST_Request $request): WP_REST_Response {
        $sessionId = (int)$request->get_param('id');
        $params = $request->get_json_params() ?: [];

        $questionId = (int)($params['questionId'] ?? 0);
        $answer = $params['answer'] ?? '';
        $latencyMs = isset($params['latencyMs']) ? (int)$params['latencyMs'] : null;
        $studentId = (int)($params['studentId'] ?? 1);

        if (!$questionId) {
            return new WP_Error('INVALID_INPUT', 'Question ID is required.', ['status' => 400]);
        }

        $this->assessmentService->saveResponse($sessionId, $questionId, $studentId, $answer, $latencyMs);

        return rest_ensure_response([
            'success' => true,
            'sessionId' => $sessionId,
            'questionId' => $questionId,
        ]);
    }

    public function completeAssessment(WP_REST_Request $request): WP_REST_Response {
        $sessionId = (int)$request->get_param('id');

        // Question definitions matching the 6 core competencies
        $questions = [
            [
                'id' => 1,
                'competency_code' => 'PD',
                'type' => 'scenario',
                'options' => [
                    ['id' => 'A', 'score_value' => 100],
                    ['id' => 'B', 'score_value' => 30],
                    ['id' => 'C', 'score_value' => 60],
                    ['id' => 'D', 'score_value' => 50],
                ],
            ],
            [
                'id' => 2,
                'competency_code' => 'CO',
                'type' => 'scenario',
                'options' => [
                    ['id' => 'A', 'score_value' => 20],
                    ['id' => 'B', 'score_value' => 100],
                    ['id' => 'C', 'score_value' => 40],
                    ['id' => 'D', 'score_value' => 30],
                ],
            ],
            [
                'id' => 3,
                'competency_code' => 'ER',
                'type' => 'scenario',
                'options' => [
                    ['id' => 'A', 'score_value' => 20],
                    ['id' => 'B', 'score_value' => 100],
                    ['id' => 'C', 'score_value' => 30],
                    ['id' => 'D', 'score_value' => 40],
                ],
            ],
            [
                'id' => 4,
                'competency_code' => 'SA',
                'type' => 'frequency',
                'is_reverse' => false,
            ],
            [
                'id' => 5,
                'competency_code' => 'SM',
                'type' => 'frequency',
                'is_reverse' => false,
            ],
            [
                'id' => 6,
                'competency_code' => 'CE',
                'type' => 'frequency',
                'is_reverse' => false,
            ],
        ];

        try {
            $result = $this->assessmentService->completeSession($sessionId, $questions);
            return rest_ensure_response($result);
        } catch (\Exception $e) {
            return new WP_Error('COMPLETION_ERROR', $e->getMessage(), ['status' => 500]);
        }
    }
}
