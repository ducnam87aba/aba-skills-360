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

    public function recordTransaction(int $studentId, int $amount, string $sourceType, ?string $sourceId = null, ?string $description = null): int {
        global $wpdb;
        $now = current_time('mysql', 1);

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
            'created_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%s', '%s', '%s']);

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
}
