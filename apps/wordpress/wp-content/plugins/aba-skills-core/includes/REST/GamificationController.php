<?php
/**
 * Gamification endpoints (GET /aba/v1/gamification/*).
 *
 * Learner-facing and self-scoped: identity always resolves from the login via
 * Authorization::currentStudentId(), never a client-supplied student id, so one
 * learner cannot read or claim another's rewards (AGENTS.md §8, §12).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\REST;

use ABA\Core\Services\GamificationService;
use ABA\Core\Auth\Authorization;
use function ABA\Core\Auth\aba_error;
use WP_REST_Request;
use WP_REST_Response;

class GamificationController {
    private GamificationService $service;

    public function __construct() {
        $this->service = new GamificationService();
    }

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        register_rest_route($namespace, '/gamification/me', [
            'methods' => 'GET',
            'callback' => [$this, 'getMe'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        register_rest_route($namespace, '/gamification/missions', [
            'methods' => 'GET',
            'callback' => [$this, 'getMissions'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        register_rest_route($namespace, '/gamification/badges', [
            'methods' => 'GET',
            'callback' => [$this, 'getBadges'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        // PA18 — longitudinal achievement timeline (earned badges + completed missions).
        register_rest_route($namespace, '/gamification/achievements', [
            'methods' => 'GET',
            'callback' => [$this, 'getAchievements'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        register_rest_route($namespace, '/gamification/missions/(?P<id>\d+)/claim', [
            'methods' => 'POST',
            'callback' => [$this, 'claimMission'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);

        // PA22 — spend a banked streak freeze to bridge one missed day. Self-scoped
        // like every other reward action (identity from login, never a client id).
        register_rest_route($namespace, '/gamification/streak/freeze', [
            'methods' => 'POST',
            'callback' => [$this, 'useStreakFreeze'],
            'permission_callback' => [Authorization::class, 'requireStudent'],
        ]);
    }

    public function getMe(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = $this->resolveStudent();
        if (is_wp_error($studentId)) {
            return $studentId;
        }
        return rest_ensure_response($this->service->buildMe($studentId));
    }

    public function getMissions(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = $this->resolveStudent();
        if (is_wp_error($studentId)) {
            return $studentId;
        }
        return rest_ensure_response($this->service->buildMissions($studentId));
    }

    public function getBadges(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = $this->resolveStudent();
        if (is_wp_error($studentId)) {
            return $studentId;
        }
        return rest_ensure_response($this->service->buildBadges($studentId));
    }

    public function getAchievements(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = $this->resolveStudent();
        if (is_wp_error($studentId)) {
            return $studentId;
        }
        return rest_ensure_response($this->service->buildAchievements($studentId));
    }

    public function claimMission(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = $this->resolveStudent();
        if (is_wp_error($studentId)) {
            return $studentId;
        }
        $missionId = (int)$request->get_param('id');
        $result = $this->service->claimMission($studentId, $missionId);
        if (!$result['success']) {
            $status = $result['status'] === 'not_found' ? 404 : 400;
            $code = $result['status'] === 'not_found' ? 'MISSION_NOT_FOUND' : 'MISSION_NOT_COMPLETE';
            return aba_error($code, 'This mission is not claimable yet.', $status);
        }
        return rest_ensure_response($result);
    }

    public function useStreakFreeze(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = $this->resolveStudent();
        if (is_wp_error($studentId)) {
            return $studentId;
        }
        return rest_ensure_response($this->service->useStreakFreeze($studentId));
    }

    /** Own student only; a login without a linked student cannot use these. */
    private function resolveStudent(): int|\WP_Error {
        $studentId = Authorization::currentStudentId();
        if ($studentId <= 0) {
            return aba_error('ABA_STUDENT_NOT_FOUND', 'No student profile linked to this account.', 404);
        }
        return $studentId;
    }
}
