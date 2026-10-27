<?php

namespace ABA\Core\Repositories;

class LessonProgressRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_lesson_progress';
    }

    public function recordProgress(int $studentId, string $nodeId, string $status = 'completed', ?float $score = 100.0, int $xpAwarded = 40): bool {
        global $wpdb;
        $now = current_time('mysql', 1);

        $sql = "INSERT INTO {$this->table}
                (student_id, node_id, status, score, xp_awarded, completed_at, updated_at)
                VALUES (%d, %s, %s, %f, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                score = VALUES(score),
                xp_awarded = VALUES(xp_awarded),
                completed_at = VALUES(completed_at),
                updated_at = VALUES(updated_at)";

        $prepared = $wpdb->prepare($sql, $studentId, $nodeId, $status, $score, $xpAwarded, $now, $now);
        return $wpdb->query($prepared) !== false;
    }

    public function getCompletedNodeIds(int $studentId): array {
        global $wpdb;
        $sql = "SELECT node_id FROM {$this->table} WHERE student_id = %d AND status = 'completed'";
        $results = $wpdb->get_col($wpdb->prepare($sql, $studentId));
        return $results ?: [];
    }

    /** Count of lessons completed since a datetime, for parent weekly summary. */
    public function countCompletedSince(int $studentId, string $sinceDatetime): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE student_id = %d AND status = 'completed' AND completed_at >= %s",
            $studentId,
            $sinceDatetime
        ));
    }

    /** Count of lessons completed on a specific calendar date (Y-m-d, GMT). */
    public function countCompletedOnDate(int $studentId, string $date): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE student_id = %d AND status = 'completed' AND DATE(completed_at) = %s",
            $studentId,
            $date
        ));
    }

    /**
     * Most recent completed lessons (newest-first), for the parent activity feed.
     * Read-only over the ground-truth progress table — the timestamps and XP come
     * straight from the recorded rows, never a fabricated counter (§12).
     * @return list<array{node_id:string,score:?float,xp_awarded:int,completed_at:string}>
     */
    public function getRecentCompleted(int $studentId, int $limit = 10): array {
        global $wpdb;
        $limit = max(1, min(50, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT node_id, score, xp_awarded, completed_at FROM {$this->table}
             WHERE student_id = %d AND status = 'completed'
             ORDER BY completed_at DESC
             LIMIT {$limit}",
            $studentId
        ), ARRAY_A);
        return $rows ?: [];
    }
}
