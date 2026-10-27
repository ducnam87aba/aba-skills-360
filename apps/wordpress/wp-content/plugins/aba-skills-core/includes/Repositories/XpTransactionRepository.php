<?php

namespace ABA\Core\Repositories;

class XpTransactionRepository {
    private string $table;
    private string $studentTable;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_xp_transactions';
        $this->studentTable = $wpdb->prefix . 'aba_students';
    }

    public function recordTransaction(int $studentId, int $amount, string $sourceType, ?string $sourceId = null, ?string $description = null, ?string $idempotencyKey = null): int {
        global $wpdb;
        $now = current_time('mysql', 1);

        // Idempotency: a repeated call with the same key must not double-award
        // (api-contract §31, assessment-spec §81). Returns the existing row id.
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existingId = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE idempotency_key = %s",
                $idempotencyKey
            ));
            if ($existingId !== null) {
                return (int)$existingId;
            }
        }

        // Fetch current total
        $currentXp = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT total_xp FROM {$this->studentTable} WHERE id = %d",
            $studentId
        ));

        $balanceAfter = max(0, $currentXp + $amount);

        // Insert ledger row
        $wpdb->insert($this->table, [
            'student_id' => $studentId,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'description' => $description,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s']);

        $txId = (int)$wpdb->insert_id;

        // Update cached total on student
        $wpdb->update(
            $this->studentTable,
            ['total_xp' => $balanceAfter, 'updated_at' => $now],
            ['id' => $studentId],
            ['%d', '%s'],
            ['%d']
        );

        return $txId;
    }

    /**
     * Ledger total (§12): the sum of every XP transaction is the source of truth.
     * The cached students.total_xp is a performance mirror; this recomputes it
     * from the ledger so nothing is lost if the cache and ledger ever diverge.
     */
    public function getTotalXp(int $studentId): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$this->table} WHERE student_id = %d",
            $studentId
        ));
    }

    /** XP grouped by source_type -> {total, count}, for the achievement breakdown. */
    public function getBreakdownBySource(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT source_type, SUM(amount) AS total, COUNT(*) AS cnt
             FROM {$this->table} WHERE student_id = %d GROUP BY source_type",
            $studentId
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['source_type']] = [
                'total' => (int)$r['total'],
                'count' => (int)$r['cnt'],
            ];
        }
        return $out;
    }

    /** Sum of XP awarded at or after a datetime (GMT), for daily/weekly missions. */
    public function getSumSince(int $studentId, string $sinceDatetime): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$this->table} WHERE student_id = %d AND created_at >= %s",
            $studentId,
            $sinceDatetime
        ));
    }

    /** Number of ledger rows for a source, e.g. completed assessments. */
    public function countBySource(int $studentId, string $sourceType): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE student_id = %d AND source_type = %s",
            $studentId,
            $sourceType
        ));
    }
}
