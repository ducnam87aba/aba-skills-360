<?php
/**
 * Persistence for immutable published assessment versions.
 *
 * assessment-spec.md §19-24: a published version is frozen; historical
 * sessions must resolve their exact question set from the version they used,
 * never from the mutable latest template.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class AssessmentVersionRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_assessment_versions';
    }

    public function findPublished(int $assessmentId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE assessment_id = %d AND status = 'published' ORDER BY published_at DESC LIMIT 1",
            $assessmentId
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Resolve the published version for a learner's grade band (PA29). A single
     * instrument (assessment_id) may ship one frozen published form per band, so
     * the session must freeze the version that matches the learner, not whichever
     * was published last. Falls back to the general latest published version when
     * the band has no dedicated form, so an unseeded band still gets a valid,
     * reproducible instrument rather than a hard failure.
     */
    public function findPublishedForBand(int $assessmentId, string $gradeGroup): ?array {
        if ($gradeGroup !== '') {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE assessment_id = %d AND status = 'published' AND grade_group = %s ORDER BY published_at DESC LIMIT 1",
                $assessmentId,
                $gradeGroup
            ), ARRAY_A);
            if ($row) {
                return $row;
            }
        }
        return $this->findPublished($assessmentId);
    }

    public function findByVersion(int $assessmentId, string $version): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE assessment_id = %d AND version = %s",
            $assessmentId,
            $version
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Decode the frozen definition_json into items + rules. */
    public function getDefinition(array $versionRow): array {
        $decoded = json_decode((string)($versionRow['definition_json'] ?? ''), true);
        if (!is_array($decoded)) {
            $decoded = ['items' => [], 'scoring' => []];
        }
        $decoded += ['items' => [], 'scoring' => []];
        return $decoded;
    }

    /** Insert a version row (used by the seeder and future admin builder). */
    public function upsert(array $data): int {
        global $wpdb;
        $now = current_time('mysql', 1);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE assessment_id = %d AND version = %s",
            $data['assessment_id'],
            $data['version']
        ));

        $row = [
            'assessment_id' => (int)$data['assessment_id'],
            'assessment_code' => $data['assessment_code'],
            'version' => $data['version'],
            'status' => $data['status'] ?? 'draft',
            'grade_group' => $data['grade_group'],
            'respondent_type' => $data['respondent_type'] ?? 'student',
            'scoring_version' => $data['scoring_version'] ?? 'ABA-FREQ-0.1',
            'recall_period' => $data['recall_period'] ?? 'last_2_weeks',
            'question_order' => $data['question_order'] ?? 'fixed',
            'definition_json' => wp_json_encode($data['definition']),
            'updated_at' => $now,
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($existing) {
            $wpdb->update($this->table, $row, ['id' => (int)$existing], $formats, ['%d']);
            return (int)$existing;
        }

        $row['created_at'] = $now;
        if (($data['status'] ?? 'draft') === 'published') {
            $row['published_at'] = $now;
            $formats[] = '%s';
        }
        $wpdb->insert($this->table, $row, $formats);
        return (int)$wpdb->insert_id;
    }
}
