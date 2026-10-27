<?php
/**
 * Persistence for score runs (assessment-spec.md §52).
 *
 * A score run is one execution of the scoring pipeline for one submitted
 * session under one scoring version. Rescoring creates a NEW run; historical
 * runs are never overwritten (§51, §114, invariant #3).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class ScoreRunRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_score_runs';
    }

    public function start(int $sessionId, int $studentId, string $assessmentVersion, string $scoringVersion, string $respondentType): int {
        global $wpdb;
        $now = current_time('mysql', 1);

        // Idempotent on (session_id, scoring_version): reuse an existing run.
        $existing = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE session_id = %d AND scoring_version = %s",
            $sessionId,
            $scoringVersion
        ));
        if ($existing) {
            return $existing;
        }

        $wpdb->insert($this->table, [
            'session_id' => $sessionId,
            'student_id' => $studentId,
            'assessment_version' => $assessmentVersion,
            'scoring_version' => $scoringVersion,
            'respondent_type' => $respondentType,
            'status' => 'processing',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        return (int)$wpdb->insert_id;
    }

    public function complete(int $runId, array $scores, array $qualityFlags): bool {
        global $wpdb;
        $now = current_time('mysql', 1);

        return $wpdb->update(
            $this->table,
            [
                'status' => 'completed',
                'scores_json' => wp_json_encode($scores),
                'quality_flags_json' => wp_json_encode($qualityFlags),
                'completed_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $runId],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    public function markFailed(int $runId, string $reason): bool {
        global $wpdb;
        return $wpdb->update(
            $this->table,
            ['status' => 'failed', 'scores_json' => wp_json_encode(['error' => $reason]), 'updated_at' => current_time('mysql', 1)],
            ['id' => $runId],
            ['%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    public function findLatestCompleted(int $sessionId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE session_id = %d AND status = 'completed' ORDER BY completed_at DESC LIMIT 1",
            $sessionId
        ), ARRAY_A);
        return $row ?: null;
    }
}
