<?php
/**
 * Suite: School-scope hardening (PA23, AGENTS.md §8).
 *
 * "Never allow access to another school's students by simply changing an ID."
 * Proves a teacher's read access is bounded by BOTH an explicit capability and a
 * matching school — a bare role, a school meta without the capability, or an
 * unassigned staff all fail. Then exercises the shipped roster + student routes so
 * the guarantee holds through the real REST stack, not just the service layer.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Auth\Authorization;

return function (AbaTestKit $kit): void {
    $kit->section('SchoolScopeTest — cross-school isolation (§8, PA23)');

    // Two students in school 1, one in school 2.
    $sA1  = $kit->makeStudent('4_6', 1);
    $sA2  = $kit->makeStudent('4_6', 1);
    $sB1  = $kit->makeStudent('4_6', 2);

    // Staff principals: scoped teacher, same-school but capability-less, and an
    // unassigned teacher (capability without a school).
    $teacher      = $kit->makeStaff(1, true);
    $noCap        = $kit->makeStaff(1, false);
    $unassigned   = $kit->makeStaff(0, true);

    $idsOf = static function (array $students): array {
        return array_map(static fn($s) => (int) $s['id'], $students);
    };

    try {
        // ---- Service layer: capability + school both required ---------------
        wp_set_current_user($teacher);
        $kit->check('teacher reads a student in their own school', Authorization::canViewStudent($sA1));
        $kit->check('teacher reads the OTHER own-school student', Authorization::canViewStudent($sA2));
        $kit->check('teacher is DENIED a cross-school student by id (§8)', !Authorization::canViewStudent($sB1));

        wp_set_current_user($noCap);
        $kit->check('same-school WITHOUT the roster capability is denied', !Authorization::canViewStudent($sA1));

        wp_set_current_user($unassigned);
        $kit->check('capability WITHOUT an assigned school is denied', !Authorization::canViewStudent($sA1));

        $kit->checkSame('student school resolves from the table', 1, Authorization::studentSchoolId($sA1));
        $kit->checkSame('staff school resolves from assignment meta', 1, Authorization::assignedSchoolId($teacher));

        // ---- REST layer: the roster + scoped student routes -----------------
        wp_set_current_user($teacher);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/roster'));
        $kit->checkSame('GET /school/roster => 200 for scoped teacher', 200, $resp->get_status());
        $roster = $resp->get_data();
        $kit->checkSame('roster is scoped to the caller school', 1, (int) ($roster['schoolId'] ?? 0));
        $rIds = $idsOf($roster['students'] ?? []);
        $kit->check('roster includes own-school student A1', in_array($sA1, $rIds, true));
        $kit->check('roster includes own-school student A2', in_array($sA2, $rIds, true));
        $kit->check('roster EXCLUDES the cross-school student B1', !in_array($sB1, $rIds, true));

        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/students/' . $sA1));
        $kit->checkSame('GET /school/students/{own} => 200', 200, $resp->get_status());

        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/students/' . $sB1));
        $kit->checkSame('GET /school/students/{cross-school} => 403', 403, $resp->get_status());

        // Roster capability is enforced server-side, not by the client's word.
        wp_set_current_user($noCap);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/roster'));
        $kit->checkSame('roster without capability => 403', 403, $resp->get_status());

        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/roster'));
        $kit->checkSame('roster unauthenticated => 401', 401, $resp->get_status());
    } finally {
        wp_set_current_user(0);
        $kit->cleanup($sA1);
        $kit->cleanup($sA2);
        $kit->cleanup($sB1);
        $kit->cleanupUser($teacher);
        $kit->cleanupUser($noCap);
        $kit->cleanupUser($unassigned);
    }
};
