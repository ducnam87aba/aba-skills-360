<?php

namespace ABA\Core\Repositories;

class SkillSnapshotRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_skill_snapshots';
    }

    public function create(int $studentId, int $sessionId, array $scores, array $qualityFlags = []): int {
        global $wpdb;
        $now = current_time('mysql', 1);

        $payload = [
            'scores' => $scores,
            'qualityFlags' => $qualityFlags,
        ];

        $wpdb->insert($this->table, [
            'student_id' => $studentId,
            'assessment_session_id' => $sessionId,
            'scores_json' => wp_json_encode($payload),
            'snapshot_date' => $now,
        ], ['%d', '%d', '%s', '%s']);

        return (int)$wpdb->insert_id;
    }

    public function getHistoryByStudent(int $studentId): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table} WHERE student_id = %d ORDER BY snapshot_date ASC";
        return $wpdb->get_results($wpdb->prepare($sql, $studentId), ARRAY_A) ?: [];
    }
}
