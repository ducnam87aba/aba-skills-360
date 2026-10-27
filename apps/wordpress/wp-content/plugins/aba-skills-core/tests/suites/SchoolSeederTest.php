<?php
/**
 * Suite: School + Class demo seed (PA28).
 *
 * Proves SchoolSeeder fills the exact school/class rows the two demo learners
 * reference (schools 10/20, classes 402/501) so PA27's projection finally yields
 * real schoolName/className on the *real* API — and that the seeder is idempotent:
 * re-running after resetting the option guard neither duplicates nor changes the
 * counts. The seeded fixtures are intentional demo data (like the ContentSeeder
 * lessons), so they are left in place rather than cleaned.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Database\SchoolSeeder;
use ABA\Core\Repositories\StudentRepository;

return function (AbaTestKit $kit): void {
    $kit->section('SchoolSeederTest — demo school/class fixtures + PA27 names (PA28)');

    global $wpdb;
    $schools = $wpdb->prefix . 'aba_schools';
    $classes = $wpdb->prefix . 'aba_classes';

    // The seeder already ran at boot (plugins_loaded). Assert the referenced rows.
    $kit->checkSame('school 10 seeded', 1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$schools} WHERE id = 10"));
    $kit->checkSame('school 20 seeded', 1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$schools} WHERE id = 20"));
    $kit->checkSame('class 402 seeded', 1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$classes} WHERE id = 402"));
    $kit->checkSame('class 501 seeded', 1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$classes} WHERE id = 501"));

    // ---- Idempotency: reset the guard, re-seed, nothing changes --------------
    $schoolCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$schools}");
    $classCount  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$classes}");
    delete_option('aba_school_seeded');
    SchoolSeeder::seed();
    SchoolSeeder::seed(); // twice more to be sure re-runs are inert
    $kit->checkSame('re-seed does not duplicate schools', $schoolCount, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$schools}"));
    $kit->checkSame('re-seed does not duplicate classes', $classCount, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$classes}"));

    // ---- Integration: PA27 projection now resolves names for the demo kids ---
    $repo = new StudentRepository();
    $s1 = $repo->findById(1); // demo learner referencing school 10 / class 402
    $kit->check('demo student 1 is present', is_array($s1));
    $kit->checkSame('student 1 school_name resolved', 'Trường Tiểu Học Quốc Tế Á Châu', $s1['school_name'] ?? null);
    $kit->checkSame('student 1 class_name resolved', 'Lớp 4A2', $s1['class_name'] ?? null);

    $s2 = $repo->findById(2); // school 20 / class 501
    $kit->checkSame('student 2 school_name resolved', 'Trường Tiểu Học Sao Mai', $s2['school_name'] ?? null);
    $kit->checkSame('student 2 class_name resolved', 'Lớp 5B1', $s2['class_name'] ?? null);

    // The API-cased projection (via a list path) agrees with the raw row.
    $roster = $repo->listBySchool(10);
    $found = null;
    foreach ($roster as $r) {
        if ((int) $r['id'] === 1) {
            $found = $r;
        }
    }
    $kit->check('school 10 roster includes demo student 1', is_array($found));
    $kit->checkSame('roster projection schoolName matches', 'Trường Tiểu Học Quốc Tế Á Châu', $found['schoolName'] ?? null);
    $kit->checkSame('roster projection className matches', 'Lớp 4A2', $found['className'] ?? null);
};
