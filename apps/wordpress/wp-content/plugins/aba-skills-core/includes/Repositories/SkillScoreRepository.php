<?php

namespace ABA\Core\Repositories;

class SkillScoreRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_skill_scores';
    }

    public function upsertScore(int $studentId, string $skillCode, string $category, float $score, string $level, int $sessionId): bool {
        global $wpdb;
        $now = current_time('mysql', 1);

        $sql = "INSERT INTO {$this->table} 
                (student_id, skill_code, skill_category, score, level, last_assessment_session_id, updated_at)
                VALUES (%d, %s, %s, %f, %s, %d, %s)
                ON DUPLICATE KEY UPDATE 
                score = VALUES(score),
                level = VALUES(level),
                last_assessment_session_id = VALUES(last_assessment_session_id),
                updated_at = VALUES(updated_at)";

        $prepared = $wpdb->prepare($sql, $studentId, $skillCode, $category, $score, $level, $sessionId, $now);
        return $wpdb->query($prepared) !== false;
    }

    public function getScoresByStudent(int $studentId): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table} WHERE student_id = %d";
        return $wpdb->get_results($wpdb->prepare($sql, $studentId), ARRAY_A) ?: [];
    }
}
