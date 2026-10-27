<?php
/**
 * Streak-freeze / power-up persistence (wp_aba_power_ups). An append-only ledger
 * mirroring the XP ledger (AGENTS.md §12): every grant is a +delta row and every
 * spend a -delta row, and the spendable balance is always SUM(delta) — never a
 * mutable counter that can drift. The unique (student_id, kind, reason) key makes
 * each entry idempotent, so a repeated milestone read or a double-tapped spend can
 * neither mint nor burn the same credit twice.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class PowerUpRepository {
    /** The only power-up kind wired so far: a bankable streak-freeze credit. */
    public const FREEZE = 'streak_freeze';

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_power_ups';
    }

    /**
     * Append one ledger entry. Idempotent on (student_id, kind, reason): a repeat
     * of the same reason is a no-op, so the caller never double-counts. Pass a
     * positive $delta to grant, negative to spend.
     */
    public function grant(int $studentId, string $kind, int $delta, string $reason): bool {
        global $wpdb;
        $now = current_time('mysql', 1);
        $sql = "INSERT INTO {$this->table} (student_id, kind, delta, reason, created_at)
                VALUES (%d, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE id = id";
        return $wpdb->query($wpdb->prepare($sql, $studentId, $kind, $delta, $reason, $now)) !== false;
    }

    /** Current spendable balance: the signed sum of the ledger for this kind. */
    public function balance(int $studentId, string $kind): int {
        global $wpdb;
        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$this->table} WHERE student_id = %d AND kind = %s",
            $studentId,
            $kind
        ));
        return (int) $sum;
    }

    /** @return array<int, array<string,mixed>> the full ledger for this kind, oldest first. */
    public function ledger(int $studentId, string $kind): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE student_id = %d AND kind = %s ORDER BY id ASC",
            $studentId,
            $kind
        ), ARRAY_A) ?: [];
    }
}
