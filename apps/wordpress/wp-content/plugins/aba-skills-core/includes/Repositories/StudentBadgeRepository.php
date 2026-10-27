<?php
/**
 * Earned-badge persistence (wp_aba_student_badges). One row per (student_id,
 * badge_code) so the first time a badge was unlocked is remembered forever —
 * the "earned at" history the read-only projection could not give (AGENTS.md
 * §11 longitudinal, §12 ground-truth). Award is idempotent on the unique key:
 * re-observing an unlocked badge never moves its earned date.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class StudentBadgeRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_student_badges';
    }

    /**
     * Mark a badge as earned. Idempotent: an existing (student, badge) keeps its
     * original awarded_at. $awardedAt defaults to now (GMT); tests backdate it.
     */
    public function award(int $studentId, string $badgeCode, ?string $awardedAt = null): bool {
        global $wpdb;
        $when = $awardedAt ?? current_time('mysql', 1);

        $sql = "INSERT INTO {$this->table} (student_id, badge_code, awarded_at)
                VALUES (%d, %s, %s)
                ON DUPLICATE KEY UPDATE badge_code = VALUES(badge_code)";
        return $wpdb->query($wpdb->prepare($sql, $studentId, $badgeCode, $when)) !== false;
    }

    /** @return array<string,string> badge_code => awarded_at (GMT datetime). */
    public function getAwardedMap(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT badge_code, awarded_at FROM {$this->table} WHERE student_id = %d",
            $studentId
        )) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row->badge_code] = (string)$row->awarded_at;
        }
        return $map;
    }

    /** True when the learner already holds this badge. */
    public function has(int $studentId, string $badgeCode): bool {
        return isset($this->getAwardedMap($studentId)[$badgeCode]);
    }
}
