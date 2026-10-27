<?php

namespace ABA\Core\REST;

use ABA\Core\Services\AssessmentService;
use ABA\Core\Auth\Authorization;
use ABA\Core\Exceptions\ApiException;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

use function ABA\Core\Auth\aba_error;

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
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        // GET /aba/v1/assessment-sessions/{id}  (resume state, api-contract §28)
        register_rest_route($namespace, '/assessment-sessions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getSession'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        // POST /aba/v1/assessment-sessions/{id}/responses
        register_rest_route($namespace, '/assessment-sessions/(?P<id>\d+)/responses', [
            'methods' => 'POST',
            'callback' => [$this, 'recordResponse'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        // POST /aba/v1/assessment-sessions/{id}/complete
        register_rest_route($namespace, '/assessment-sessions/(?P<id>\d+)/complete', [
            'methods' => 'POST',
            'callback' => [$this, 'completeAssessment'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        // GET /aba/v1/assessments/{id}  (public metadata, api-contract §26)
        register_rest_route($namespace, '/assessments/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getAssessment'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        // GET /aba/v1/assessment-sessions/{id}/questions  (next batch, api-contract §30)
        register_rest_route($namespace, '/assessment-sessions/(?P<id>\d+)/questions', [
            'methods' => 'GET',
            'callback' => [$this, 'getSessionQuestions'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);
    }

    public function startAssessment(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $assessmentId = (int)$request->get_param('id');

        // Identity is derived from the authenticated login, never the request body.
        $studentId = Authorization::currentStudentId();
        $scope = Authorization::requireStudentScope($studentId);
        if (is_wp_error($scope)) {
            return $scope;
        }

        try {
            $started = $this->assessmentService->startSession($assessmentId, $studentId);
        } catch (ApiException $e) {
            return $this->toError($e);
        }

        $session = $started['session'];

        return rest_ensure_response([
            'session' => [
                'id' => (int)$session['id'],
                'assessmentId' => (int)$session['assessment_id'],
                'assessmentVersion' => $session['assessment_version'],
                'studentId' => (int)$session['student_id'],
                'status' => $session['status'],
                'startedAt' => $session['started_at'],
                'resume' => $started['resume'],
            ],
            'questionBatch' => [
                // Project only what the respondent needs; never the scoring key (§77).
                'items' => array_map([$this, 'projectQuestion'], $started['items']),
                'cursor' => null,
                'hasMore' => false,
            ],
        ]);
    }

    public function getSession(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $sessionId = (int)$request->get_param('id');
        $studentId = Authorization::currentStudentId();
        if (!Authorization::ownsSession($sessionId, $studentId)) {
            return aba_error('ABA_FORBIDDEN', 'Session does not belong to this student.', 403);
        }

        try {
            $state = $this->assessmentService->getSessionState($sessionId);
        } catch (ApiException $e) {
            return $this->toError($e);
        }

        return rest_ensure_response(['session' => $state]);
    }

    public function recordResponse(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $sessionId = (int)$request->get_param('id');
        $params = $request->get_json_params() ?: [];

        $studentId = Authorization::currentStudentId();
        if (!Authorization::ownsResponse($sessionId, $studentId)) {
            return aba_error('ABA_FORBIDDEN', 'Session does not belong to this student.', 403);
        }

        // Accept a single response or a batch (api-contract §29).
        $items = (isset($params['responses']) && is_array($params['responses']))
            ? $params['responses']
            : [$params];

        $saved = [];
        $progress = null;

        try {
            foreach ($items as $item) {
                $questionId = (int)($item['questionId'] ?? 0);
                if (!$questionId) {
                    throw new ApiException('INVALID_RESPONSE', 'Question ID is required.', 422);
                }
                $latencyMs = isset($item['responseTimeMs'])
                    ? (int)$item['responseTimeMs']
                    : (isset($item['latencyMs']) ? (int)$item['latencyMs'] : null);

                $result = $this->assessmentService->recordResponse(
                    $sessionId,
                    $studentId,
                    $questionId,
                    $item['answer'] ?? null,
                    $latencyMs,
                    $item['missingReason'] ?? null
                );
                $saved[] = ['questionId' => $questionId, 'saved' => true];
                $progress = $result['progress'];
            }
        } catch (ApiException $e) {
            return $this->toError($e);
        }

        return rest_ensure_response([
            'saved' => $saved,
            'progress' => $progress,
        ]);
    }

    public function completeAssessment(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $sessionId = (int)$request->get_param('id');

        $studentId = Authorization::currentStudentId();
        if (!Authorization::ownsSession($sessionId, $studentId)) {
            return aba_error('ABA_FORBIDDEN', 'Session does not belong to this student.', 403);
        }

        try {
            $result = $this->assessmentService->completeSession($sessionId);
        } catch (ApiException $e) {
            return $this->toError($e);
        }

        return rest_ensure_response([
            'session' => [
                'id' => $result['sessionId'],
                'status' => $result['status'],
                'completedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            'result' => [
                'status' => 'ready',
                'skillsProfileAvailable' => true,
                'overallScore' => $result['overallScore'],
                'scoringVersion' => $result['scoringVersion'],
                'scores' => $result['scores'],
                'qualityFlags' => $result['qualityFlags'],
                'idempotent' => $result['idempotent'],
            ],
            'rewards' => [
                'xpAwarded' => $result['xpAwarded'],
            ],
        ]);
    }

    public function getAssessment(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $assessmentId = (int)$request->get_param('id');
        try {
            $meta = $this->assessmentService->getAssessmentMeta($assessmentId);
        } catch (ApiException $e) {
            return $this->toError($e);
        }
        return rest_ensure_response(['assessment' => $meta]);
    }

    public function getSessionQuestions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $sessionId = (int)$request->get_param('id');
        $studentId = Authorization::currentStudentId();
        if (!Authorization::ownsSession($sessionId, $studentId)) {
            return aba_error('ABA_FORBIDDEN', 'Session does not belong to this student.', 403);
        }
        try {
            $items = $this->assessmentService->getSessionItems($sessionId);
        } catch (ApiException $e) {
            return $this->toError($e);
        }
        return rest_ensure_response([
            'items' => array_map([$this, 'projectQuestion'], $items),
            'cursor' => null,
            'hasMore' => false,
        ]);
    }

    /**
     * Project a frozen question item into the client answer shape, stripping the
     * scoring key: reverse flags, rubric score_value and weights stay server-side
     * (assessment-spec.md §77, invariant around leakage).
     */
    private function projectQuestion(array $item): array {
        $type = ($item['type'] ?? 'scenario') === 'frequency' ? 'likert' : 'scenario';

        $options = [];
        foreach (($item['options'] ?? []) as $opt) {
            if (isset($opt['value'])) {
                // Likert: expose the scale value + label only.
                $options[] = ['value' => $opt['value'], 'label' => $opt['label'] ?? null];
            } else {
                // Scenario: expose option id + label, never score_value.
                $options[] = ['id' => $opt['id'], 'label' => $opt['label'] ?? null];
            }
        }

        return [
            'id' => (int)$item['id'],
            'type' => $type,
            'recallPeriod' => $item['recall_period'] ?? 'last_2_weeks',
            'prompt' => $item['prompt'] ?? '',
            'options' => $options,
        ];
    }

    private function toError(ApiException $e): WP_Error {
        return aba_error($e->apiCode(), $e->getMessage(), $e->status(), $e->details());
    }
}
