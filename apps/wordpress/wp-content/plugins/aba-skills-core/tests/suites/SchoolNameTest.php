<?php
/**
 * Suite: School/class name projection (PA27).
 *
 * The student row stores only bare school_id / class_id, yet toApiShape advertises
 * schoolName / className — which read $row['school_name'] / $row['class_name'] that
 * the old single-table SELECT never produced, so both were always null on the real
 * API. PA27 LEFT JOINs wp_aba_schools + wp_aba_classes into the student reads. This
 * proves the names now resolve across every read path (findById, roster, parent
 * children) and that LEFT (not INNER) still returns a student whose school/class
 * row is missing — the id is surfaced with a null name rather than dropped (§8).
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\StudentRepository;

return function (AbaTestKit $kit): void {
    $kit->section('SchoolNameTest — school/class names resolved by projection (PA27)');

    $school = $kit->makeSchool('SCH-27', 'Trường Demo 27');
    $class  = $kit->makeClass($school, '5A1', 'Lớp 5A1', '4_6');

    $A = $kit->makeStudent('4_6', $school, $class);       // fully attached
    $O = $kit->makeStudent('4_6', 99999999, 88888888);     // dangling school/class

    $repo = new StudentRepository();

    try {
        // ---- Single read resolves both names --------------------------------
        // findById returns the raw joined row (callers map via toApiShape), so
        // assert on the SQL alias columns here, not the API-cased keys.
        $row = $repo->findById($A);
        $kit->check('findById still returns the student', is_array($row));
        $kit->checkSame('school_name resolved from wp_aba_schools', 'Trường Demo 27', $row['school_name'] ?? null);
        $kit->checkSame('class_name resolved from wp_aba_classes', 'Lớp 5A1', $row['class_name'] ?? null);

        // ---- Roster reads carry the same names ------------------------------
        $byId = [];
        foreach ($repo->listBySchool($school) as $s) {
            $byId[(int) $s['id']] = $s;
        }
        $kit->check('school roster includes A', isset($byId[$A]));
        $kit->checkSame('school roster row has schoolName', 'Trường Demo 27', $byId[$A]['schoolName'] ?? null);
        $kit->checkSame('school roster row has className', 'Lớp 5A1', $byId[$A]['className'] ?? null);

        $classRows = $repo->listByClass($class);
        $kit->checkSame('class roster includes A', 1, count($classRows));
        $kit->checkSame('class roster row has schoolName', 'Trường Demo 27', $classRows[0]['schoolName'] ?? null);

        // ---- Parent children path -------------------------------------------
        $parent = $kit->makeParent('Parent 27');
        $kit->linkParentStudent($parent, $A);
        $kids = $repo->getChildrenForParent($parent);
        $kit->checkSame('parent sees one child', 1, count($kids));
        $kit->checkSame('child row carries schoolName', 'Trường Demo 27', $kids[0]['schoolName'] ?? null);

        // ---- LEFT-JOIN safety: missing school/class keeps the student -------
        $orphan = $repo->findById($O);
        $kit->check('dangling student is still returned (not dropped)', is_array($orphan));
        $kit->checkSame('dangling school_name is null', null, $orphan['school_name']);
        $kit->checkSame('dangling class_name is null', null, $orphan['class_name']);
    } finally {
        $kit->cleanup($A);
        $kit->cleanup($O);
        if (isset($parent)) {
            $kit->cleanupUser($parent);
        }
        $kit->cleanupClass($class);
        $kit->cleanupSchool($school);
    }
};
