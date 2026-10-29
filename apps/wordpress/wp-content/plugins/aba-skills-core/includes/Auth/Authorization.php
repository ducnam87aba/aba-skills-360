<?php
/**
 * ABA Authorization layer.
 *
 * Enforces architecture invariants:
 *   - AGENTS.md §8 / architecture.md §30, §47 (#10): authorization is server-side.
 *   - architecture.md §3: use capabilities rather than hardcoded role names.
 *   - architecture.md §31: multi-school / resource scope is considered.
 *
 * Identity model (initial):
 *   A WP user is linked to a domain record via user meta:
 *     - aba_student_id : int  → the wp_aba_students.id this user *is*
 *     - aba_parent     : bool → the user reads their linked children only
 *   Admin / editor / teacher capabilities bypass resource ownership checks.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/** Custom primitive capabilities for the ABA platform. */
const ABA_CAP_STUDENT = 'aba_assessment_take';
const ABA_CAP_PARENT  = 'aba_report_read';
// School-scoped staff (teacher) may read the roster of their own school only.
// Capability-driven on purpose — never a hardcoded role-name check (§3, §8).
const ABA_CAP_ROSTER  = 'aba_manage_students';

/**
 * Capabilities granted to each core WordPress role. Keep additive and minimal
 * (least privilege). Roles can later be replaced by explicit ABA roles.
 */
function aba_role_capability_map(): array {
    return [
        'administrator' => [ABA_CAP_STUDENT, ABA_CAP_PARENT, ABA_CAP_ROSTER],
        'editor'        => [ABA_CAP_STUDENT, ABA_CAP_PARENT, ABA_CAP_ROSTER],
        'author'        => [ABA_CAP_STUDENT, ABA_CAP_PARENT],
        'teacher'       => [ABA_CAP_STUDENT, ABA_CAP_PARENT, ABA_CAP_ROSTER],
        'parent'        => [ABA_CAP_PARENT],
        'student'       => [ABA_CAP_STUDENT],
        'subscriber'    => [ABA_CAP_STUDENT],
    ];
}

/**
 * Grant ABA capabilities to the roles that should hold them. Idempotent —
 * safe to run on every activation.
 */
function aba_grant_role_capabilities(): void {
    foreach (aba_role_capability_map() as $role_name => $caps) {
        $role = \get_role($role_name);
        if (!$role) {
            continue;
        }
        foreach ($caps as $cap) {
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }
    }
}

/** Consistent error body per architecture.md §37 / api-contract.md §6 (Error Format). */
function aba_error(string $code, string $message, int $status, array $details = []): \WP_Error {
    return new \WP_Error($code, $message, ['status' => $status, 'code' => $code, 'details' => $details]);
}

/**
 * Map a WP user (plus its linked student record) into the shared /me DTO used
 * by auth/login, /me and /students/me so the contract shape stays consistent.
 */
function aba_user_dto(\WP_User $user): array {
    $student = (new \ABA\Core\Repositories\StudentRepository())->findByUserId($user->ID);
    $roles = $user->roles ?: [];
    return [
        'id' => (int)$user->ID,
        'username' => $user->user_login,
        'email' => $user->user_email,
        'displayName' => $user->display_name,
        'roles' => $roles,
        'studentId' => $student ? (int)$student['id'] : null,
        'schoolId' => $student ? (int)($student['school_id'] ?? 0) : null,
        'parentId' => in_array('aba_parent', $roles, true) || get_user_meta($user->ID, 'aba_parent', true) ? (int)$user->ID : null,
        // PA48 — a staff-generated handout password must be replaced by the
        // learner's own secret on first sign-in; the front end routes on this.
        'mustChangePassword' => get_user_meta($user->ID, 'aba_must_change_password', true) === '1',
    ];
}

/** True when the user holds a platform-level capability that bypasses ownership. */
function _aba_is_platform_user(int $user_id): bool {
    return user_can($user_id, 'manage_options')
        || user_can($user_id, 'edit_others_posts')
        || user_can($user_id, 'aba_manage_all');
}

class Authorization {

    /**
     * Resolve the student_id for a WP user.
     * The canonical link is wp_aba_students.user_id; the aba_student_id user
     * meta is only a cache. Read the cache first, fall back to the table, then
     * backfill so later lookups stay cheap (avoids meta/table divergence).
     */
    public static function studentIdForUser(int $user_id): int {
        if ($user_id === 0) {
            return 0;
        }
        $meta = (int) get_user_meta($user_id, 'aba_student_id', true);
        if ($meta > 0) {
            return $meta;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'aba_students';
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d",
            $user_id
        ));
        if ($found > 0) {
            update_user_meta($user_id, 'aba_student_id', $found);
        }
        return $found;
    }

    /** Resolve the student_id the current login maps to (0 when not linked). */
    public static function currentStudentId(): int {
        return self::studentIdForUser(\get_current_user_id());
    }

    /**
     * The acting user must be able to read/write the given student's data.
     * Returns true for own linked student, or platform user.
     */
    public static function canViewStudent(int $student_id): bool {
        $user_id = \get_current_user_id();
        if ($user_id === 0) {
            return false;
        }
        if (_aba_is_platform_user($user_id)) {
            return true;
        }
        if (self::studentIdForUser($user_id) === $student_id && $student_id > 0) {
            return true;
        }
        // School-scoped staff (e.g. a teacher) may read students within their own
        // school only — capability + matching school, never a bare role name or a
        // client-supplied id (§3, §8). No assigned school => no roster access.
        if (user_can($user_id, ABA_CAP_ROSTER)) {
            // PA24 — narrowest scope wins. A class-assigned staff reads only their
            // own class; otherwise the caller falls back to the PA23 whole-school
            // scope. Class ids are globally unique, so a class match already implies
            // the same school — an out-of-class student cannot be reached by id.
            $scoped = self::assignedClassId($user_id) > 0
                ? self::sameClass($user_id, $student_id)
                : self::sameSchool($user_id, $student_id);
            if ($scoped) {
                return true;
            }
        }
        return self::isParentOf($user_id, $student_id);
    }

    /** The acting user is allowed to *take* an assessment for this student. */
    public static function canTakeAssessment(int $student_id): bool {
        $user_id = \get_current_user_id();
        if ($user_id === 0 || $student_id <= 0) {
            return false;
        }
        if (_aba_is_platform_user($user_id)) {
            return true;
        }
        return self::studentIdForUser($user_id) === $student_id
            && user_can($user_id, ABA_CAP_STUDENT);
    }

    /** The student's owning school (0 when the student does not exist). */
    public static function studentSchoolId(int $student_id): int {
        if ($student_id <= 0) {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'aba_students';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT school_id FROM {$table} WHERE id = %d",
            $student_id
        ));
    }

    /** The school a staff user is scoped to (0 when unassigned). */
    public static function assignedSchoolId(int $user_id): int {
        return (int) get_user_meta($user_id, 'aba_school_id', true);
    }

    /** Staff and student share a school; unassigned staff never match (§8). */
    public static function sameSchool(int $user_id, int $student_id): bool {
        $school = self::assignedSchoolId($user_id);
        return $school > 0 && self::studentSchoolId($student_id) === $school;
    }

    /** The class a staff user is scoped to (0 when not class-assigned, PA24). */
    public static function assignedClassId(int $user_id): int {
        return (int) get_user_meta($user_id, 'aba_class_id', true);
    }

    /** The student's class (0 when the row is absent or unassigned). */
    public static function studentClassId(int $student_id): int {
        if ($student_id <= 0) {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'aba_students';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT class_id FROM {$table} WHERE id = %d",
            $student_id
        ));
    }

    /** Staff and student share a class; unassigned staff never match (§8). */
    public static function sameClass(int $user_id, int $student_id): bool {
        $class = self::assignedClassId($user_id);
        return $class > 0 && self::studentClassId($student_id) === $class;
    }

    /** Parent/child link stored in wp_aba_parent_student with active status. */
    public static function isParentOf(int $parent_user_id, int $student_id): bool {
        if ($parent_user_id === 0 || $student_id <= 0) {
            return false;
        }
        if (!user_can($parent_user_id, ABA_CAP_PARENT) && !_aba_is_platform_user($parent_user_id)) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'aba_parent_student';
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT student_id FROM {$table} WHERE parent_user_id = %d AND student_id = %d AND status = 'active'",
            $parent_user_id,
            $student_id
        ));
        return $found !== null;
    }

    /** Session ownership: caller may only touch their own session. */
    public static function ownsSession(int $session_id, int $student_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'aba_assessment_sessions';
        $owner = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT student_id FROM {$table} WHERE id = %d",
            $session_id
        ));
        if ($owner === 0) {
            return false;
        }
        $user_id = \get_current_user_id();
        if (_aba_is_platform_user($user_id)) {
            return true;
        }
        return $owner === $student_id;
    }

    /** Response ownership: caller may only write into their own session. */
    public static function ownsResponse(int $session_id, int $student_id): bool {
        return self::ownsSession($session_id, $student_id);
    }

    // ---- Permission callbacks (used by register_rest_route) ---- //

    public static function requireStudent(): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
        }
        if (!current_user_can(ABA_CAP_STUDENT) && !_aba_is_platform_user(get_current_user_id())) {
            return aba_error('ABA_FORBIDDEN', 'Missing assessment capability.', 403);
        }
        return true;
    }

    public static function requireStudentScope(int $student_id): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
        }
        if (!self::canTakeAssessment($student_id) && !_aba_is_platform_user(get_current_user_id())) {
            return aba_error('ABA_FORBIDDEN', 'Cannot act for this student.', 403);
        }
        return true;
    }

    public static function requireParent(): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
        }
        if (!current_user_can(ABA_CAP_PARENT) && !_aba_is_platform_user(get_current_user_id())) {
            return aba_error('ABA_FORBIDDEN', 'Missing parent capability.', 403);
        }
        return true;
    }

    public static function requireViewStudent(int $student_id): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
        }
        if (!self::canViewStudent($student_id)) {
            return aba_error('ABA_FORBIDDEN', 'No access to this student.', 403);
        }
        return true;
    }

    /** Caller is school staff entitled to a roster view (§8 school scope). */
    public static function requireRoster(): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
        }
        if (!current_user_can(ABA_CAP_ROSTER) && !_aba_is_platform_user(get_current_user_id())) {
            return aba_error('ABA_FORBIDDEN', 'Missing roster capability.', 403);
        }
        return true;
    }

    /**
     * PA42 — only a platform administrator may approve/reject a parent link
     * request. Deliberately stricter than the roster cap: a class teacher can
     * read their roster but must not be able to mint parent access to any
     * learner (§8 — a parent-child link is an access grant, never self-service
     * and never school-scoped staff work). A parent can also never review their
     * own request: requireParent users do not hold manage_options.
     */
    public static function requireLinkAdministrator(): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
        }
        if (!_aba_is_platform_user(get_current_user_id())) {
            return aba_error('ABA_FORBIDDEN', 'Only a platform administrator can review link requests.', 403);
        }
        return true;
    }
}
