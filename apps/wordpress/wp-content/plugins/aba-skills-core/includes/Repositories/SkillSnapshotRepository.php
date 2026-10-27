<?php

namespace ABA\Core\Repositories;

class SkillSnapshotRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_skill_snapshots';
    }

    /**
     * Append one immutable snapshot. Snapshots are never updated in place — the
     * longitudinal history (§11) is an append-only ledger, and each row freezes
     * the exact competency scores + quality flags of the run that produced it.
     *
     * $snapshotDate lets a caller (or a test) backdate an entry; it defaults to
     * now so the assessment-completion path is unchanged.
     */
    public function create(int $studentId, int $sessionId, array $scores, array $qualityFlags = [], ?string $snapshotDate = null): int {
        global $wpdb;
        $now = $snapshotDate ?? current_time('mysql', 1);

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
        // id ASC is a stable tiebreaker when two runs share a snapshot_date second,
        // so trend charts always render oldest -> newest deterministically.
        $sql = "SELECT * FROM {$this->table} WHERE student_id = %d ORDER BY snapshot_date ASC, id ASC";
        return $wpdb->get_results($wpdb->prepare($sql, $studentId), ARRAY_A) ?: [];
    }

    /** Most recent snapshot, for the parent overview "latest report" pointer. */
    public function findLatest(int $studentId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE student_id = %d ORDER BY snapshot_date DESC LIMIT 1",
            $studentId
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Snapshots for a whole set of students (PA32 class trend). Pure persistence —
     * grouping/averaging per date is done in the service. The caller passes only
     * ids it is already authorized to read (the learner's own class), and the
     * result is aggregated before it ever reaches the client, so no individual
     * peer's history is exposed (§8).
     *
     * @param int[] $studentIds
     */
    public function getSnapshotsForStudents(array $studentIds): array {
        $ids = array_values(array_filter(array_map('intval', $studentIds), static fn($i) => $i > 0));
        if (!$ids) {
            return [];
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT * FROM {$this->table} WHERE student_id IN ({$placeholders}) ORDER BY snapshot_date ASC, id ASC";
        return $wpdb->get_results($wpdb->prepare($sql, $ids), ARRAY_A) ?: [];
    }
}
