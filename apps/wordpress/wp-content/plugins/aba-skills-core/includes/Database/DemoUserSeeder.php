<?php
/**
 * Seeds the demo identity layer + a realistic class cohort (PA37).
 *
 * The headless front end can only be exercised against WordPress when there
 * are (a) wp_users with known passwords and the ABA role/capability shape the
 * Authorization layer expects, (b) a parent->child link, and (c) a class big
 * enough to clear the PA33 k-anonymity floor (5 distinct contributors) so the
 * benchmark/trend surfaces publish instead of staying suppressed. None of that
 * exists in the shipped DB, so this seeder makes the real backend demo-ready.
 *
 * Like SchoolSeeder it is idempotent and additive, keyed by natural unique
 * constraints (users by user_login, students by the UNIQUE user_id, scores by
 * the repo upsert, parent links by the pair). Cohort learners get a real
 * wp_user because students.user_id is UNIQUE — but a random, unusable password
 * and no ABA role, so they are data-only rows that anonymize aggregates and can
 * never sign in. Real logins are the four advertised demo accounts. Guarded by
 * a version option; re-runs are cheap.
 *
 * Demo passwords are fixed and documented in the app's login hint — acceptable
 * for a local-only environment; never reuse this class in staging/production.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

class DemoUserSeeder {
    public const VERSION = 'demo-v5';

    /** Shared demo password (local environment only, mirrors the /login hint). */
    public const DEMO_PASSWORD = 'Aba@Demo2026';

    /** ABA roles the guard/capability map already knows about. */
    private const ROLES = [
        'student' => ['name' => 'Student', 'caps' => ['aba_assessment_take', 'read']],
        'parent'  => ['name' => 'Parent', 'caps' => ['aba_report_read', 'read']],
        'teacher' => ['name' => 'Teacher', 'caps' => ['aba_assessment_take', 'aba_report_read', 'aba_manage_students', 'read']],
    ];

    /**
     * The four advertised logins. Roles drive the front end: the guard and
     * homeRouteFor branch on the literal 'parent'/'student' role names, so the
     * seeder must use exactly those (user 3 arrived as 'author' — fixed here).
     */
    private const USERS = [
        [ 'login' => 'aba_admin',     'display' => 'Quản trị ABA',  'role' => 'administrator', 'email' => 'admin@aba.dev' ],
        [ 'login' => 'aba_student_1', 'display' => 'Nguyễn Nam Dương', 'role' => 'student',    'email' => 'namduong@aba.dev' ],
        [ 'login' => 'aba_parent_1',  'display' => 'Chị Lan (Phụ huynh)', 'role' => 'parent',  'email' => 'parentlan@aba.dev' ],
        [ 'login' => 'aba_teacher_1', 'display' => 'Cô Hương (GVCN 4A2)', 'role' => 'teacher', 'email' => 'teacher@aba.dev' ],
    ];

    /** Classmates added to learner 1's class (402) to clear the k-anonymity floor. */
    private const COHORT = [
        [ 'code' => 'ABA-2026-101', 'first' => 'Minh Anh', 'last' => 'Trần',     'scores' => [ 'SA' => 74, 'ER' => 65, 'SM' => 60, 'CO' => 80, 'CE' => 70 ] ],
        [ 'code' => 'ABA-2026-102', 'first' => 'Khánh Lyn', 'last' => 'Nguyễn',  'scores' => [ 'SA' => 85, 'ER' => 70, 'SM' => 65, 'CO' => 75 ] ],
        [ 'code' => 'ABA-2026-103', 'first' => 'Bảo Ngọc', 'last' => 'Lê',       'scores' => [ 'SA' => 60, 'ER' => 55, 'SM' => 70, 'PD' => 65 ] ],
        [ 'code' => 'ABA-2026-104', 'first' => 'Duy Khoa', 'last' => 'Phạm',     'scores' => [ 'SA' => 78, 'CO' => 60, 'CE' => 55, 'PD' => 72 ] ],
        [ 'code' => 'ABA-2026-105', 'first' => 'Hải Yến', 'last' => 'Đỗ',        'scores' => [ 'ER' => 72, 'SM' => 68, 'CO' => 66, 'PD' => 60 ] ],
        [ 'code' => 'ABA-2026-106', 'first' => 'Tô Trung', 'last' => 'Vương',    'scores' => [ 'SA' => 66, 'ER' => 60, 'PD' => 75 ] ],
    ];

    /** Two measurement dates so the class trend has a before/after (Aug -> Oct). */
    private const SNAP_AUG = '2026-08-01 03:00:00';
    private const SNAP_OCT = '2026-10-01 03:00:00';
    private const SNAP_SESSION_BASE = 900000; // pseudo ids; snapshots do not join sessions

    /**
     * PA38 — trail history for learner 1, keyed by the same node ids the
     * LearningPathService derives (units are gap-ordered, but progress rows
     * only need the ids). CE unit fully done, ER nearly, SM started: the
     * /learn map lights up completed/active/locked without any fabricated
     * counter — everything downstream (missions, streak, badges) derives.
     */
    private const TRAIL_NODES = [
        'unit-ce-node-1', 'unit-ce-node-2', 'unit-ce-node-3',
        'unit-er-node-1', 'unit-er-node-2',
        'unit-sm-node-1',
    ];
    /** XP per template node (lesson 30 / quiz 60 / boss 100) matching NODE_TEMPLATE. */
    private const TRAIL_XP = [ 30, 60, 100, 30, 60, 30 ];
    /**
     * Backdated completion moments (older => newest) for the five historical
     * nodes. The sixth node (unit-ce-node-1) is stamped at seed time instead:
     * a freshly completed lesson today is what mission 1 (daily) must show.
     */
    private const TRAIL_DATES = [
        '2026-09-12 02:30:00', '2026-09-20 03:00:00',
        '2026-10-05 02:00:00', '2026-10-14 02:30:00', '2026-10-22 03:00:00',
    ];

    public static function seed(): void {
        if (get_option('aba_demo_users_seeded') === self::VERSION) {
            return;
        }

        self::ensureRoles();
        $ids = self::ensureUsers();
        self::ensureStaffScope($ids['aba_teacher_1']);
        self::ensureParentLink($ids['aba_parent_1']);
        self::ensureCohort();
        self::ensureLearnerFixtures($ids['aba_student_1']);
        self::ensureLearnerGamification($ids['aba_student_1']);
        // PA41: a parent may have more than one linked child. Learner 2 (a
        // shipped student in a different class/school) is linked as the parent's
        // secondary child and given its own measured profile, so the front-end
        // child switcher exercises real per-child data + §8 scoping.
        self::ensureSecondChildFixtures();
        // PA42: the parent's "add a child" queue gets one genuine pending
        // request (cohort learner ABA-2026-101, not linked to anyone) so the
        // request → admin-approve → child-appears flow is demoable end to end.
        self::ensureDemoLinkRequest($ids['aba_parent_1'], 'ABA-2026-101', 'mother');
        // PA43: a SECOND, never-linked cohort learner stays pending so the new
        // administrator review screen always has a live item to approve, even
        // after the first demo request has already been decided.
        self::ensureDemoLinkRequest($ids['aba_parent_1'], 'ABA-2026-106', 'father');

        // Demo identities now exist => the headless front end may authenticate
        // with app passwords over plain HTTP (see the plugin bootstrap filter).
        update_option('aba_headless_auth', '1', false);
        update_option('aba_demo_users_seeded', self::VERSION, false);
    }

    private static function ensureRoles(): void {
        foreach (self::ROLES as $name => $spec) {
            if (get_role($name)) {
                continue;
            }
            \add_role($name, $spec['name'], array_fill_keys($spec['caps'], true));
        }
    }

    /** @return array<string,int> login => user id */
    private static function ensureUsers(): array {
        $byLogin = [];
        foreach (self::USERS as $u) {
            $existing = get_user_by('login', $u['login']);
            if ($existing) {
                // Align the shipped demo users with the ABA identity model
                // (known password + correct role) without creating duplicates.
                wp_update_user([
                    'ID' => $existing->ID,
                    'user_pass' => self::DEMO_PASSWORD,
                    'display_name' => $u['display'],
                    'role' => $u['role'],
                ]);
                $byLogin[$u['login']] = (int) $existing->ID;
                continue;
            }
            $newId = wp_insert_user([
                'user_login' => $u['login'],
                'user_pass' => self::DEMO_PASSWORD,
                'user_email' => $u['email'],
                'display_name' => $u['display'],
                'role' => $u['role'],
            ]);
            if (!is_wp_error($newId)) {
                $byLogin[$u['login']] = (int) $newId;
            }
        }
        return $byLogin;
    }

    /** Teacher meta-scope so PA23/PA24 (school/class roster) resolve for them. */
    private static function ensureStaffScope(int $teacherUserId): void {
        if ($teacherUserId <= 0) {
            return;
        }
        update_user_meta($teacherUserId, 'aba_school_id', 10);
        update_user_meta($teacherUserId, 'aba_class_id', 402);
    }

    private static function ensureParentLink(int $parentUserId): void {
        global $wpdb;
        if ($parentUserId <= 0) {
            return;
        }
        $table = $wpdb->prefix . 'aba_parent_student';
        // PA41: primary learner 1 plus a secondary learner 2, so the parent
        // switcher has more than one child to choose between. Keyed by the
        // (parent, student) pair, so a re-run never duplicates the link.
        $links = [
            [ 'student_id' => 1, 'relationship' => 'parent', 'is_primary' => 1 ],
            [ 'student_id' => 2, 'relationship' => 'parent', 'is_primary' => 0 ],
        ];
        foreach ($links as $link) {
            $found = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE parent_user_id = %d AND student_id = %d",
                $parentUserId,
                $link['student_id']
            ));
            if ($found === 0) {
                $wpdb->insert($table, [
                    'parent_user_id' => $parentUserId,
                    'student_id' => $link['student_id'],
                    'relationship' => $link['relationship'],
                    'is_primary' => $link['is_primary'],
                    'status' => 'active',
                ], ['%d', '%d', '%s', '%d', '%s']);
            }
        }
        update_user_meta($parentUserId, 'aba_parent', 1);
    }

    /**
     * PA41 — give the secondary learner (student 2) its own measured profile so
     * switching children in the parent app renders distinct, real data. Scores
     * and snapshots are upsert/insert-or-skip, so this is idempotent and touches
     * only learner 2 (class 501 — it never pollutes learner 1's class-402
     * k-anonymity cohort). No XP ledger rows are written: the activity feed for
     * this child is deliberately empty, which the UI renders as an honest
     * empty-state rather than fabricated history (§12).
     */
    private static function ensureSecondChildFixtures(): void {
        $studentId = 2;
        $scores = new \ABA\Core\Repositories\SkillScoreRepository();
        // A different shape from learner 1 so the parent sees a real contrast.
        $finalists = [ 'SA' => 74, 'ER' => 62, 'SM' => 55, 'CO' => 68, 'CE' => 80, 'PD' => 58 ];
        foreach ($finalists as $code => $score) {
            $scores->upsertScore($studentId, (string) $code, 'core_competency', (float) $score, self::levelFor((float) $score), 0, 'student', 'ABA-DEMO-1.0');
        }

        $snaps = new \ABA\Core\Repositories\SkillSnapshotRepository();
        $aug = [];
        foreach ($finalists as $code => $score) {
            $aug[] = [ 'skillCode' => $code, 'score' => round($score * 0.86, 1), 'level' => self::levelFor($score * 0.86) ];
        }
        $oct = [];
        foreach ($finalists as $code => $score) {
            $oct[] = [ 'skillCode' => $code, 'score' => (float) $score, 'level' => self::levelFor((float) $score) ];
        }
        self::seedSnapshot($snaps, $studentId, self::SNAP_SESSION_BASE + 921, $aug, self::SNAP_AUG);
        self::seedSnapshot($snaps, $studentId, self::SNAP_SESSION_BASE + 922, $oct, self::SNAP_OCT);
    }

    /**
     * PA42/PA43 — one genuine pending link request for the demo parent, pointing
     * at a cohort learner (an unlinked student). Insert-if-missing by the
     * (parent, student) unique key; if the demo already approved it, the row is
     * left in its real reviewed state — the seeder never rewinds history.
     */
    private static function ensureDemoLinkRequest(int $parentUserId, string $code, string $relationship): void {
        global $wpdb;
        if ($parentUserId <= 0) {
            return;
        }
        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT id, student_code FROM {$wpdb->prefix}aba_students WHERE student_code = %s AND status = 'active'",
            $code
        ), ARRAY_A);
        if ($student === null) {
            return;
        }
        $table = $wpdb->prefix . 'aba_parent_link_requests';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE parent_user_id = %d AND student_id = %d",
            $parentUserId,
            (int) $student['id']
        ));
        if ($exists !== null) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($table, [
            'parent_user_id' => $parentUserId,
            'student_id'     => (int) $student['id'],
            'student_code'   => $student['student_code'],
            'relationship'   => $relationship,
            'status'         => 'pending',
            'created_at'     => $now,
            'updated_at'     => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s']);
    }

    /**
     * Five+ distinct classmates with current scores and two dated snapshots so
     * PA31/PA32 publish for learner 1 under the PA33 floor. Each gets a
     * data-only wp_user (random password, subscriber) because students.user_id
     * is UNIQUE; keyed by that user, so the student row is insert-or-read.
     */
    private static function ensureCohort(): void {
        global $wpdb;
        $students = $wpdb->prefix . 'aba_students';
        $scores = new \ABA\Core\Repositories\SkillScoreRepository();
        $snaps = new \ABA\Core\Repositories\SkillSnapshotRepository();
        $i = 0;

        foreach (self::COHORT as $row) {
            $login = 'aba_cohort_' . substr($row['code'], -3);
            $userId = 0;
            $existing = get_user_by('login', $login);
            if ($existing) {
                $userId = (int) $existing->ID;
            } else {
                $newId = wp_insert_user([
                    'user_login' => $login,
                    'user_pass' => wp_generate_password(32, true, true),
                    'user_email' => $login . '@aba.local',
                    'display_name' => $row['last'] . ' ' . $row['first'],
                    'role' => 'subscriber',
                ]);
                if (is_wp_error($newId)) {
                    continue;
                }
                $userId = (int) $newId;
            }
            $sid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$students} WHERE user_id = %d", $userId));
            if ($sid === 0) {
                $wpdb->insert($students, [
                    'user_id' => $userId,
                    'school_id' => 10,
                    'class_id' => 402,
                    'student_code' => $row['code'],
                    'first_name' => $row['first'],
                    'last_name' => $row['last'],
                    'grade_level' => 4,
                    'grade_group' => '4_6',
                    'status' => 'active',
                ], ['%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']);
                $sid = (int) $wpdb->insert_id;
            }
            if ($sid <= 0) {
                continue;
            }
            // The demo cohort must always stand for a full, active class so the
            // k-anonymity benchmark (§8) and its sample counts stay stable. A
            // prior E2E pass may have archived one of these learners through the
            // roster screen; reassert the seeded identities' active state here.
            $wpdb->update($students, ['status' => 'active'], ['id' => $sid], ['%s'], ['%d']);
            foreach ($row['scores'] as $code => $score) {
                $scores->upsertScore($sid, (string) $code, 'core_competency', (float) $score, self::levelFor((float) $score), 0, 'student', 'ABA-DEMO-1.0');
            }
            // Aug is the "before" measurement (≈85% of current), Oct the current.
            self::seedSnapshot($snaps, $sid, self::SNAP_SESSION_BASE + $i * 10 + 1, self::scaleScores($row['scores'], 0.85), self::SNAP_AUG);
            self::seedSnapshot($snaps, $sid, self::SNAP_SESSION_BASE + $i * 10 + 2, self::scaleScores($row['scores'], 1.0), self::SNAP_OCT);
            $i++;
        }
    }

    /**
     * Make learner 1 a realistic *demo* learner instead of the all-100 artifact
     * of a single completed assessment: a growth gap in ER/CE (drives the §13
     * recommendations + PA34 priority banner) and their own two-point history so
     * the parent trend chart is not a single dot.
     */
    private static function ensureLearnerFixtures(int $studentUserId): void {
        global $wpdb;
        $studentId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}aba_students WHERE user_id = %d", $studentUserId));
        if ($studentId <= 0) {
            $studentId = 1; // fall back to the shipped demo learner
        }
        $scores = new \ABA\Core\Repositories\SkillScoreRepository();

        $finalists = [ 'SA' => 82, 'ER' => 55, 'SM' => 68, 'CO' => 80, 'CE' => 45, 'PD' => 86 ];
        foreach ($finalists as $code => $score) {
            $scores->upsertScore($studentId, (string) $code, 'core_competency', (float) $score, self::levelFor((float) $score), 0, 'student', 'ABA-DEMO-1.0');
        }

        // Own trend: Aug was ~10 points lower on every measured code.
        $snaps = new \ABA\Core\Repositories\SkillSnapshotRepository();
        $aug = [];
        foreach ($finalists as $code => $score) {
            $aug[] = [ 'skillCode' => $code, 'score' => round($score * 0.82, 1), 'level' => self::levelFor($score * 0.82) ];
        }
        $oct = [];
        foreach ($finalists as $code => $score) {
            $oct[] = [ 'skillCode' => $code, 'score' => (float) $score, 'level' => self::levelFor((float) $score) ];
        }
        self::seedSnapshot($snaps, $studentId, self::SNAP_SESSION_BASE + 901, $aug, self::SNAP_AUG);
        self::seedSnapshot($snaps, $studentId, self::SNAP_SESSION_BASE + 902, $oct, self::SNAP_OCT);

        // Cached total mirrors the ledger (§12 stays the source of truth; this is
        // only the denormalized copy the profile header reads).
        $xpRepo = new \ABA\Core\Repositories\XpTransactionRepository();
        $xpRepo->recordTransaction($studentId, 100, 'mission', 'demo-seed', 'Nhiệm vụ demo (seeder)', 'aba-demo-xp-1');
        $wpdb->update($wpdb->prefix . 'aba_students', ['total_xp' => $xpRepo->getTotalXp($studentId)], ['id' => $studentId], ['%d'], ['%d']);
    }

    /**
     * PA38 — light up Nhiệm vụ / Thành tích / Hồ sơ on the real backend by
     * seeding only the ground-truth tables the GamificationService derives from
     * (AGENTS.md §12: ledger + activity calendar are the source of truth, so we
     * never write a totals/badges counter directly).
     *
     * Freshness rule: only "today" surfaces (daily missions, live streak) use
     * relative dates computed at seed time; everything that plays the role of
     * history is backdated to fixed October dates. Re-runs stay idempotent via
     * the unique keys (student+node, student+date, student+mission+period) and
     * the XP ledger's idempotency_key.
     */
    private static function ensureLearnerGamification(int $studentUserId): void {
        global $wpdb;
        $studentId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}aba_students WHERE user_id = %d", $studentUserId));
        if ($studentId <= 0) {
            $studentId = 1;
        }

        $progress = $wpdb->prefix . 'aba_lesson_progress';
        $xp = $wpdb->prefix . 'aba_xp_transactions';
        $today = gmdate('Y-m-d');

        // 1) Lesson progress — five backdated nodes (CE-2/3, ER-1/2, SM-1)…
        $historical = array_diff(self::TRAIL_NODES, [ 'unit-ce-node-1' ]);
        $i = 0;
        foreach ($historical as $nodeId) {
            self::seedProgressRow($progress, $studentId, $nodeId, self::TRAIL_XP[$i], self::TRAIL_DATES[$i]);
            $i++;
        }
        // …plus unit-ce-node-1 stamped "today" so the daily lesson mission
        // starts with a live progress bar on a fresh seed. Re-stamped on every
        // run (see touchProgressToday) so a midnight rollover does not strand
        // mission 1 on yesterday's completed_at.
        self::touchProgressToday($progress, $studentId, 'unit-ce-node-1', 30);

        // 2) XP ledger — one row per completed node with a stable idempotency
        // key, so a restart never double-pays (§12). The "today" row is what
        // mission 2 (50 XP/ngày) reads via getSumSince.
        self::seedXpRow($xp, $studentId, 30, 'lesson', 'unit-er-node-1', 'Hoàn thành bài học unit-er-node-1', 'aba-demo-xp-t1', '2026-10-05 02:00:00');
        self::seedXpRow($xp, $studentId, 60, 'lesson', 'unit-er-node-2', 'Hoàn thành bài học unit-er-node-2', 'aba-demo-xp-t2', '2026-10-14 02:30:00');
        self::seedXpRow($xp, $studentId, 30, 'lesson', 'unit-sm-node-1', 'Hoàn thành bài học unit-sm-node-1', 'aba-demo-xp-t3', '2026-10-22 03:00:00');
        self::seedXpRow($xp, $studentId, 50, 'assessment', null, 'Hoàn thành bài đánh giá 360°', 'aba-demo-xp-assess', '2026-09-25 04:00:00');
        // Today's ledger row carries the fresh lesson + the daily XP mission
        // (30 + 30 = 60 ≥ 50). Keyed by the date, so a re-seed never doubles it.
        self::seedXpRow($xp, $studentId, 30, 'lesson', 'unit-ce-node-1', 'Hoàn thành bài học unit-ce-node-1', 'aba-demo-xp-today:' . $today, current_time('mysql', 1));
        self::seedXpRow($xp, $studentId, 30, 'mission', 'daily-starter', 'Thưởng nhiệm vụ hàng ngày (demo)', 'aba-demo-xp-mission-today:' . $today, current_time('mysql', 1));

        // 3) Streak calendar — a fresh 3-day run (today, -1, -2) so the flame +
        // STREAK_3 badge derive, while STREAK_7 stays a visible locked goal.
        $streaks = new \ABA\Core\Repositories\StreakRepository();
        $streaks->recordActivity($studentId, $today);
        $streaks->recordActivity($studentId, gmdate('Y-m-d', strtotime('-1 day')));
        $streaks->recordActivity($studentId, gmdate('Y-m-d', strtotime('-2 day')));
        $streaks->recordActivity($studentId, '2026-09-10');
        $streaks->recordActivity($studentId, '2026-09-11');
        // /students/me reads the denormalized streak columns; keep them as a
        // derived cache of the calendar (§12), otherwise the stale v1 fixture
        // values (7/14) contradict the ledger-derived /gamification numbers.
        $wpdb->update(
            $wpdb->prefix . 'aba_students',
            ['current_streak' => $streaks->getCurrentStreak($studentId), 'longest_streak' => $streaks->getLongestStreak($studentId)],
            ['id' => $studentId],
            ['%d', '%d'],
            ['%d']
        );

        // 4) Mission history — durable completions so the achievements timeline
        // has mission entries (claim-key "mission:{id}:{student}:{period}" is
        // NOT seeded, so the learner can still claim today's live missions).
        $missions = new \ABA\Core\Repositories\MissionProgressRepository();
        $missions->upsert($studentId, 1, '2026-10-20', 1, 1, true, '2026-10-20 02:10:00');
        $missions->upsert($studentId, 3, '2026-10-19', 3, 3, true, '2026-10-24 03:00:00');

        // Cached total mirrors the ledger (§12) after all demo writes.
        $xpRepo = new \ABA\Core\Repositories\XpTransactionRepository();
        $wpdb->update($wpdb->prefix . 'aba_students', ['total_xp' => $xpRepo->getTotalXp($studentId)], ['id' => $studentId], ['%d'], ['%d']);
    }

    /** Insert-or-skip one lesson_progress row on the (student, node) unique key. */
    private static function seedProgressRow(string $table, int $studentId, string $nodeId, int $xpAwarded, string $completedAt): void {
        global $wpdb;
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE student_id = %d AND node_id = %s",
            $studentId, $nodeId
        ));
        if ($found > 0) {
            return;
        }
        $wpdb->insert($table, [
            'student_id' => $studentId,
            'node_id' => $nodeId,
            'status' => 'completed',
            'score' => 100.0,
            'xp_awarded' => $xpAwarded,
            'completed_at' => $completedAt,
        ], ['%d', '%s', '%s', '%f', '%d', '%s']);
    }

    /**
     * Insert-or-refresh the "today" lesson node so the daily lesson mission is
     * genuinely complete on the current date. Unlike seedProgressRow this
     * UPDATEs an existing row's completed_at to now (never adds a row), so the
     * progress-row idempotency count is unchanged across midnight rollovers.
     */
    private static function touchProgressToday(string $table, int $studentId, string $nodeId, int $xpAwarded): void {
        global $wpdb;
        $now = current_time('mysql', 1);
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE student_id = %d AND node_id = %s",
            $studentId, $nodeId
        ));
        if ($found > 0) {
            $wpdb->update($table, ['status' => 'completed', 'completed_at' => $now], [
                'student_id' => $studentId,
                'node_id' => $nodeId,
            ], ['%s', '%s'], ['%d', '%s']);
            return;
        }
        $wpdb->insert($table, [
            'student_id' => $studentId,
            'node_id' => $nodeId,
            'status' => 'completed',
            'score' => 100.0,
            'xp_awarded' => $xpAwarded,
            'completed_at' => $now,
        ], ['%d', '%s', '%s', '%f', '%d', '%s']);
    }

    /** Insert one backdated ledger row guarded by its idempotency key (§12). */
    private static function seedXpRow(string $table, int $studentId, int $amount, string $sourceType, ?string $sourceId, string $description, string $key, string $createdAt): void {
        global $wpdb;
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE idempotency_key = %s",
            $key
        ));
        if ($found > 0) {
            return;
        }
        // balance_after is a display mirror rebuilt from the ledger; the cached
        // students.total_xp gets re-synced at the end of the seed.
        $running = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE student_id = %d",
            $studentId
        ));
        $wpdb->insert($table, [
            'student_id' => $studentId,
            'amount' => $amount,
            'balance_after' => max(0, $running + $amount),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'description' => $description,
            'idempotency_key' => $key,
            'created_at' => $createdAt,
        ], ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s']);
    }

    /** One snapshot per (student, date) — repeat seeds are a no-op. */
    private static function seedSnapshot(\ABA\Core\Repositories\SkillSnapshotRepository $snaps, int $studentId, int $sessionId, array $scores, string $date): void {
        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}aba_skill_snapshots WHERE student_id = %d AND snapshot_date = %s",
            $studentId,
            $date
        ));
        if ($exists === 0) {
            $snaps->create($studentId, $sessionId, $scores, [], $date);
        }
    }

    /** @param array<string,int> $scores */
    private static function scaleScores(array $scores, float $factor): array {
        $out = [];
        foreach ($scores as $code => $score) {
            $v = round((float) $score * $factor, 1);
            $out[] = [ 'skillCode' => (string) $code, 'score' => $v, 'level' => self::levelFor($v) ];
        }
        return $out;
    }

    /** Same bands as ScoringEngine::levelFor — kept in sync intentionally. */
    private static function levelFor(float $score): string {
        if ($score >= 90) {
            return 'advanced';
        }
        if ($score >= 70) {
            return 'proficient';
        }
        if ($score >= 50) {
            return 'developing';
        }
        return 'beginning';
    }
}
