<?php
/**
 * ABA Skills Core — durable test harness bootstrap.
 *
 * Lives inside the plugin so it is bind-mounted with the code and auto-runnable
 * in CI (`docker exec aba-wordpress php .../tests/run_all.php`) without any
 * `docker cp`. The plugin directory is web-served, so this file hard-refuses
 * to run outside the CLI: a request to the webroot never bootstraps WordPress
 * or touches data.
 *
 * @package ABA_Skills_Core
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('ABA test harness is CLI-only.' . PHP_EOL);
}

// Resolve wp-load.php. Default: <root>/wp-content/plugins/aba-skills-core/tests
// walked up four levels to <root>. Overridable via ABA_WP_ROOT for other mounts.
$wpRoot = getenv('ABA_WP_ROOT') ?: dirname(__DIR__, 4);
$wpLoad = rtrim($wpRoot, '/\\') . '/wp-load.php';

if (!is_file($wpLoad)) {
    fwrite(STDERR, "wp-load.php not found at {$wpLoad} (set ABA_WP_ROOT)" . PHP_EOL);
    exit(1);
}

require_once $wpLoad;

if (!defined('ABSPATH')) {
    fwrite(STDERR, 'WordPress did not bootstrap (ABSPATH missing).' . PHP_EOL);
    exit(1);
}

/**
 * Minimal zero-dependency test kit. Each suite is a closure receiving this
 * object and calling check()/checkSame()/checkClose(). Counters are shared so
 * run_all.php can print one aggregate result and set the CI exit code.
 */
final class AbaTestKit
{
    public int $pass = 0;
    public int $fail = 0;

    /** @var string Current suite label, echoed on failures for context. */
    private string $section = '';

    public function section(string $title): void
    {
        $this->section = $title;
        echo PHP_EOL . '==== ' . $title . ' ====' . PHP_EOL;
    }

    public function check(string $name, bool $condition): void
    {
        if ($condition) {
            $this->pass++;
            echo '  [PASS] ' . $name . PHP_EOL;
        } else {
            $this->fail++;
            echo '  [FAIL] ' . $name . '  (section: ' . $this->section . ')' . PHP_EOL;
        }
    }

    /** Loose scalar equality with a readable diff on failure. */
    public function checkSame(string $name, $expected, $actual): void
    {
        $ok = $expected == $actual;
        $this->check($name, $ok);
        if (!$ok) {
            echo '         expected: ' . $this->render($expected)
                . ' | actual: ' . $this->render($actual) . PHP_EOL;
        }
    }

    /** Float comparison tolerant to the engine's 2-decimal rounding. */
    public function checkClose(string $name, float $expected, ?float $actual, float $eps = 0.011): void
    {
        $ok = $actual !== null && abs($expected - $actual) <= $eps;
        $this->check($name, $ok);
        if (!$ok) {
            echo '         expected ~= ' . $this->render($expected)
                . ' | actual: ' . $this->render($actual) . PHP_EOL;
        }
    }

    /**
     * Create an isolated throwaway student so suites never depend on seeded
     * rows and never collide. Returns the new wp_aba_students.id.
     */
    public function makeStudent(string $gradeGroup = '4_6', int $schoolId = 1, int $classId = 0): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'aba_students';

        // Big out-of-range id keeps user_id clear of real accounts; the unique
        // student_code guarantees a fresh row even across concurrent runs.
        $token = 900000000 + random_int(1, 99999999);

        $inserted = $wpdb->insert($table, [
            'user_id'         => $token,
            'school_id'       => $schoolId,
            'class_id'        => $classId > 0 ? $classId : null,
            'student_code'    => 'TEST-' . $token,
            'first_name'      => 'Test',
            'last_name'       => 'Student',
            'grade_level'     => 5,
            'grade_group'     => $gradeGroup,
            'status'          => 'active',
            'total_xp'        => 0,
            'current_streak'  => 0,
            'longest_streak'  => 0,
        ], ['%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d']);

        if ($inserted === false) {
            throw new RuntimeException('makeStudent failed: ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Remove every trace of a throwaway student across the ABA tables, so the
     * suite is repeatable. Guarded by table existence to stay safe on partial
     * migrations.
     */
    public function cleanup(int $studentId): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $sessionIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$p}aba_assessment_sessions WHERE student_id = %d",
            $studentId
        ));
        if (!empty($sessionIds)) {
            $in = implode(',', array_map('intval', $sessionIds));
            if ($this->tableExists("{$p}aba_assessment_responses")) {
                $wpdb->query("DELETE FROM {$p}aba_assessment_responses WHERE session_id IN ($in)");
            }
            if ($this->tableExists("{$p}aba_score_runs")) {
                $wpdb->query("DELETE FROM {$p}aba_score_runs WHERE session_id IN ($in)");
            }
        }

        foreach ([
            'aba_assessment_responses',
            'aba_score_runs',
            'aba_skill_scores',
            'aba_skill_snapshots',
            'aba_xp_transactions',
            'aba_power_ups',
            'aba_lesson_progress',
            'aba_streaks',
            'aba_mission_progress',
            'aba_student_badges',
            'aba_reports',
            'aba_events',
            'aba_assessment_sessions',
        ] as $logical) {
            $table = $p . $logical;
            if ($this->tableExists($table)) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE student_id = %d", $studentId));
            }
        }

        $wpdb->query($wpdb->prepare("DELETE FROM {$p}aba_students WHERE id = %d", $studentId));
    }

    public function summary(): void
    {
        echo PHP_EOL . '==== RESULT: ' . $this->pass . ' passed, ' . $this->fail . ' failed ====' . PHP_EOL;
    }

    /**
     * Create a throwaway WP subscriber linked to $studentId so suites can
     * exercise the *real* REST stack through rest_do_request() with a genuine
     * authenticated identity. The subscriber already holds ABA_CAP_STUDENT (via
     * aba_grant_role_capabilities) and the meta link makes it the student itself.
     *
     * $password lets a suite log in with a known secret (AuthTest drives the real
     * /auth/login). Callers that pass nothing get a random, unusable password.
     */
    public function makeUser(int $studentId, ?string $password = null): int
    {
        $token = 950000000 + random_int(1, 99999999);
        $login = 'aba_test_' . $token;

        $user_id = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $login . '@example.test',
            'display_name' => 'ABA Test User',
            'first_name'   => 'ABA',
            'last_name'    => 'Test',
            'role'         => 'subscriber',
            'user_pass'    => $password ?? wp_generate_password(24, true, true),
        ]);
        if (is_wp_error($user_id)) {
            throw new RuntimeException('makeUser failed: ' . $user_id->get_error_message());
        }
        $user_id = (int) $user_id;

        // Canonical link is wp_aba_students.user_id; keep the meta cache in sync
        // so both /me (findByUserId) and Authorization::studentIdForUser agree.
        update_user_meta($user_id, 'aba_student_id', $studentId);
        $this->linkStudentUser($studentId, $user_id);

        return $user_id;
    }

    /** Point a throwaway student's user_id column at a real WP user id. */
    public function linkStudentUser(int $studentId, int $userId): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'aba_students',
            ['user_id' => $userId],
            ['id' => $studentId],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Create a throwaway SCHOOL STAFF user (PA23) so suites can prove school
     * scope server-side. Role stays 'subscriber' on purpose — the roster right
     * comes ONLY from the explicit capability, mirroring the "use capabilities,
     * not role names" rule (§3). $schoolId 0 leaves the caller unassigned; when
     * $grantCap is false the user holds the school meta but not the capability.
     */
    public function makeStaff(int $schoolId, bool $grantCap = true, int $classId = 0): int
    {
        $token = 970000000 + random_int(1, 99999999);
        $login = 'aba_test_' . $token;

        $user_id = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $login . '@example.test',
            'display_name' => 'ABA Test Staff',
            'role'         => 'subscriber',
            'user_pass'    => wp_generate_password(24, true, true),
        ]);
        if (is_wp_error($user_id)) {
            throw new RuntimeException('makeStaff failed: ' . $user_id->get_error_message());
        }
        $user_id = (int) $user_id;

        if ($grantCap) {
            $user = new \WP_User($user_id);
            $user->add_cap('aba_manage_students');
        }
        if ($schoolId > 0) {
            update_user_meta($user_id, 'aba_school_id', $schoolId);
        }
        if ($classId > 0) {
            update_user_meta($user_id, 'aba_class_id', $classId);
        }
        return $user_id;
    }

    /**
     * Create a throwaway class row (PA26) so suites can prove the parent's class
     * view resolves real wp_aba_classes data. Returns the new wp_aba_classes.id.
     */
    public function makeClass(int $schoolId, string $code, string $name, string $gradeGroup = '4_6', string $academicYear = '2026-2027'): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'aba_classes',
            [
                'school_id'     => $schoolId,
                'code'          => $code,
                'name'          => $name,
                'grade_level'   => 4,
                'grade_group'   => $gradeGroup,
                'academic_year' => $academicYear,
                'status'        => 'active',
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Create a throwaway PARENT user (PA26). Role stays 'subscriber'; the read
     * right comes ONLY from the explicit aba_report_read capability, mirroring
     * the capability-not-role rule (§3). $classId is unused here but kept for
     * symmetry; parents never hold roster scope.
     */
    public function makeParent(string $displayName = 'ABA Test Parent'): int
    {
        $token = 980000000 + random_int(1, 99999999);
        $login = 'aba_test_' . $token;

        $user_id = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $login . '@example.test',
            'display_name' => $displayName,
            'role'         => 'subscriber',
            'user_pass'    => wp_generate_password(24, true, true),
        ]);
        if (is_wp_error($user_id)) {
            throw new RuntimeException('makeParent failed: ' . $user_id->get_error_message());
        }
        $user_id = (int) $user_id;

        $user = new \WP_User($user_id);
        $user->add_cap('aba_report_read');
        return $user_id;
    }

    /** Link a parent user to a student with an active relationship (PA26). */
    public function linkParentStudent(int $parentUserId, int $studentId): void
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'aba_parent_student',
            [
                'parent_user_id' => $parentUserId,
                'student_id'     => $studentId,
                'relationship'   => 'parent',
                'is_primary'     => 1,
                'status'         => 'active',
            ],
            ['%d', '%d', '%s', '%d', '%s']
        );
    }

    /** Remove a class row created by makeClass (zero residue for PA26). */
    public function cleanupClass(int $classId): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'aba_classes', ['id' => $classId], ['%d']);
    }

    /**
     * Create a throwaway school row (PA27) so suites can prove the student
     * projection resolves school_name via the LEFT JOIN. Returns wp_aba_schools.id.
     */
    public function makeSchool(string $code, string $name): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'aba_schools',
            [
                'code'   => $code,
                'name'   => $name,
                'status' => 'active',
            ],
            ['%s', '%s', '%s']
        );
        return (int) $wpdb->insert_id;
    }

    /** Remove a school row created by makeSchool (zero residue for PA27). */
    public function cleanupSchool(int $schoolId): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'aba_schools', ['id' => $schoolId], ['%d']);
    }

    /** Remove a throwaway WP user and all of its meta (dependency-free delete). */
    public function cleanupUser(int $userId): void
    {
        global $wpdb;
        // A parent may have child links; drop them so no orphan row survives.
        $wpdb->delete($wpdb->prefix . 'aba_parent_student', ['parent_user_id' => $userId], ['%d']);
        $wpdb->delete($wpdb->prefix . 'usermeta', ['user_id' => $userId], ['%d']);
        $wpdb->delete($wpdb->prefix . 'users', ['ID' => $userId], ['%d']);
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;
        $like = $wpdb->esc_like($table);
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) !== null;
    }

    private function render($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }
}
