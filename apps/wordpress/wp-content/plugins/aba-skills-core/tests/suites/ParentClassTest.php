<?php
/**
 * Suite: Parent <-> class visibility (PA26, AGENTS.md §5/§8).
 *
 * The parent's children list and single-child overview are enriched with the
 * class each learner sits in — its real name/code/academic year from
 * wp_aba_classes and its homeroom teacher *derived* from the PA24 scope meta (a
 * staff whose aba_class_id points at the class AND holds the roster capability),
 * never a new table. Proves: teacher resolution skips a class-marked user who
 * lacks the capability; a class with no assigned staff yields a null teacher
 * (no fabrication); a child with no class gets a null context; and the whole
 * view is server-side gated so an unrelated parent sees nothing.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\ClassRepository;
use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Services\ParentOverviewService;

return function (AbaTestKit $kit): void {
    $kit->section('ParentClassTest — class + teacher visibility for parents (PA26)');

    // Two classes in different schools.
    $classOne = $kit->makeClass(1, '4A1', 'Lớp 4A1 (2026)', '4_6');
    $classTwo = $kit->makeClass(2, '10B2', 'Lớp 10B2 (2026)', '10_12');

    // Students: one per real class, one with no class at all.
    $A = $kit->makeStudent('4_6', 1, $classOne);
    $B = $kit->makeStudent('10_12', 2, $classTwo);
    $C = $kit->makeStudent('4_6', 1, 0);

    // Class-marked staff WITHOUT the roster cap is created FIRST (lower user id)
    // so the capability filter is exercised regardless of ordering; the real
    // teacher carries the cap and a distinct display name.
    $teacherNoCap = $kit->makeStaff(1, false, $classOne);
    $teacherCap   = $kit->makeStaff(1, true, $classOne);
    wp_update_user(['ID' => $teacherCap, 'display_name' => 'Teacher Alpha']);

    $parent     = $kit->makeParent('Parent One');
    $otherParent = $kit->makeParent('Parent Two');
    $kit->linkParentStudent($parent, $A);
    $kit->linkParentStudent($parent, $B);
    $kit->linkParentStudent($parent, $C);

    $classRepo = new ClassRepository();
    $studentRepo = new StudentRepository();
    $overview = new ParentOverviewService();

    try {
        // ---- ClassRepository: real class data resolved from wp_aba_classes ----
        $cls = $classRepo->find($classOne);
        $kit->check('find resolves the class row', is_array($cls));
        $kit->checkSame('class name is the real one', 'Lớp 4A1 (2026)', $cls['name'] ?? null);
        $kit->checkSame('class code is projected', '4A1', $cls['code'] ?? null);
        $kit->checkSame('class band is projected', '4_6', $cls['gradeGroup'] ?? null);
        $kit->check('find(0) is null', $classRepo->find(0) === null);
        $kit->check('find(unknown) is null', $classRepo->find(99999999) === null);

        // Teacher is the capability holder, not the capability-less class-mate.
        $ctx1 = $classRepo->contextFor($classOne);
        $kit->checkSame('teacher is the roster-cap staff', $teacherCap, $ctx1['teacherId'] ?? null);
        $kit->checkSame('teacher name is surfaced', 'Teacher Alpha', $ctx1['teacherName'] ?? null);
        // A class with no assigned staff must not fabricate a teacher.
        $ctx2 = $classRepo->contextFor($classTwo);
        $kit->check('class without staff has null teacher', ($ctx2['teacherId'] ?? null) === null);
        $kit->check('but still carries the class name', ($ctx2['name'] ?? null) === 'Lớp 10B2 (2026)');
        $kit->check('contextFor(0) is null', $classRepo->contextFor(0) === null);

        // ---- Service: decorateChildren adds per-child class context ----------
        $children = $studentRepo->getChildrenForParent($parent);
        $kit->checkSame('parent has three linked children', 3, count($children));
        $decorated = $overview->decorateChildren($children);
        $byId = [];
        foreach ($decorated as $c) {
            $byId[(int) $c['id']] = $c;
        }
        $kit->check('child A gains a class object', is_array($byId[$A]['class'] ?? null));
        $kit->checkSame('child A class name', 'Lớp 4A1 (2026)', $byId[$A]['class']['name'] ?? null);
        $kit->checkSame('child A teacher', 'Teacher Alpha', $byId[$A]['class']['teacherName'] ?? null);
        $kit->check('child B class has no teacher', ($byId[$B]['class']['teacherId'] ?? null) === null);
        $kit->check('child C (no class) gets null context', ($byId[$C]['class'] ?? null) === null);
        $kit->check('decoration is deterministic', $decorated === $overview->decorateChildren($children));

        // ---- Overview build() carries the same context -----------------------
        $ov = $overview->build($A);
        $kit->checkSame('overview student class name', 'Lớp 4A1 (2026)', $ov['student']['class']['name'] ?? null);
        $kit->check('overview for a class-less child is null', $overview->build($C)['student']['class'] === null);

        // ---- REST: server-side scoping, never a client-supplied id -----------
        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/parent/children'));
        $kit->checkSame('GET /parent/children unauthenticated => 401', 401, $resp->get_status());

        wp_set_current_user($parent);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/parent/children'));
        $kit->checkSame('parent /parent/children => 200', 200, $resp->get_status());
        $list = [];
        foreach ($resp->get_data() as $c) {
            $list[(int) $c['id']] = $c;
        }
        $kit->checkSame('REST child A teacher name', 'Teacher Alpha', $list[$A]['class']['teacherName'] ?? null);

        // An unrelated parent sees nothing — no leak of another family's class.
        wp_set_current_user($otherParent);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/parent/children'));
        $kit->checkSame('unlinked parent /parent/children => 200', 200, $resp->get_status());
        $kit->checkSame('unlinked parent sees no children', 0, count($resp->get_data()));
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/parent/children/' . $A . '/overview'));
        $kit->checkSame('unlinked parent child overview => 403', 403, $resp->get_status());

        wp_set_current_user($parent);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/parent/children/' . $A . '/overview'));
        $kit->checkSame('linked parent child overview => 200', 200, $resp->get_status());
        $kit->checkSame('overview REST class name', 'Lớp 4A1 (2026)', $resp->get_data()['student']['class']['name'] ?? null);
    } finally {
        wp_set_current_user(0);
        $kit->cleanup($A);
        $kit->cleanup($B);
        $kit->cleanup($C);
        $kit->cleanupUser($parent);
        $kit->cleanupUser($otherParent);
        $kit->cleanupUser($teacherNoCap);
        $kit->cleanupUser($teacherCap);
        $kit->cleanupClass($classOne);
        $kit->cleanupClass($classTwo);
    }
};
