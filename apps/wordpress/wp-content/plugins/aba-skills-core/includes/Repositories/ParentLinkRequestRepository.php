<?php
/**
 * Persistence for the parent link-request queue (PA42, wp_aba_parent_link_requests).
 *
 * One row per (parent_user_id, student_id) pair (unique key), so the flow is
 * idempotent: re-submitting while pending returns the existing request instead
 * of minting duplicates. The approved parent_student write also lives here —
 * it is the only place that touches that table besides reads, keeping all
 * persistence in the repository layer (AGENTS.md §4).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class ParentLinkRequestRepository {
    private string $table;
    private string $linkTable;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_parent_link_requests';
        $this->linkTable = $wpdb->prefix . 'aba_parent_student';
    }

    public function find(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public function findByParentAndStudent(int $parentUserId, int $studentId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE parent_user_id = %d AND student_id = %d",
            $parentUserId,
            $studentId
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Newest first; the parent sees ONLY their own requests (§8). */
    public function listForParent(int $parentUserId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE parent_user_id = %d ORDER BY created_at DESC, id DESC",
            $parentUserId
        ), ARRAY_A) ?: [];
        return array_map([$this, 'toApiShape'], $rows);
    }

    /**
     * PA43 — the administrator review queue. Unlike the parent's own queue this
     * must join the requesting parent (who is asking) and the target learner
     * (whose record is at stake) so a reviewer can actually make the call. That
     * wider projection is deliberately reachable ONLY behind requireLink-
     * Administrator (platform user) — the same class _aba_is_platform_user gates
     * canViewStudent on — so no learner identity is exposed to a lesser caller
     * (§8). $status is whitelisted; anything else lists every state.
     */
    public function listForAdmin(string $status = 'pending'): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $where = in_array($status, ['pending', 'approved', 'rejected'], true)
            ? 'WHERE r.status = %s'
            : '';
        $sql = "SELECT r.*, u.display_name AS parent_name, u.user_login AS parent_login,
                       s.first_name AS student_first, s.last_name AS student_last
                FROM {$this->table} r
                LEFT JOIN {$p}users u ON u.ID = r.parent_user_id
                LEFT JOIN {$p}aba_students s ON s.id = r.student_id
                {$where}
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT 200";
        if ($where !== '') {
            $sql = $wpdb->prepare($sql, $status);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        return array_map([$this, 'toAdminShape'], $rows);
    }

    public function create(int $parentUserId, int $studentId, string $studentCode, string $relationship): ?int {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert($this->table, [
            'parent_user_id' => $parentUserId,
            'student_id'     => $studentId,
            'student_code'   => $studentCode,
            'relationship'   => $relationship,
            'status'         => 'pending',
            'created_at'     => $now,
            'updated_at'     => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s']);
        return $ok === false ? null : (int) $wpdb->insert_id;
    }

    /** A previously rejected request re-opens as pending (fresh review). */
    public function reopen(int $id, string $relationship): void {
        global $wpdb;
        // Raw query: wpdb::update() formats NULL values as empty strings, and
        // clearing the review trail genuinely requires NULL, not ''.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status = 'pending', relationship = %s, reviewed_by = NULL, reviewed_at = NULL, updated_at = %s WHERE id = %d",
            $relationship,
            gmdate('Y-m-d H:i:s'),
            $id
        ));
    }

    public function markReviewed(int $id, string $status, int $reviewerUserId): void {
        global $wpdb;
        $wpdb->update($this->table, [
            'status'      => $status,
            'reviewed_by' => $reviewerUserId,
            'reviewed_at' => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id], ['%s', '%d', '%s', '%s'], ['%d']);
    }

    /** An active parent_student link already exists => nothing to request. */
    public function hasActiveLink(int $parentUserId, int $studentId): bool {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->linkTable} WHERE parent_user_id = %d AND student_id = %d AND status = 'active'",
            $parentUserId,
            $studentId
        ));
        return $found !== null;
    }

    /**
     * Approval writes the actual access grant: insert the active link, or
     * reactivate a previously unlinked row. Idempotent by (parent, student).
     */
    public function ensureActiveLink(int $parentUserId, int $studentId, string $relationship): void {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$this->linkTable} WHERE parent_user_id = %d AND student_id = %d",
            $parentUserId,
            $studentId
        ), ARRAY_A);
        if ($existing) {
            if ($existing['status'] !== 'active') {
                $wpdb->update($this->linkTable, ['status' => 'active'], ['id' => (int) $existing['id']], ['%s'], ['%d']);
            }
            return;
        }
        $wpdb->insert($this->linkTable, [
            'parent_user_id' => $parentUserId,
            'student_id'     => $studentId,
            'relationship'   => $relationship,
            'is_primary'     => 0,
            'status'         => 'active',
        ], ['%d', '%d', '%s', '%d', '%s']);
    }

    /**
     * API shape for the parent's queue. Deliberately omits student_id and the
     * learner's name: a pending request must not leak another family's student
     * identity before an administrator verifies the relationship (§8). The
     * parent already knows the code they typed.
     */
    public function toApiShape(array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'studentCode' => $row['student_code'] ?? '',
            'relationship' => $row['relationship'] ?? 'guardian',
            'status' => $row['status'] ?? 'pending',
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'] ?? 'now')),
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['updated_at'] ?? 'now')),
        ];
    }

    /**
     * PA43 admin review shape. Carries BOTH sides of the pending grant — the
     * requesting parent and the named learner — because a reviewer cannot judge
     * a claim blind. Reachable only after requireLinkAdministrator, so this is
     * consistent with the platform-user access already granted elsewhere (§8).
     */
    public function toAdminShape(array $row): array {
        $studentName = trim(($row['student_last'] ?? '') . ' ' . ($row['student_first'] ?? ''));
        return [
            'id' => (int) ($row['id'] ?? 0),
            'parentUserId' => (int) ($row['parent_user_id'] ?? 0),
            'parentName' => $row['parent_name'] ?? '',
            'parentLogin' => $row['parent_login'] ?? '',
            'studentCode' => $row['student_code'] ?? '',
            'studentName' => $studentName !== '' ? $studentName : ($row['student_code'] ?? ''),
            'relationship' => $row['relationship'] ?? 'guardian',
            'status' => $row['status'] ?? 'pending',
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'] ?? 'now')),
            'reviewedAt' => !empty($row['reviewed_at']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime($row['reviewed_at'])) : null,
        ];
    }
}
