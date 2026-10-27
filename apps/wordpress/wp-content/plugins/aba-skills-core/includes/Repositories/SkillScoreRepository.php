<?php

namespace ABA\Core\Repositories;

class SkillScoreRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_skill_scores';
    }

    /**
     * Upsert the current projected score for one skill.
     * $score is nullable: insufficient evidence must persist NULL, never 0
     * (assessment-spec.md §45, invariant #1). Source provenance (§53-54) keeps
     * student / teacher / parent responses in separate, non-blended rows.
     */
    public function upsertScore(
        int $studentId,
        string $skillCode,
        string $category,
        ?float $score,
        string $level,
        int $sessionId,
        string $sourceType = 'student',
        ?string $scoringVersion = null,
        string $scoreStatus = 'valid'
    ): bool {
        global $wpdb;
        $now = current_time('mysql', 1);

        // Store NULL explicitly: prepare() coerces a null %s to '' which MySQL
        // would save as 0.00 in a DECIMAL, breaking "missing != 0" (§45).
        $scoreExpr = $score === null ? 'NULL' : '%f';

        $sql = "INSERT INTO {$this->table} 
                (student_id, skill_code, skill_category, score, level, source_type, scoring_version, score_status, last_assessment_session_id, updated_at)
                VALUES (%d, %s, %s, {$scoreExpr}, %s, %s, %s, %s, %d, %s)
                ON DUPLICATE KEY UPDATE 
                score = VALUES(score),
                level = VALUES(level),
                source_type = VALUES(source_type),
                scoring_version = VALUES(scoring_version),
                score_status = VALUES(score_status),
                last_assessment_session_id = VALUES(last_assessment_session_id),
                updated_at = VALUES(updated_at)";

        $args = [$studentId, $skillCode, $category];
        if ($score !== null) {
            $args[] = $score;
        }
        $args[] = $level;
        $args[] = $sourceType;
        $args[] = $scoringVersion;
        $args[] = $scoreStatus;
        $args[] = $sessionId;
        $args[] = $now;

        $prepared = $wpdb->prepare($sql, $args);
        return $wpdb->query($prepared) !== false;
    }

    /**
     * Current rows for a student. $sourceType narrows to a single measurement
     * source (student / teacher / parent, §54); null returns every source.
     */
    public function getScoresByStudent(int $studentId, ?string $sourceType = null): array {
        global $wpdb;
        if ($sourceType === null) {
            $sql = "SELECT * FROM {$this->table} WHERE student_id = %d";
            $prepared = $wpdb->prepare($sql, $studentId);
        } else {
            $sql = "SELECT * FROM {$this->table} WHERE student_id = %d AND source_type = %s";
            $prepared = $wpdb->prepare($sql, $studentId, $sourceType);
        }
        return $wpdb->get_results($prepared, ARRAY_A) ?: [];
    }

    /** Distinct measurement sources that currently have scores for a student. */
    public function getSourcesForStudent(int $studentId): array {
        global $wpdb;
        $sql = "SELECT DISTINCT source_type FROM {$this->table} WHERE student_id = %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $studentId), ARRAY_A) ?: [];
        return array_map(static fn($r) => $r['source_type'], $rows);
    }

    /**
     * Anonymized class aggregate (PA31, §11 comparisons): mean score + sample
     * count per skill across a set of students for one measurement source. NULL
     * (insufficient-evidence) scores are excluded so a missing measure never
     * drags the average (§45). Only the aggregate is returned — never per-student
     * rows — so classmates stay anonymous while the class mean is comparable (§8).
     *
     * @param int[] $studentIds
     * @return array<string, array{avg: float, count: int}> keyed by skill_code
     */
    public function getClassAggregate(array $studentIds, string $sourceType): array {
        $ids = array_values(array_filter(array_map('intval', $studentIds), static fn($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT skill_code, AVG(score) AS avg_score, COUNT(score) AS n
                FROM {$this->table}
                WHERE source_type = %s AND score IS NOT NULL AND student_id IN ({$placeholders})
                GROUP BY skill_code";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge([$sourceType], $ids)), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[$r['skill_code']] = ['avg' => round((float)$r['avg_score'], 1), 'count' => (int)$r['n']];
        }
        return $out;
    }
}
