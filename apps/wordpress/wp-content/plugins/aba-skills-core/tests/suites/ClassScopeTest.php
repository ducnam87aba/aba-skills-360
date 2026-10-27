<?php
/**
 * Suite: Class-level scope (PA24, AGENTS.md §8) — the fine-grained sibling of PA23.
 *
 * A staff user's scope is the NARROWEST assignment they hold: a class-assigned
 * teacher reads only their own class, while a school-assigned staff keeps the
 * PA23 whole-school view. Cross-class and cross-school reads are refused, and the
 * broad /school/roster view is denied outright to a class-scoped caller. Proven
 * through the real REST stack, never a client-supplied id.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Auth\Authorization;

return function (AbaTestKit $kit): void {
    $kit->section('ClassScopeTest — class-level isolation (§8, PA24)');

    // School 1 has two classes (10 and 11); school 2 has one class (20).
    $A1 = $kit->makeStudent('4_6', 1, 10);
    $A2 = $kit->makeStudent('4_6', 1, 10); // same class as A1
    $A3 = $kit->makeStudent('4_6', 1, 11); // same school, different class
    $B1 = $kit->makeStudent('4_6', 2, 20); // different school

    // Principals: a class-10 teacher (class scope only), a class-11 teacher, and
    // a school-1 admin (school scope). Class staff carry no aba_school_id.
    $classTeacher = $kit->makeStaff(0, true, 10);
    $otherTeacher = $kit->makeStaff(0, true, 11);
    $schoolAdmin  = $kit->makeStaff(1, true, 0);

    $idsOf = static function (array $students): array {
        return array_map(static fn($s) => (int) $s['id'], $students);
    };

    try {
        // ---- Service layer: narrowest assignment decides access ----------------
        wp_set_current_user($classTeacher);
        $kit->check('class teacher reads their own student', Authorization::canViewStudent($A1));
        $kit->check('class teacher reads a classmate', Authorization::canViewStudent($A2));
        $kit->check('class teacher DENIED a different-class student in the same school', !Authorization::canViewStudent($A3));
        $kit->check('class teacher DENIED a cross-school student', !Authorization::canViewStudent($B1));

        wp_set_current_user($otherTeacher);
        $kit->check('class-11 teacher reads their own class student', Authorization::canViewStudent($A3));

        wp_set_current_user($schoolAdmin);
        $kit->check('school admin reads any student in the school', Authorization::canViewStudent($A1));
        $kit->check('school admin reads the other class too (PA23 intact)', Authorization::canViewStudent($A3));
        $kit->check('school admin DENIED a cross-school student (§8)', !Authorization::canViewStudent($B1));

        $kit->checkSame('student class resolves from the table', 10, Authorization::studentClassId($A1));
        $kit->checkSame('teacher class resolves from the assignment meta', 10, Authorization::assignedClassId($classTeacher));
        $kit->check('sameClass matches within the class', Authorization::sameClass($classTeacher, $A1));
        $kit->check('sameClass rejects another class', !Authorization::sameClass($classTeacher, $A3));

        // ---- REST layer: the class roster is scoped to the caller's class -------
        wp_set_current_user($classTeacher);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/class/roster'));
        $kit->checkSame('GET /class/roster => 200 for a class teacher', 200, $resp->get_status());
        $roster = $resp->get_data();
        $kit->checkSame('class roster is scoped to the caller class', 10, (int) ($roster['classId'] ?? 0));
        $cIds = $idsOf($roster['students'] ?? []);
        $kit->check('class roster includes A1', in_array($A1, $cIds, true));
        $kit->check('class roster includes classmate A2', in_array($A2, $cIds, true));
        $kit->check('class roster EXCLUDES the other-class student A3', !in_array($A3, $cIds, true));
        $kit->check('class roster EXCLUDES the cross-school student B1', !in_array($B1, $cIds, true));

        // A class-scoped caller must not be handed the broader school view.
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/roster'));
        $kit->checkSame('class teacher hitting /school/roster => 403', 403, $resp->get_status());

        // Single-student reads still route through the class-aware gate.
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/students/' . $A1));
        $kit->checkSame('class teacher reads an own-class student => 200', 200, $resp->get_status());
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/students/' . $A3));
        $kit->checkSame('class teacher reads a different-class student => 403', 403, $resp->get_status());

        // ---- School admin keeps the whole-school roster -------------------------
        wp_set_current_user($schoolAdmin);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/roster'));
        $kit->checkSame('GET /school/roster => 200 for school admin', 200, $resp->get_status());
        $sIds = $idsOf($resp->get_data()['students'] ?? []);
        $kit->check('school roster includes class 10 A1', in_array($A1, $sIds, true));
        $kit->check('school roster includes class 11 A3', in_array($A3, $sIds, true));
        $kit->check('school roster EXCLUDES cross-school B1', !in_array($B1, $sIds, true));

        // A school-only caller has no class assignment => empty class roster.
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/class/roster'));
        $kit->checkSame('unassigned caller class roster => 200', 200, $resp->get_status());
        $kit->checkSame('unassigned caller sees no class', 0, (int) ($resp->get_data()['classId'] ?? -1));

        // ---- Unauthenticated ----------------------------------------------------
        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/class/roster'));
        $kit->checkSame('class roster unauthenticated => 401', 401, $resp->get_status());
    } finally {
        wp_set_current_user(0);
        $kit->cleanup($A1);
        $kit->cleanup($A2);
        $kit->cleanup($A3);
        $kit->cleanup($B1);
        $kit->cleanupUser($classTeacher);
        $kit->cleanupUser($otherTeacher);
        $kit->cleanupUser($schoolAdmin);
    }
};
