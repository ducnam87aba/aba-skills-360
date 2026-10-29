<?php

namespace ABA\Core\Repositories;

class StudentRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_students';
    }

    /**
     * Shared projection base (PA27). A student row stores only bare school_id /
     * class_id; the human names live in wp_aba_schools / wp_aba_classes (§6).
     * LEFT JOINing them here populates school_name / class_name that toApiShape
     * already reads — in ONE query, so no caller pays an N+1 lookup. LEFT (not
     * INNER) keeps the student visible even if its school/class row is missing.
     * PA46 adds the linked wp_users login (LEFT: cohort rows always have a user,
     * but a broken link must never hide the learner) so staff and parents can
     * see the account a learner signs in with. PA47 surfaces the one-time-issue
     * marker (aba_credential_issued_at user meta) so the roster can label the
     * action "Cấp tài khoản" vs "Đặt lại mật khẩu" without a probe request.
     */
    private function baseSelect(): string {
        global $wpdb;
        $p = $wpdb->prefix;
        return "SELECT s.*, sch.name AS school_name, cls.name AS class_name, u.user_login AS user_login, cred.meta_value AS credential_issued_at
                FROM {$p}aba_students s
                LEFT JOIN {$p}aba_schools sch ON sch.id = s.school_id
                LEFT JOIN {$p}aba_classes cls ON cls.id = s.class_id
                LEFT JOIN {$p}users u ON u.ID = s.user_id
                LEFT JOIN {$p}usermeta cred ON cred.user_id = s.user_id AND cred.meta_key = 'aba_credential_issued_at' ";
    }

    public function findById(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare($this->baseSelect() . "WHERE s.id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public function findByUserId(int $userId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare($this->baseSelect() . "WHERE s.user_id = %d", $userId), ARRAY_A);
        return $row ?: null;
    }

    /**
     * PA42 — resolve a learner by the human-facing student code printed on
     * their school record (e.g. ABA-2026-101). Exact, case-insensitive match on
     * active rows only; the link-request flow uses this BEFORE any link exists.
     */
    public function findByCode(string $code): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            $this->baseSelect() . "WHERE s.student_code = %s AND s.status = 'active' LIMIT 1",
            $code
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * PA45 — persist edits to an existing learner. Persistence only (§4): the
     * service has already gated WHO may be edited and validated WHAT values are
     * legal; this method just writes the allowed subset. $data keys are column
     * names; unknown keys are ignored so a stray field can never widen the write.
     * Returns true on success.
     */
    public function update(int $id, array $data): bool {
        global $wpdb;
        $allowed = [
            'first_name', 'last_name', 'grade_level', 'grade_group',
            'school_id', 'class_id', 'status',
        ];
        $write = [];
        $formats = [];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $write[$column] = $data[$column];
            $formats[] = in_array($column, ['first_name', 'last_name', 'grade_group', 'status'], true) ? '%s' : '%d';
        }
        if (empty($write)) {
            return false;
        }
        return $wpdb->update($this->table, $write, ['id' => $id], $formats, ['%d']) !== false;
    }

    /** True when a code is already taken (active OR archived) — codes are UNIQUE. */
    public function codeExists(string $code): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE student_code = %s LIMIT 1",
            $code
        )) !== null;
    }

    /**
     * PA44 — persist one learner row. Persistence only (§4): the caller (a
     * service) has already resolved the scoped school_id/class_id and created the
     * linked wp_user, so nothing here trusts a client value. Returns the new
     * student id, or null on failure. created_at/updated_at use the table defaults.
     */
    public function insert(array $data): ?int {
        global $wpdb;
        $ok = $wpdb->insert($this->table, [
            'user_id'      => (int) $data['user_id'],
            'school_id'    => (int) $data['school_id'],
            'class_id'     => isset($data['class_id']) ? (int) $data['class_id'] : null,
            'student_code' => (string) $data['student_code'],
            'first_name'   => (string) $data['first_name'],
            'last_name'    => (string) $data['last_name'],
            'grade_level'  => (int) $data['grade_level'],
            'grade_group'  => (string) $data['grade_group'],
            'status'       => 'active',
        ], ['%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']);
        return $ok === false ? null : (int) $wpdb->insert_id;
    }

    public function getChildrenForParent(int $parentUserId): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $sql = $this->baseSelect()
            . "INNER JOIN {$p}aba_parent_student ps ON s.id = ps.student_id
               WHERE ps.parent_user_id = %d AND ps.status = 'active'
               ORDER BY ps.is_primary DESC, s.id ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $parentUserId), ARRAY_A) ?: [];
        return array_map([$this, 'toApiShape'], $rows);
    }

    /**
     * The roster of one school (PA23). Scoped by school_id — the caller's own
     * assignment — never an arbitrary id from the client (§8). Returns [] for an
     * unassigned caller (school_id 0), which is the safe default. PA45: staff
     * screens pass $includeInactive so archived learners stay visible for
     * reactivation; every older caller keeps the active-only view.
     */
    public function listBySchool(int $schoolId, bool $includeInactive = false): array {
        if ($schoolId <= 0) {
            return [];
        }
        global $wpdb;
        $statusClause = $includeInactive ? '' : " AND s.status = 'active'";
        $rows = $wpdb->get_results($wpdb->prepare(
            $this->baseSelect() . "WHERE s.school_id = %d" . $statusClause . " ORDER BY s.id ASC",
            $schoolId
        ), ARRAY_A) ?: [];
        return array_map([$this, 'toApiShape'], $rows);
    }

    /**
     * The roster of one class (PA24). Scoped by class_id — the caller's own
     * assignment — never an arbitrary id from the client (§8). Returns [] for an
     * unassigned caller (class_id 0), which is the safe default. PA45 keeps the
     * same $includeInactive flag as listBySchool.
     */
    public function listByClass(int $classId, bool $includeInactive = false): array {
        if ($classId <= 0) {
            return [];
        }
        global $wpdb;
        $statusClause = $includeInactive ? '' : " AND s.status = 'active'";
        $rows = $wpdb->get_results($wpdb->prepare(
            $this->baseSelect() . "WHERE s.class_id = %d" . $statusClause . " ORDER BY s.id ASC",
            $classId
        ), ARRAY_A) ?: [];
        return array_map([$this, 'toApiShape'], $rows);
    }

    /**
     * Map a raw wp_aba_students row into the StudentProfile API contract shape.
     * Null-safe so a freshly seeded row never fatals the endpoint.
     */
    public function toApiShape(array $row): array {
        $fullName = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? ''));
        if ($fullName === '') {
            $fullName = $row['student_code'] ?? '';
        }
        $totalXp = (int)($row['total_xp'] ?? 0);

        return [
            'id' => (int)($row['id'] ?? 0),
            'userId' => (int)($row['user_id'] ?? 0),
            'studentCode' => $row['student_code'] ?? '',
            'firstName' => $row['first_name'] ?? '',
            'lastName' => $row['last_name'] ?? '',
            'fullName' => $fullName,
            'gradeLevel' => (int)($row['grade_level'] ?? 0),
            'gradeGroup' => $row['grade_group'] ?? '4_6',
            'schoolId' => (int)($row['school_id'] ?? 0),
            'schoolName' => $row['school_name'] ?? null,
            'classId' => isset($row['class_id']) ? (int)$row['class_id'] : null,
            'className' => $row['class_name'] ?? null,
            'avatarUrl' => $row['avatar_url'] ?? null,
            'totalXp' => $totalXp,
            'currentStreak' => (int)($row['current_streak'] ?? 0),
            'longestStreak' => (int)($row['longest_streak'] ?? 0),
            'level' => (int)($row['level'] ?? max(1, intdiv($totalXp, 200) + 1)),
            'lastActiveAt' => $row['last_active_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            // PA45 — the roster screen shows archived learners for reactivation,
            // so status is part of the staff-facing shape.
            'status' => $row['status'] ?? 'active',
            // PA46 — the login a learner signs in with once staff provision it.
            'username' => $row['user_login'] ?? null,
            // PA47 — set once a password has been issued; drives the reset UI.
            'credentialIssuedAt' => !empty($row['credential_issued_at']) ? (string) $row['credential_issued_at'] : null,
        ];
    }
}
