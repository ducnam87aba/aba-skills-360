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

    /** PA49 — credential lifecycle audit (who issued/reset/changed a login). */
    public const CREDENTIAL_ISSUED = 'credential_issued';
    public const CREDENTIAL_RESET = 'credential_reset';
    public const CREDENTIAL_CHANGED = 'credential_changed';
    /** PA50 — a handout credential aged past the rotation window (forced change). */
    public const CREDENTIAL_EXPIRED = 'credential_expired';
    /** PA52 — a parent (or staff) ended every live login of an account. */
    public const CREDENTIAL_SESSIONS_REVOKED = 'credential_sessions_revoked';
    /** PA56 — one named device was signed out, the other devices left signed in. */
    public const CREDENTIAL_DEVICE_REVOKED = 'credential_device_revoked';
    /** PA57 — a device that was not signed in before now holds the account. */
    public const CREDENTIAL_DEVICE_ADDED = 'credential_device_added';
    /** PA59 — a browser session was opened with the account password. */
    public const CREDENTIAL_SESSION_OPENED = 'credential_session_opened';
    /** PA53 — a credential was locked out after repeated failed sign-ins. */
    public const LOGIN_THROTTLED = 'login_throttled';

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_events';
    }

    /**
     * Append one event. Returns the new row id, or 0 on failure.
     */
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
     * PA49 — newest-first raw rows for one student restricted to a set of event
     * names. The generic events table stays untouched (§6); the caller owns the
     * permission gate, this is pure persistence. The acting user id is returned
     * so the service can resolve *who* acted without joining here.
     *
     * @param list<string> $eventNames
     * @return list<object>{id, user_id, student_id, event_name, payload_json, created_at}
     */
    public function listForStudent(int $studentId, array $eventNames, int $limit = 50): array {
        global $wpdb;
        if ($eventNames === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($eventNames), '%s'));
        $sql = "SELECT id, user_id, student_id, event_name, payload_json, created_at
                FROM {$this->table}
                WHERE student_id = %d AND event_name IN ({$placeholders})
                ORDER BY created_at DESC, id DESC
                LIMIT %d";
        $params = array_merge([$studentId], $eventNames, [$limit]);
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
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
