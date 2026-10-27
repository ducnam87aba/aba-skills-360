<?php

namespace ABA\Core\Repositories;

class AssessmentResponseRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_assessment_responses';
    }

    public function saveResponse(int $sessionId, int $questionId, int $studentId, string $value, ?int $latencyMs = null, ?string $missingReason = null): bool {
        global $wpdb;
        $now = current_time('mysql', 1);
        $scoreStatus = $missingReason !== null && $missingReason !== '' ? 'missing' : 'valid';

        // Upsert on unique key uk_session_question (session_id, question_id).
        // Repeated autosave for the same question updates in place (api-contract §29).
        $sql = "INSERT INTO {$this->table} 
                (session_id, question_id, student_id, response_value, missing_reason, score_status, latency_ms, submitted_at)
                VALUES (%d, %d, %d, %s, %s, %s, %d, %s)
                ON DUPLICATE KEY UPDATE 
                response_value = VALUES(response_value), 
                missing_reason = VALUES(missing_reason), 
                score_status = VALUES(score_status), 
                latency_ms = VALUES(latency_ms), 
                submitted_at = VALUES(submitted_at)";

        $prepared = $wpdb->prepare($sql, $sessionId, $questionId, $studentId, $value, $missingReason, $scoreStatus, $latencyMs ?? 0, $now);
        $result = $wpdb->query($prepared);
        return $result !== false;
    }

    /** Count of genuinely answered (non-missing) responses, for progress reporting. */
    public function countAnswered(int $sessionId): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE session_id = %d AND (missing_reason IS NULL OR missing_reason = '')",
            $sessionId
        ));
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
