<?php
/**
 * Seeds the demo School + Class fixtures (PA28).
 *
 * The two demo learners (wp_aba_students 1 and 2) already carry bare
 * school_id / class_id (10/402 and 20/501), but wp_aba_schools and wp_aba_classes
 * ship empty — so PA27's projection correctly yields a null schoolName/className
 * on the *real* API (there is simply no row to join). This seeder fills exactly
 * those referenced rows so the backend is self-contained and the API returns the
 * same names the front-end mock advertises, letting the app run off WordPress
 * with MSW disabled.
 *
 * Idempotent and additive: rows are keyed by their natural unique constraints
 * (schools.code, classes uk_class_school_code) and matched by explicit id, so a
 * re-run never duplicates nor overwrites a row an admin has since renamed. Mirrors
 * ContentSeeder's "DB is the source of truth, option is a cheap guard" stance.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

class SchoolSeeder {
    public const VERSION = 'demo-v1';

    /** Explicit ids chosen to match the existing demo students' references. */
    private const SCHOOLS = [
        [ 'id' => 10, 'code' => 'ABA-SCH-010', 'name' => 'Trường Tiểu Học Quốc Tế Á Châu', 'province' => 'Hà Nội' ],
        [ 'id' => 20, 'code' => 'ABA-SCH-020', 'name' => 'Trường Tiểu Học Sao Mai', 'province' => 'Hà Nội' ],
    ];

    private const CLASSES = [
        [ 'id' => 402, 'school_id' => 10, 'code' => '4A2', 'name' => 'Lớp 4A2', 'grade_level' => 4, 'grade_group' => '4_6' ],
        [ 'id' => 501, 'school_id' => 20, 'code' => '5B1', 'name' => 'Lớp 5B1', 'grade_level' => 5, 'grade_group' => '4_6' ],
    ];

    public static function seed(): void {
        if (get_option('aba_school_seeded') === self::VERSION) {
            return;
        }

        foreach (self::SCHOOLS as $s) {
            self::ensureSchool($s);
        }
        foreach (self::CLASSES as $c) {
            self::ensureClass($c);
        }

        update_option('aba_school_seeded', self::VERSION, false);
    }

    private static function ensureSchool(array $s): void {
        global $wpdb;
        $table = $wpdb->prefix . 'aba_schools';
        // Match by the explicit id first, then by the unique code — if either is
        // taken we leave the existing row untouched (never clobber an admin edit).
        $byId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $s['id']));
        if ($byId === $s['id']) {
            return;
        }
        $byCode = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE code = %s", $s['code']));
        if ($byCode !== null) {
            return;
        }
        $wpdb->insert(
            $table,
            [
                'id'       => $s['id'],
                'code'     => $s['code'],
                'name'     => $s['name'],
                'province' => $s['province'],
                'status'   => 'active',
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    private static function ensureClass(array $c): void {
        global $wpdb;
        $table = $wpdb->prefix . 'aba_classes';
        $byId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $c['id']));
        if ($byId === $c['id']) {
            return;
        }
        // (school_id, code) is the class unique key; bail if it already exists.
        $byCode = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE school_id = %d AND code = %s",
            $c['school_id'],
            $c['code']
        ));
        if ($byCode !== null) {
            return;
        }
        $wpdb->insert(
            $table,
            [
                'id'            => $c['id'],
                'school_id'     => $c['school_id'],
                'code'          => $c['code'],
                'name'          => $c['name'],
                'grade_level'   => $c['grade_level'],
                'grade_group'   => $c['grade_group'],
                'academic_year' => '2026-2027',
                'status'        => 'active',
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }
}
