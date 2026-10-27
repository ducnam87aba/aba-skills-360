<?php
/**
 * Streak persistence (wp_aba_streaks). One row per active calendar day, keyed by
 * (student_id, activity_date), so "did the learner show up today" and the current
 * consecutive-day streak are derived from real activity rather than a mutable
 * counter. Aligned with the XP-ledger philosophy: keep the ground truth, derive
 * the number (AGENTS.md §12, §11 longitudinal).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class StreakRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_streaks';
    }

    /**
     * Mark one calendar day as active. Idempotent: a repeat on the same date is a
     * no-op on the unique key. $date defaults to today (GMT) to match the ledger's
     * timestamps; tests can backdate to build deterministic runs.
     */
    public function recordActivity(int $studentId, ?string $date = null): bool {
        global $wpdb;
        $activityDate = $date ?? gmdate('Y-m-d');
        $now = current_time('mysql', 1);

        $sql = "INSERT INTO {$this->table} (student_id, activity_date, streak_count, created_at)
                VALUES (%d, %s, 1, %s)
                ON DUPLICATE KEY UPDATE activity_date = VALUES(activity_date)";
        return $wpdb->query($wpdb->prepare($sql, $studentId, $activityDate, $now)) !== false;
    }

    /**
     * Current consecutive-day streak. Counts backward from the most recent active
     * day; the run only counts while the streak is still "alive" (last activity
     * today or yesterday) — an older gap means the streak has broken to 0.
     */
    public function getCurrentStreak(int $studentId): int {
        $dates = $this->getActiveDates($studentId);
        if ($dates === []) {
            return 0;
        }

        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
        $mostRecent = $dates[0]; // DESC order => newest first
        if ($mostRecent !== $today && $mostRecent !== $yesterday) {
            return 0;
        }

        $streak = 1;
        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $expected = gmdate('Y-m-d', strtotime($dates[$i - 1] . ' -1 day'));
            if ($dates[$i] === $expected) {
                $streak++;
            } else {
                break;
            }
        }
        return $streak;
    }

    /** Longest run ever recorded, across the full activity calendar. */
    public function getLongestStreak(int $studentId): int {
        $dates = $this->getActiveDates($studentId);
        if ($dates === []) {
            return 0;
        }
        $best = 1;
        $run = 1;
        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $expected = gmdate('Y-m-d', strtotime($dates[$i - 1] . ' -1 day'));
            if ($dates[$i] === $expected) {
                $run++;
            } else {
                $run = 1;
            }
            $best = max($best, $run);
        }
        return $best;
    }

    /** @return string[] active dates in YYYY-MM-DD, newest first. */
    private function getActiveDates(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT activity_date FROM {$this->table} WHERE student_id = %d ORDER BY activity_date DESC",
            $studentId
        )) ?: [];
        return array_map(static fn($d) => substr((string)$d, 0, 10), $rows);
    }

    /**
     * PA22 — the single calendar day a freeze could bridge, or null when the run
     * needs no repair. Only meaningful while the streak is still alive (last
     * activity today or yesterday); we then scan the two most-recent active days
     * and report the missing date of the first one-day hole. A larger gap, or no
     * hole at the top of the run, is not repairable by a single freeze.
     */
    public function detectRepairableGap(int $studentId): ?string {
        $dates = $this->getActiveDates($studentId);
        if ($dates === []) {
            return null;
        }
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
        if ($dates[0] !== $today && $dates[0] !== $yesterday) {
            return null; // the run has already died at the top; nothing to save
        }

        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $newer = strtotime($dates[$i - 1]);
            $older = strtotime($dates[$i]);
            $gapDays = (int) round(($newer - $older) / 86400);
            if ($gapDays === 2) {
                return gmdate('Y-m-d', $older + 86400); // the hole between them
            }
            if ($gapDays > 2) {
                return null; // first hole is too big for one freeze
            }
        }
        return null;
    }

    /**
     * PA22 — mark a missed day as protected by a freeze. This bridges the run in
     * getCurrentStreak without awarding any XP (the row is not an xp transaction),
     * and it never overwrites a real activity day: on the unique key it only flips
     * the freeze_used flag. Idempotent — applying twice to the same date is a no-op.
     */
    public function applyFreeze(int $studentId, string $gapDate): bool {
        global $wpdb;
        $gapDate = substr($gapDate, 0, 10);
        $now = current_time('mysql', 1);
        $sql = "INSERT INTO {$this->table} (student_id, activity_date, streak_count, freeze_used, created_at)
                VALUES (%d, %s, 1, 1, %s)
                ON DUPLICATE KEY UPDATE freeze_used = 1";
        return $wpdb->query($wpdb->prepare($sql, $studentId, $gapDate, $now)) !== false;
    }
}
