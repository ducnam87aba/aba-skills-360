<?php
/**
 * Domain event ledger (wp_aba_events). Append-only ground truth for things that
 * happen *to* a student over time — report delivery, read receipts, ... — rather
 * than a mutable status column that silently loses history. The current state is
 * always *derived* by reading the log back (AGENTS.md §12: the ledger is the
 * source of truth; §11: longitudinal). Rows carry an acting user_id so a receipt
 * records *who* read it, not just that it was read.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class EventRepository {
    /** Event names owned by the report delivery flow (PA21). */
    public const REPORT_GENERATED = 'report_generated';
    public const REPORT_READ = 'report_read';

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_events';
    }

    /** Append one event. Returns the new row id, or 0 on failure. */
    public function record(?int $userId, ?int $studentId, string $eventName, array $payload): int {
        global $wpdb;
        $inserted = $wpdb->insert(
            $this->table,
            [
                'user_id'      => $userId,
                'student_id'   => $studentId,
                'event_name'   => $eventName,
                'payload_json' => wp_json_encode($payload),
                'created_at'   => current_time('mysql', 1),
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );
        return $inserted === false ? 0 : (int)$wpdb->insert_id;
    }

    /**
     * Read-receipt timestamps for one report, oldest first. Filtered by the
     * reportId carried in the JSON payload so the generic events table stays
     * unmodified (§6: reuse the transactional table, don't special-case schema).
     *
     * @return list<string> created_at values (GMT datetime).
     */
    public function readsFor(int $studentId, int $reportId): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT created_at FROM {$this->table}
             WHERE student_id = %d AND event_name = %s
               AND JSON_EXTRACT(payload_json, '$.reportId') = %d
             ORDER BY created_at ASC, id ASC",
            $studentId,
            self::REPORT_READ,
            $reportId
        )) ?: [];
    }
}
