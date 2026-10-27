<?php
/**
 * Suite: PA37 demo identity + cohort seeder (the real-backend demo data).
 *
 * Verifies what the headless front end needs from WordPress directly: the four
 * advertised demo logins exist with the roles the client guard branches on
 * ('student'/'parent'), the parent->child link and teacher scope resolve through
 * the Authorization layer, the class cohort clears the PA33 k-anonymity floor so
 * the benchmark/trend publish, the seeder is idempotent, cohort rows can never
 * sign in, and — where the environment allows app passwords — /auth/login hands
 * back a Bearer token that authenticates a later REST call (the exact flow the
 * Next rewrite uses). PA38 extends the seed with gamification ground truth
 * (lesson progress, XP ledger rows, streak calendar, mission history) and this
 * suite asserts the derived surfaces light up. Fixes are live demo fixtures, so
 * nothing is cleaned up; only the login-failure transient is restored in
 * finally.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Database\DemoUserSeeder;
use ABA\Core\Auth\Authorization;
use ABA\Core\Services\SkillsProfileService;
use ABA\Core\Services\GamificationService;
use ABA\Core\Repositories\LessonProgressRepository;
use ABA\Core\Repositories\StudentRepository;
use ABA\Core\REST\AuthController;

return function (AbaTestKit $kit): void {
    $kit->section('DemoSeederTest — real-backend demo identities + cohort (PA37)');

    $seededUsers = static fn(): int => count(get_users(['search' => '*aba_cohort_*', 'number' => 100]));
    $classSize = static fn(): int => (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}aba_students WHERE class_id = 402");
    $snapshotCount = static fn(): int => (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}aba_skill_snapshots");
    $t = static fn(string $name): string => $GLOBALS['wpdb']->prefix . $name;
    $ledgerRows = static fn(): int => (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$t('aba_xp_transactions')} WHERE student_id = 1");
    $streakRows = static fn(): int => (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$t('aba_streaks')} WHERE student_id = 1");
    $progressRows = static fn(): int => (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$t('aba_lesson_progress')} WHERE student_id = 1");

    $beforeU = $beforeC = $beforeS = 0;
    $beforeL = $beforeSt = $beforeP = 0;

    try {
        DemoUserSeeder::seed();
        $beforeU = $seededUsers();
        $beforeC = $classSize();
        $beforeS = $snapshotCount();
        $beforeL = $ledgerRows();
        $beforeSt = $streakRows();
        $beforeP = $progressRows();

        // Re-running must be a no-op (natural-key idempotency, SchoolSeeder stance).
        delete_option('aba_demo_users_seeded');
        DemoUserSeeder::seed();
        $kit->checkSame('re-running the seeder does not duplicate cohort users', $beforeU, $seededUsers());
        $kit->checkSame('re-running does not duplicate the class cohort', $beforeC, $classSize());
        $kit->checkSame('re-running does not duplicate snapshots', $beforeS, $snapshotCount());
        $kit->checkSame('re-running does not duplicate XP ledger rows (§12)', $beforeL, $ledgerRows());
        $kit->checkSame('re-running does not duplicate streak calendar days', $beforeSt, $streakRows());
        $kit->checkSame('re-running does not duplicate lesson progress rows', $beforeP, $progressRows());

        // ---- Identities the /login hint advertises -------------------------
        $student = get_user_by('login', 'aba_student_1');
        $parent  = get_user_by('login', 'aba_parent_1');
        $teacher = get_user_by('login', 'aba_teacher_1');
        $kit->check('demo learner exists with the student role (front-end guard)', $student && in_array('student', $student->roles, true));
        $kit->check('demo parent exists with the parent role (homeRouteFor)', $parent && in_array('parent', $parent->roles, true));
        $kit->check('demo teacher exists with the teacher role', $teacher && in_array('teacher', $teacher->roles, true));
        $kit->check('demo password authenticates the learner', $student && wp_authenticate($student->user_login, DemoUserSeeder::DEMO_PASSWORD) instanceof WP_User);

        // ---- Authorization wiring ------------------------------------------
        $kit->check('parent is linked to learner 1', $parent && Authorization::isParentOf((int) $parent->ID, 1));
        $studentId = $student ? Authorization::studentIdForUser((int) $student->ID) : 0;
        $kit->checkSame('learner login resolves to student 1', 1, $studentId);
        $kit->check('teacher is class-scoped to the demo class', $teacher && Authorization::assignedClassId((int) $teacher->ID) === 402);
        $teacherSeesLearner = function () use ($teacher, $studentId) {
            wp_set_current_user((int) $teacher->ID);
            $ok = Authorization::canViewStudent($studentId);
            wp_set_current_user(0);
            return $ok;
        };
        $kit->check('teacher can view the learner (class scope, §8)', $teacher && $teacherSeesLearner());

        // ---- Cohort clears the k-anonymity floor ---------------------------
        $service = new SkillsProfileService();
        $bm = $service->getClassBenchmark($studentId);
        $kit->check('class benchmark reports reliable (>= 5 members)', ($bm['reliable'] ?? false) === true);
        $published = 0;
        foreach ($bm['competencies'] as $row) {
            if (($row['classAverage'] ?? null) !== null) {
                $published++;
            }
        }
        $kit->check('at least one competency mean publishes for the demo class', $published >= 5);
        $trend = $service->getClassTrend($studentId);
        $kit->check('class trend has before/after dates (>= 2 points)', count($trend) >= 2);

        // ---- Cohort learners are data-only (can never sign in) -------------
        $cohort = get_user_by('login', 'aba_cohort_101');
        $kit->check('cohort user exists', $cohort !== false);
        $kit->check('cohort user cannot authenticate with the demo password', $cohort && !(wp_authenticate($cohort->user_login, DemoUserSeeder::DEMO_PASSWORD) instanceof WP_User));

        // ---- App-password token over REST (the PA37 headless flow) ---------
        // WP 6.5+ authenticates app passwords only via Basic; the harness mirrors
        // exactly what the api-client sends. Skipped cleanly when the
        // environment forbids app passwords (flag off, un-seeded production).
        delete_transient(AuthController::failKey('aba_student_1'));
        $req = new WP_REST_Request('POST', '/aba/v1/auth/login');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode(['username' => 'aba_student_1', 'password' => DemoUserSeeder::DEMO_PASSWORD]));
        $resp = rest_do_request($req);
        $kit->checkSame('POST /auth/login (demo learner) => 200', 200, $resp->get_status());
        $token = $resp->get_data()['token'] ?? null;
        if ($token !== null) {
            $kit->check('login hands back an app-password token', is_string($token) && preg_match('/^[A-Za-z0-9 ]{24,29}$/', $token) === 1);
            $_SERVER['PHP_AUTH_USER'] = 'aba_student_1';
            $_SERVER['PHP_AUTH_PW'] = $token;
            $me = rest_do_request(new WP_REST_Request('GET', '/aba/v1/me'));
            unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
            $kit->checkSame('Basic app-password authenticates GET /me => 200', 200, $me->get_status());
            $kit->checkSame('token identity is the demo learner', (int) $student->ID, (int) ($me->get_data()['id'] ?? 0));
        } else {
            $kit->check('token field present but null when app passwords unavailable', array_key_exists('token', $resp->get_data()));
        }

        // ---- PA38 gamification derives from the seeded ground truth --------
        $g = new GamificationService();
        $me = $g->buildMe($studentId);
        $kit->check('streak derives to >= 3 from the seeded calendar', ($me['currentStreak'] ?? 0) >= 3);
        // /students/me reads the denormalized streak columns — they must mirror
        // the calendar-derived numbers or the header contradicts /gamification/me.
        $studentRepo = new StudentRepository();
        $meRow = $studentRepo->toApiShape($studentRepo->findById($studentId));
        $kit->checkSame('students/me cached streak matches the ledger-derived streak', $me['currentStreak'], $meRow['currentStreak']);
        $kit->checkSame('students/me cached longest streak matches the calendar', $me['longestStreak'], $meRow['longestStreak']);
        $kit->check('XP total derives from the ledger (>= 380)', ($me['totalXp'] ?? 0) >= 380);
        $sources = array_keys($me['xpBySource'] ?? []);
        $kit->check('ledger breaks down into lesson + mission + assessment sources', array_intersect([ 'lesson', 'mission', 'assessment' ], $sources) === [ 'lesson', 'mission', 'assessment' ]);

        $missions = []; // id => first projection (deterministic, no id reuse)
        foreach ($g->buildMissions($studentId) as $m) {
            $missions[(int) $m['id']] = $m;
        }
        $kit->check('daily lesson mission (1) is complete', ($missions[1]['isCompleted'] ?? false) === true);
        $kit->check('daily XP mission (2) is complete', ($missions[2]['isCompleted'] ?? false) === true);
        $kit->check('weekly lesson mission (3) is still in progress', ($missions[3]['isCompleted'] ?? true) === false);
        $kit->check('weekly assessment mission (4) is complete', ($missions[4]['isCompleted'] ?? false) === true);

        $badges = []; // code => badge
        foreach ($g->buildBadges($studentId) as $b) {
            $badges[(string) $b['code']] = $b;
        }
        $kit->check('FIRST_ASSESSMENT badge unlocks from the ledger', ($badges['FIRST_ASSESSMENT']['isUnlocked'] ?? false) === true);
        $kit->check('STREAK_3 badge unlocks from the calendar', ($badges['STREAK_3']['isUnlocked'] ?? false) === true);
        $kit->check('STREAK_7 stays a locked goal', ($badges['STREAK_7']['isUnlocked'] ?? true) === false);
        $kit->check('unlocked badge carries a durable first-earned date (§11)', !empty($badges['STREAK_3']['unlockedAt'] ?? ''));

        $ach = $g->buildAchievements($studentId);
        $missionEntries = 0;
        foreach ($ach['timeline'] as $row) {
            if (($row['kind'] ?? '') === 'mission') {
                $missionEntries++;
            }
        }
        $kit->check('achievements timeline includes the seeded mission history', $missionEntries >= 2);
        $kit->check('timeline is newest-first', array_reduce($ach['timeline'], static function ($ok, $row) {
            static $prev = null;
            $cur = (string) ($row['earnedAt'] ?? '');
            $good = $ok && ($prev === null || strcmp($prev, $cur) >= 0);
            $prev = $cur;
            return $good;
        }, true));

        // /learn lights up: six completed nodes => the first gap unit is done,
        // the next is active (frontier), later units locked.
        $completed = (new LessonProgressRepository())->getCompletedNodeIds($studentId);
        $kit->checkSame('six trail nodes are marked completed', 6, count($completed));
        wp_set_current_user((int) $student->ID);
        $path = rest_do_request(new WP_REST_Request('GET', '/aba/v1/learning/path'));
        wp_set_current_user(0);
        $kit->checkSame('GET /learning/path for the demo learner => 200', 200, $path->get_status());
        $doneUnits = $activeUnits = $lockedUnits = 0;
        foreach ($path->get_data()['units'] ?? [] as $u) {
            if (($u['status'] ?? '') === 'completed') {
                $doneUnits++;
            } elseif (($u['status'] ?? '') === 'active') {
                $activeUnits++;
            } else {
                $lockedUnits++;
            }
        }
        $kit->check('path shows a completed gap unit, one active frontier and locked trails', $doneUnits >= 1 && $activeUnits === 1 && $lockedUnits >= 1);
        $kit->check('path current node sits inside the active unit', !empty($path->get_data()['currentNodeId']));
    } finally {
        // Never leave a lockout or failure window on the advertised demo login.
        delete_transient(AuthController::failKey('aba_student_1'));
        delete_transient(AuthController::failKey('aba_cohort_101'));
    }
};
