<?php
/**
 * Suite: Gamification derived from ground-truth ledgers (PA11).
 *
 * Proves AGENTS.md §12: the XP ledger is the source of truth (SUM over
 * xp_transactions, cached total only a mirror), the streak is derived from the
 * activity calendar (§11 longitudinal), and missions/badges are deterministic
 * functions of real state. Also proves reward claims are idempotent, so a client
 * cannot farm XP by double-tapping (§31 / invariant #4).
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\XpTransactionRepository;
use ABA\Core\Repositories\LessonProgressRepository;
use ABA\Core\Repositories\StreakRepository;
use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Repositories\StudentBadgeRepository;
use ABA\Core\Repositories\MissionProgressRepository;
use ABA\Core\Services\GamificationService;

return function (AbaTestKit $kit): void {
    $kit->section('GamificationTest — ledger-derived XP, streak, missions (PA11)');

    $studentId = $kit->makeStudent('4_6');
    $staleId = $kit->makeStudent('4_6');

    $xp = new XpTransactionRepository();
    $lesson = new LessonProgressRepository();
    $streak = new StreakRepository();
    $students = new StudentRepository();
    $badgeRepo = new StudentBadgeRepository();
    $missionRepo = new MissionProgressRepository();
    $service = new GamificationService();

    try {
        // ---- XP ledger is the source of truth (§12) -----------------------
        $xp->recordTransaction($studentId, 20, 'lesson', 'n1', 'lesson', null);
        $xp->recordTransaction($studentId, 30, 'mission', 'm1', 'mission', null);
        $xp->recordTransaction($studentId, 50, 'assessment', '1', 'assessment', 'assessment:1');

        $kit->checkSame('ledger SUM = 100 XP', 100, $xp->getTotalXp($studentId));
        $row = $students->findById($studentId);
        $kit->checkSame('cached total mirrors the ledger', 100, (int)($row['total_xp'] ?? -1));

        $breakdown = $xp->getBreakdownBySource($studentId);
        $kit->checkSame('lesson XP grouped', 20, $breakdown['lesson']['total'] ?? null);
        $kit->checkSame('assessment XP grouped', 50, $breakdown['assessment']['total'] ?? null);

        // Idempotency: the same key must not double-award (§31).
        $xp->recordTransaction($studentId, 50, 'assessment', '1', 'dupe', 'assessment:1');
        $kit->checkSame('duplicate idempotency key does not re-credit', 100, $xp->getTotalXp($studentId));
        $kit->checkSame('assessment still counted once', 1, $xp->countBySource($studentId, 'assessment'));

        // ---- Streak derived from the activity calendar -------------------
        $streak->recordActivity($studentId, gmdate('Y-m-d'));
        $streak->recordActivity($studentId, gmdate('Y-m-d', strtotime('-1 day')));
        $streak->recordActivity($studentId, gmdate('Y-m-d', strtotime('-2 day')));
        $kit->checkSame('three consecutive active days => streak 3', 3, $streak->getCurrentStreak($studentId));
        $kit->check('same-day activity is idempotent', $streak->recordActivity($studentId, gmdate('Y-m-d')));
        $kit->checkSame('repeat today does not inflate the streak', 3, $streak->getCurrentStreak($studentId));

        // A lone old activity means the current streak has broken to 0, even
        // though the longest-ever run was 1.
        $streak->recordActivity($staleId, gmdate('Y-m-d', strtotime('-10 day')));
        $kit->checkSame('stale last activity => current streak 0', 0, $streak->getCurrentStreak($staleId));
        $kit->checkSame('but the longest run is still remembered', 1, $streak->getLongestStreak($staleId));

        // ---- Missions are deterministic from real progress ---------------
        $lesson->recordProgress($studentId, 'n1', 'completed', 100.0, 30);
        $missions = $service->buildMissions($studentId);
        $byId = [];
        foreach ($missions as $m) {
            $byId[$m['id']] = $m;
        }
        $kit->checkSame('daily-lesson mission is complete (>=1 today)', true, $byId[1]['isCompleted'] ?? null);
        $kit->checkSame('daily-XP mission is complete (>=50 today)', true, $byId[2]['isCompleted'] ?? null);
        $kit->check('progress is capped at target', ($byId[2]['progress'] ?? 999) <= ($byId[2]['target'] ?? 0));

        // ---- Claim pays through the ledger, idempotently -----------------
        $before = $xp->getTotalXp($studentId);
        $claim = $service->claimMission($studentId, 1);
        $kit->checkSame('claim succeeds', true, $claim['success'] ?? null);
        $kit->checkSame('claim credits 25 XP once', $before + 25, $xp->getTotalXp($studentId));
        $service->claimMission($studentId, 1); // same period, same key
        $kit->checkSame('re-claim cannot farm XP', $before + 25, $xp->getTotalXp($studentId));

        // An incomplete mission must not pay out.
        $incomplete = $service->claimMission($staleId, 3);
        $kit->checkSame('unmet mission is not claimable', false, $incomplete['success'] ?? null);

        // ---- Badges unlock from milestones (§12) -------------------------
        $badges = [];
        foreach ($service->buildBadges($studentId) as $b) {
            $badges[$b['code']] = $b['isUnlocked'];
        }
        $kit->checkSame('FIRST_ASSESSMENT unlocked', true, $badges['FIRST_ASSESSMENT'] ?? null);
        $kit->checkSame('STREAK_3 unlocked (streak 3)', true, $badges['STREAK_3'] ?? null);
        $kit->checkSame('STREAK_7 still locked', false, $badges['STREAK_7'] ?? null);
        $kit->checkSame('XP_1000 still locked (125 XP)', false, $badges['XP_1000'] ?? null);

        // ---- PA15: earned badges become a durable, stable history (§11) ---
        $kit->check('FIRST_ASSESSMENT persisted', $badgeRepo->has($studentId, 'FIRST_ASSESSMENT'));
        $kit->check('STREAK_3 persisted', $badgeRepo->has($studentId, 'STREAK_3'));
        $kit->check('STREAK_7 not persisted (still locked)', !$badgeRepo->has($studentId, 'STREAK_7'));

        // The first-earned moment never moves: a later award cannot rewrite it.
        $badgeRepo->award($staleId, 'XP_1000', '2020-01-01 00:00:00');
        $badgeRepo->award($staleId, 'XP_1000', '2025-06-06 06:06:06');
        $earnedStale = $badgeRepo->getAwardedMap($staleId);
        $kit->checkSame('badge award is idempotent on the first-earned date', '2020-01-01 00:00:00', $earnedStale['XP_1000'] ?? null);

        // unlockedAt is read back from the store, so it is byte-stable across calls.
        $un1 = $un2 = null;
        foreach ($service->buildBadges($studentId) as $b) { if ($b['code'] === 'FIRST_ASSESSMENT') { $un1 = $b['unlockedAt'] ?? null; } }
        foreach ($service->buildBadges($studentId) as $b) { if ($b['code'] === 'FIRST_ASSESSMENT') { $un2 = $b['unlockedAt'] ?? null; } }
        $kit->checkSame('unlockedAt is stable across repeated reads', $un1, $un2);

        // ---- PA15: a claim leaves a durable mission-progress record -------
        $today = gmdate('Y-m-d');
        $kit->check('mission 1 recorded completed for today', $missionRepo->isCompleted($studentId, 1, $today));
        $kit->check('completed-mission count reflects the claim', $missionRepo->countCompletedSince($studentId, $today . ' 00:00:00') >= 1);
        $kit->check('mission history is queryable for the learner', count($missionRepo->getHistory($studentId)) >= 1);

        // ---- PA18: achievements are a dated longitudinal timeline (§11) ----
        $ach = $service->buildAchievements($studentId);
        $kit->checkSame('two badges unlocked on the timeline', 2, $ach['unlockedCount'] ?? null);
        $kit->checkSame('full badge catalogue counted', 4, $ach['totalCount'] ?? null);
        $kit->check('timeline merges badges + completed missions', is_array($ach['timeline'] ?? null) && count($ach['timeline']) >= 3);
        $allDated = true;
        foreach ($ach['timeline'] as $ev) { if (empty($ev['earnedAt'])) { $allDated = false; } }
        $kit->check('every milestone records when it was earned', $allDated);
        $hasMission = false;
        foreach ($ach['timeline'] as $ev) { if (($ev['kind'] ?? '') === 'mission' && (int)($ev['missionId'] ?? 0) === 1) { $hasMission = true; } }
        $kit->check('the claimed mission appears on the timeline', $hasMission);
        $kit->check('achievements projection is deterministic', wp_json_encode($service->buildAchievements($studentId)) === wp_json_encode($service->buildAchievements($studentId)));

        // ---- buildMe composes the ledger + streak ------------------------
        $me = $service->buildMe($studentId);
        $kit->checkSame('me.totalXp from ledger', 125, $me['totalXp'] ?? null);
        $kit->checkSame('me.currentStreak', 3, $me['currentStreak'] ?? null);
        $kit->checkSame('me.level (intdiv 200)', 1, $me['level'] ?? null);

        // Determinism: identical state => byte-identical projection.
        $kit->check('gamification projection is deterministic', wp_json_encode($service->buildMe($studentId)) === wp_json_encode($service->buildMe($studentId)));
    } finally {
        $kit->cleanup($studentId);
        $kit->cleanup($staleId);
    }
};
