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
}
