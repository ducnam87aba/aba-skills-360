<?php

namespace ABA\Core\Repositories;

class AssessmentSessionRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_assessment_sessions';
    }

    public function create(array $data): int {
        global $wpdb;
        $now = current_time('mysql', 1);

        $wpdb->insert($this->table, [
            'assessment_id' => $data['assessment_id'],
            'student_id' => $data['student_id'],
            'school_id' => $data['school_id'] ?? 1,
            'assessment_version' => $data['assessment_version'] ?? '1.0.0',
            'status' => 'in_progress',
            'scoring_strategy' => $data['scoring_strategy'] ?? 'standard',
            'current_step' => 0,
            'total_steps' => $data['total_steps'] ?? 5,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);

        return (int)$wpdb->insert_id;
    }

    public function findById(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public function updateStatus(int $id, string $status, ?float $normalizedScore = null): bool {
        global $wpdb;
        $now = current_time('mysql', 1);

        $data = [
            'status' => $status,
            'updated_at' => $now,
        ];
        $format = ['%s', '%s'];

        if ($status === 'completed') {
            $data['completed_at'] = $now;
            $format[] = '%s';
        }
        if ($normalizedScore !== null) {
            $data['normalized_score'] = $normalizedScore;
            $format[] = '%f';
        }

        $result = $wpdb->update($this->table, $data, ['id' => $id], $format, ['%d']);
        return $result !== false;
    }
}
