<?php
/**
 * Mission-progress persistence (wp_aba_mission_progress). One row per
 * (student_id, mission_id, period_date) records the durable outcome of a daily
 * or weekly mission for a given period, so completed/claimed missions remain a
 * queryable longitudinal history instead of an ephemeral projection (AGENTS.md
 * §11). Upsert is idempotent on the unique period key: re-observing the same
 * period only refreshes progress and the completion stamp, never duplicating.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class MissionProgressRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_mission_progress';
    }

    /**
     * Upsert one mission's state for a period. When completed and no $completedAt
     * is supplied, the current GMT time is stamped only the first time the row
     * reaches completion (a later refresh never rewrites the original moment).
     */
    public function upsert(
        int $studentId,
        int $missionId,
        string $periodDate,
        int $progress,
        int $target,
        bool $isCompleted,
        ?string $completedAt = null
    ): bool {
        global $wpdb;

        $completedFlag = $isCompleted ? 1 : 0;
        $completed = $isCompleted
            ? ($completedAt ?? $wpdb->get_var($wpdb->prepare(
                "SELECT completed_at FROM {$this->table} WHERE student_id = %d AND mission_id = %d AND period_date = %s",
                $studentId,
                $missionId,
                $periodDate
            )) ?: current_time('mysql', 1))
            : null;

        $sql = "INSERT INTO {$this->table}
                    (student_id, mission_id, period_date, current_progress, target_progress, is_completed, completed_at)
                VALUES (%d, %d, %s, %d, %d, %d, %s)
                ON DUPLICATE KEY UPDATE
                    current_progress = VALUES(current_progress),
                    target_progress = VALUES(target_progress),
                    is_completed = VALUES(is_completed),
                    completed_at = COALESCE(completed_at, VALUES(completed_at))";
        return $wpdb->query($wpdb->prepare(
            $sql,
            $studentId,
            $missionId,
            $periodDate,
            $progress,
            $target,
            $completedFlag,
            $completed
        )) !== false;
    }

    /** Count of missions completed since a datetime (parent weekly summary). */
    public function countCompletedSince(int $studentId, string $sinceDatetime): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE student_id = %d AND is_completed = 1 AND completed_at >= %s",
            $studentId,
            $sinceDatetime
        ));
    }

    /** @return array<int,array> this learner's mission history rows, newest period first. */
    public function getHistory(int $studentId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT mission_id, period_date, current_progress, target_progress, is_completed, completed_at
             FROM {$this->table} WHERE student_id = %d ORDER BY period_date DESC, mission_id ASC",
            $studentId
        ), ARRAY_A) ?: [];
    }

    /** True when this mission was completed for the given period. */
    public function isCompleted(int $studentId, int $missionId, string $periodDate): bool {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table}
             WHERE student_id = %d AND mission_id = %d AND period_date = %s AND is_completed = 1",
            $studentId,
            $missionId,
            $periodDate
        ));
        return $found !== null;
    }
}
