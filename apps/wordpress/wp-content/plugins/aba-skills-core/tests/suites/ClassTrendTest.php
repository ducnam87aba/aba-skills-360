<?php
/**
 * Suite: Longitudinal class average + k-anonymity floor (PA32 / PA33).
 *
 * Verifies getClassTrend aggregates each classmate's snapshots per assessment
 * date into a per-competency class mean — NULL (insufficient-evidence) scores
 * excluded (§45), dates ordered oldest → newest, a class-less learner yields an
 * empty trend (never a fabricated baseline), and the k-anonymity floor (§8): a
 * cell is published only when >= 5 DISTINCT learners contributed. A peer's
 * same-date retake counts once, a thin cell is dropped (its whole date omitted
 * when nothing clears the floor). The endpoint is view-permission gated with no
 * individual peer series leaked. Determinism asserted.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\SkillsProfileService;
use ABA\Core\Repositories\SkillSnapshotRepository;

return function (AbaTestKit $kit): void {
    $kit->section('ClassTrendTest — class average over time + k-anonymity floor (§11/§8)');

    $snaps = new SkillSnapshotRepository();
    $service = new SkillsProfileService();

    $school = $kit->makeSchool('SCH-32', 'Trường Trend 32');
    $class  = $kit->makeClass($school, '6A1', 'Lớp 6A1', '4_6');

    $a = $kit->makeStudent('4_6', $school, $class);
    $b = $kit->makeStudent('4_6', $school, $class);
    $c = $kit->makeStudent('4_6', $school, $class);
    $d = $kit->makeStudent('4_6', $school, $class);
    $e = $kit->makeStudent('4_6', $school, $class);
    $z = $kit->makeStudent('4_6', $school, 0); // class-less

    // 2026-08-01: SA across all five distinct learners => published. (80+40+70+60+90)/5 = 68.0
    foreach ([$a => 80, $b => 40, $c => 70, $d => 60, $e => 90] as $sid => $v) {
        $snaps->create($sid, 0, [ ['skillCode' => 'SA', 'score' => $v] ], [], '2026-08-01 00:00:00');
    }
    // 2026-09-01: SA only three learners => below floor => dropped.
    foreach ([$a => 90, $b => 60, $c => 70] as $sid => $v) {
        $snaps->create($sid, 0, [ ['skillCode' => 'SA', 'score' => $v] ], [], '2026-09-01 00:00:00');
    }
    // 2026-09-01: ER across all five => published despite SA being suppressed that date.
    foreach ([$a => 50, $b => 55, $c => 60, $d => 65, $e => 70] as $sid => $v) {
        $snaps->create($sid, 0, [ ['skillCode' => 'ER', 'score' => $v] ], [], '2026-09-01 00:00:00');
    }
    // 2026-10-01: A retakes SA (80 then 99) + B,C,D once => 4 DISTINCT learners,
    // five rows. Keyed by student the retake counts once => below floor => whole
    // date is omitted (proves per-learner, not per-row, anonymity).
    $snaps->create($a, 0, [ ['skillCode' => 'SA', 'score' => 80] ], [], '2026-10-01 00:00:00');
    $snaps->create($a, 0, [ ['skillCode' => 'SA', 'score' => 99] ], [], '2026-10-01 00:00:01');
    foreach ([$b => 70, $c => 60, $d => 85] as $sid => $v) {
        $snaps->create($sid, 0, [ ['skillCode' => 'SA', 'score' => $v] ], [], '2026-10-01 00:00:00');
    }

    /** index trend rows by date for assertion. */
    $byDate = static function (array $trend): array {
        $out = [];
        foreach ($trend as $row) {
            $out[$row['date']] = $row['scores'];
        }
        return $out;
    };

    try {
        $trend = $service->getClassTrend($a);
        $kit->checkSame('only dates clearing the floor survive (Aug + Sep)', 2, count($trend));
        $kit->checkSame('oldest date first', '2026-08-01', $trend[0]['date']);
        $kit->checkSame('newest date second', '2026-09-01', $trend[1]['date']);

        $map = $byDate($trend);
        $kit->checkClose('Aug class SA mean over 5 (68.0)', 68.0, (float) $map['2026-08-01']['SA']);
        $kit->check('Aug has no ER (never measured)', !isset($map['2026-08-01']['ER']));

        // Per-cell independence: SA dropped below the floor, ER published above it.
        $kit->check('Sep SA suppressed (only 3 contributors)', !isset($map['2026-09-01']['SA']));
        $kit->checkClose('Sep class ER mean over 5 (60.0)', 60.0, (float) $map['2026-09-01']['ER']);

        // Retake date had 5 rows but only 4 distinct learners => fully omitted.
        $kit->check('Oct omitted (retake counts once => 4 distinct < floor)', !isset($map['2026-10-01']));

        // Determinism.
        $kit->check('class trend is deterministic', $trend === $service->getClassTrend($a));

        // Class-less learner => empty.
        $kit->check('class-less trend is empty', $service->getClassTrend($z) === []);

        // ---- REST permission (§8) -------------------------------------------
        wp_set_current_user(0);
        $anon = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $a . '/skills/class-trend'));
        $kit->checkSame('anon class-trend rejected (401)', 401, $anon->get_status());

        $parent = $kit->makeParent('Trend Parent');
        $kit->linkParentStudent($parent, $a);
        wp_set_current_user($parent);
        $ok = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $a . '/skills/class-trend'));
        $kit->checkSame('linked parent reads class trend (200)', 200, $ok->get_status());
        $body = $ok->get_data();
        $kit->check('trend body is a 2-point series', is_array($body) && count($body) === 2);

        $stranger = $kit->makeParent('Stranger Parent');
        wp_set_current_user($stranger);
        $no = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $a . '/skills/class-trend'));
        $kit->checkSame('unlinked parent forbidden (403)', 403, $no->get_status());

        wp_set_current_user(0);
    } finally {
        foreach ([$a, $b, $c, $d, $e, $z] as $sid) {
            $kit->cleanup($sid);
        }
        if (isset($parent)) {
            $kit->cleanupUser($parent);
        }
        if (isset($stranger)) {
            $kit->cleanupUser($stranger);
        }
        $kit->cleanupClass($class);
        $kit->cleanupSchool($school);
    }
};
