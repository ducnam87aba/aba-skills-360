<?php

namespace ABA\Core\Repositories;

class AssessmentResponseRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_assessment_responses';
    }

    public function saveResponse(int $sessionId, int $questionId, int $studentId, string $value, ?int $latencyMs = null): bool {
        global $wpdb;
        $now = current_time('mysql', 1);

        // Upsert on unique key uk_session_question (session_id, question_id)
        $sql = "INSERT INTO {$this->table} 
                (session_id, question_id, student_id, response_value, latency_ms, submitted_at)
                VALUES (%d, %d, %d, %s, %d, %s)
                ON DUPLICATE KEY UPDATE 
                response_value = VALUES(response_value), 
                latency_ms = VALUES(latency_ms), 
                submitted_at = VALUES(submitted_at)";

        $prepared = $wpdb->prepare($sql, $sessionId, $questionId, $studentId, $value, $latencyMs ?? 0, $now);
        $result = $wpdb->query($prepared);
        return $result !== false;
    }

    public function getResponsesBySession(int $sessionId): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table} WHERE session_id = %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $sessionId), ARRAY_A);
        
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int)$row['question_id']] = $row;
        }
        return $keyed;
    }
}
