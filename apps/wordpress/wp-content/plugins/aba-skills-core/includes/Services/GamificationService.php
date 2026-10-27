<?php
/**
 * Gamification assembled from ground-truth ledgers, never from denormalized
 * counters (AGENTS.md §12). XP comes from SUM(xp_transactions); the streak is
 * derived from the activity calendar (wp_aba_streaks); missions and badges are
 * deterministic functions of that state so the same data always yields the same
 * rewards and no client can fabricate progress.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Services;

use ABA\Core\Repositories\XpTransactionRepository;
use ABA\Core\Repositories\LessonProgressRepository;
use ABA\Core\Repositories\StreakRepository;
use ABA\Core\Repositories\StudentBadgeRepository;
use ABA\Core\Repositories\MissionProgressRepository;
use ABA\Core\Repositories\PowerUpRepository;

if (!defined('ABSPATH')) {
    exit;
}

class GamificationService {
    /** Cached-total mirror is 200 XP per level (matches StudentRepository::toApiShape). */
    private const XP_PER_LEVEL = 200;

    private XpTransactionRepository $xpRepo;
    private LessonProgressRepository $lessonRepo;
    private StreakRepository $streakRepo;
    private StudentBadgeRepository $badgeRepo;
    private MissionProgressRepository $missionRepo;
    private PowerUpRepository $powerRepo;

    public function __construct() {
        $this->xpRepo = new XpTransactionRepository();
        $this->lessonRepo = new LessonProgressRepository();
        $this->streakRepo = new StreakRepository();
        $this->badgeRepo = new StudentBadgeRepository();
        $this->missionRepo = new MissionProgressRepository();
        $this->powerRepo = new PowerUpRepository();
    }

    /** The learner's derived gamification state for the header / achievements. */
    public function buildMe(int $studentId): array {
        $totalXp = $this->xpRepo->getTotalXp($studentId);
        return [
            'studentId' => $studentId,
            'totalXp' => $totalXp,
            'xpBySource' => $this->xpRepo->getBreakdownBySource($studentId),
            'currentStreak' => $this->streakRepo->getCurrentStreak($studentId),
            'longestStreak' => $this->streakRepo->getLongestStreak($studentId),
            'assessmentsCompleted' => $this->xpRepo->countBySource($studentId, 'assessment'),
            'level' => intdiv($totalXp, self::XP_PER_LEVEL) + 1,
            // PA22: banked streak-freeze credits, read straight from the ledger
            // (pure read — buildMe must stay byte-deterministic, no side effects).
            'streakFreezes' => $this->powerRepo->balance($studentId, PowerUpRepository::FREEZE),
        ];
    }

    /**
     * Deterministic, data-driven missions. Mirrors the Mission contract shape.
     * @return array<int, array>
     */
    public function buildMissions(int $studentId): array {
        $today = gmdate('Y-m-d');
        $sinceToday = $today . ' 00:00:00';
        $sinceWeek = gmdate('Y-m-d H:i:s', strtotime('-7 days'));

        $lessonsToday = $this->lessonRepo->countCompletedOnDate($studentId, $today);
        $xpToday = $this->xpRepo->getSumSince($studentId, $sinceToday);
        $lessonsWeek = $this->lessonRepo->countCompletedSince($studentId, $sinceWeek);
        $assessments = $this->xpRepo->countBySource($studentId, 'assessment');

        $dayEnd = gmdate('Y-m-d', strtotime('+1 day')) . 'T00:00:00Z';
        $weekEnd = gmdate('Y-m-d', strtotime('next monday')) . 'T00:00:00Z';

        $defs = [
            ['id' => 1, 'title' => 'Hoàn thành 1 bài học hôm nay', 'description' => 'Bất kỳ bài học nào trên lộ trình học tập', 'xpReward' => 25, 'type' => 'daily', 'target' => 1, 'progress' => $lessonsToday, 'expiresAt' => $dayEnd],
            ['id' => 2, 'title' => 'Kiếm 50 XP trong ngày', 'description' => 'Tổng XP tích lũy từ bài học, nhiệm vụ và đánh giá', 'xpReward' => 30, 'type' => 'daily', 'target' => 50, 'progress' => $xpToday, 'expiresAt' => $dayEnd],
            ['id' => 3, 'title' => 'Hoàn thành 3 bài học trong tuần', 'description' => 'Duy trì nhịp học đều đặn cả tuần', 'xpReward' => 40, 'type' => 'weekly', 'target' => 3, 'progress' => $lessonsWeek, 'expiresAt' => $weekEnd],
            ['id' => 4, 'title' => 'Hoàn thành 1 bài đánh giá năng lực', 'description' => 'Đánh giá 360° để cập nhật hồ sơ năng lực', 'xpReward' => 50, 'type' => 'weekly', 'target' => 1, 'progress' => $assessments, 'expiresAt' => $weekEnd],
        ];

        $missions = [];
        foreach ($defs as $d) {
            $progress = min($d['progress'], $d['target']);
            $missions[] = [
                'id' => $d['id'],
                'title' => $d['title'],
                'description' => $d['description'],
                'xpReward' => $d['xpReward'],
                'progress' => $progress,
                'target' => $d['target'],
                'isCompleted' => $progress >= $d['target'],
                'type' => $d['type'],
                'expiresAt' => $d['expiresAt'],
            ];
        }
        return $missions;
    }

    /**
     * Deterministic badge catalogue unlocked by real milestones.
     * @return array<int, array>
     */
    public function buildBadges(int $studentId): array {
        $me = $this->buildMe($studentId);

        $defs = [
            ['id' => 1, 'code' => 'FIRST_ASSESSMENT', 'name' => 'Người Dám Thử', 'description' => 'Hoàn thành bài đánh giá năng lực đầu tiên', 'category' => 'assessment', 'icon' => 'award', 'on' => $me['assessmentsCompleted'] >= 1],
            ['id' => 2, 'code' => 'STREAK_3', 'name' => 'Kỷ Luật Nhỏ', 'description' => 'Duy trì chuỗi học tập 3 ngày liên tiếp', 'category' => 'streak', 'icon' => 'flame', 'on' => $me['currentStreak'] >= 3],
            ['id' => 3, 'code' => 'STREAK_7', 'name' => 'Chiến Binh Bền Bỉ', 'description' => 'Duy trì chuỗi học tập 7 ngày liên tiếp', 'category' => 'streak', 'icon' => 'flame', 'on' => $me['currentStreak'] >= 7],
            ['id' => 4, 'code' => 'XP_1000', 'name' => 'Bậc Thầy Tích Lũy', 'description' => 'Vượt mốc 1000 điểm kinh nghiệm XP', 'category' => 'xp', 'icon' => 'zap', 'on' => $me['totalXp'] >= 1000],
        ];

        // Persist newly-reached milestones so the first-earned moment becomes a
        // durable history (§11). Award is idempotent: an already-earned badge
        // keeps its original date, so repeated reads never rewrite it.
        foreach ($defs as $d) {
            if ($d['on']) {
                $this->badgeRepo->award($studentId, $d['code']);
            }
        }
        // PA22: the same persist-milestones-on-read path banks a streak freeze for
        // every full 7-day run reached. Idempotent, so repeated reads mint only one.
        $this->syncStreakFreezes($studentId, $me['currentStreak']);
        $earned = $this->badgeRepo->getAwardedMap($studentId);

        $badges = [];
        foreach ($defs as $d) {
            $badge = [
                'id' => $d['id'],
                'code' => $d['code'],
                'name' => $d['name'],
                'description' => $d['description'],
                'category' => $d['category'],
                'icon' => $d['icon'],
                'isUnlocked' => $d['on'],
            ];
            if ($d['on']) {
                $badge['unlockedAt'] = $this->toIso($earned[$d['code']] ?? current_time('mysql', 1));
            }
            $badges[] = $badge;
        }
        return $badges;
    }

    /** GMT datetime from the DB => the contract's ISO-8601 (Z) instant. */
    private function toIso(string $mysqlDatetime): string {
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : $mysqlDatetime;
    }

    /**
     * PA22 — bank one freeze for each full 7-day streak milestone reached (§13
     * deterministic). The grant reason carries the milestone number, so a streak
     * that lingers anywhere in [7,13] grants exactly once and later reads are
     * no-ops; crossing 14 banks a second. Never revokes on a later break — the
     * ledger history is the point of truth (§12).
     */
    private function syncStreakFreezes(int $studentId, int $currentStreak): void {
        if ($currentStreak < 7) {
            return;
        }
        $milestone = intdiv($currentStreak, 7);
        $this->powerRepo->grant($studentId, PowerUpRepository::FREEZE, 1, "streak_milestone:{$milestone}");
    }

    /**
     * PA22 — spend one banked freeze to bridge a single missed day and keep the
     * run alive. Deterministic and safe: the bridge inserts only a freeze-flagged
     * streak row (no XP is minted, §12), the spend is idempotent per gap date so a
     * double-tap cannot burn two credits on one hole, and nothing is consumed when
     * there is no repairable gap or no credit. Returns the resulting state.
     *
     * @return array{applied:bool,status:string,gapDate:?string,currentStreak:int,freezes:int}
     */
    public function useStreakFreeze(int $studentId): array {
        $gap = $this->streakRepo->detectRepairableGap($studentId);
        if ($gap === null) {
            return [
                'applied' => false,
                'status' => 'no_gap',
                'gapDate' => null,
                'currentStreak' => $this->streakRepo->getCurrentStreak($studentId),
                'freezes' => $this->powerRepo->balance($studentId, PowerUpRepository::FREEZE),
            ];
        }

        $balance = $this->powerRepo->balance($studentId, PowerUpRepository::FREEZE);
        if ($balance < 1) {
            return [
                'applied' => false,
                'status' => 'no_freeze',
                'gapDate' => $gap,
                'currentStreak' => $this->streakRepo->getCurrentStreak($studentId),
                'freezes' => $balance,
            ];
        }

        // Burn keyed to the gap date, so repairing the same hole twice spends once.
        $this->powerRepo->grant($studentId, PowerUpRepository::FREEZE, -1, "use:{$gap}");
        $this->streakRepo->applyFreeze($studentId, $gap);

        return [
            'applied' => true,
            'status' => 'applied',
            'gapDate' => $gap,
            'currentStreak' => $this->streakRepo->getCurrentStreak($studentId),
            'freezes' => $this->powerRepo->balance($studentId, PowerUpRepository::FREEZE),
        ];
    }

    /**
     * PA18 — the achievement timeline. Composes the badge catalogue (with its
     * durable first-earned dates, §11) plus the completed-mission history into a
     * single chronologically-ordered record of *when* the learner hit each
     * milestone, rather than only the current unlocked/done snapshot.
     *
     * Deterministic: same ledger + same persisted history => identical output.
     * @return array<string,mixed>
     */
    public function buildAchievements(int $studentId): array {
        $me = $this->buildMe($studentId);
        $badges = $this->buildBadges($studentId);
        $unlocked = array_values(array_filter($badges, static function ($b) {
            return !empty($b['isUnlocked']);
        }));

        $timeline = [];
        foreach ($unlocked as $b) {
            $timeline[] = [
                'kind' => 'badge',
                'code' => $b['code'],
                'title' => $b['name'],
                'earnedAt' => $b['unlockedAt'] ?? null,
            ];
        }
        foreach ($this->missionRepo->getHistory($studentId) as $row) {
            if ((int)($row['is_completed'] ?? 0) !== 1) {
                continue;
            }
            $completedAt = $row['completed_at'] ?? null;
            $timeline[] = [
                'kind' => 'mission',
                'missionId' => (int)$row['mission_id'],
                'periodDate' => (string)($row['period_date'] ?? ''),
                'earnedAt' => $completedAt !== null ? $this->toIso((string)$completedAt) : null,
            ];
        }

        // Newest milestone first; PHP 8 sort is stable, so equal timestamps keep
        // the deterministic badge-then-mission insertion order.
        usort($timeline, static function ($a, $b) {
            return strcmp((string)($b['earnedAt'] ?? ''), (string)($a['earnedAt'] ?? ''));
        });

        return [
            'studentId' => $studentId,
            'level' => $me['level'],
            'totalXp' => $me['totalXp'],
            'currentStreak' => $me['currentStreak'],
            'unlockedCount' => count($unlocked),
            'totalCount' => count($badges),
            'badges' => $badges,
            'timeline' => $timeline,
        ];
    }

    /**
     * Claim a mission reward. Only completable missions pay out, and the award is
     * idempotent per learner + mission + period, so double-tapping cannot farm XP
     * (AGENTS.md §12 ledger; api-contract §31).
     * @return array {success, missionId, xpAwarded, status}
     */
    public function claimMission(int $studentId, int $missionId): array {
        $mission = null;
        foreach ($this->buildMissions($studentId) as $m) {
            if ($m['id'] === $missionId) {
                $mission = $m;
                break;
            }
        }
        if ($mission === null) {
            return ['success' => false, 'missionId' => $missionId, 'xpAwarded' => 0, 'status' => 'not_found'];
        }
        if (!$mission['isCompleted']) {
            return ['success' => false, 'missionId' => $missionId, 'xpAwarded' => 0, 'status' => 'not_complete'];
        }

        $period = $mission['type'] === 'weekly' ? gmdate('Y-m-d', strtotime('monday this week')) : gmdate('Y-m-d');
        $key = "mission:{$missionId}:{$studentId}:{$period}";
        $this->xpRepo->recordTransaction(
            $studentId,
            (int)$mission['xpReward'],
            'mission',
            (string)$missionId,
            'Nhận thưởng nhiệm vụ: ' . $mission['title'],
            $key
        );

        // Durable record that this mission was met for the period (§11 history),
        // alongside the ledger award. Idempotent on the period key.
        $this->missionRepo->upsert(
            $studentId,
            $missionId,
            $period,
            (int)$mission['target'],
            (int)$mission['target'],
            true
        );

        return ['success' => true, 'missionId' => $missionId, 'xpAwarded' => (int)$mission['xpReward'], 'status' => 'claimed'];
    }
}
