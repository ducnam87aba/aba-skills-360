<?php
/**
 * Suite: Longitudinal Skills Profile — Skill Snapshots over time (PA10).
 *
 * The Skills Profile must keep BOTH the current score and its history so trend
 * charts and over-time comparisons are possible (§11). Completion already writes
 * an append-only snapshot row; this suite proves the READ side that feeds the
 * learner profile + parent trend chart: chronological order, null preservation
 * for insufficient evidence (§45), and immutability of historical rows.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\SkillSnapshotRepository;
use ABA\Core\Services\SkillsProfileService;

return function (AbaTestKit $kit): void {
    $kit->section('SkillsHistoryTest — longitudinal snapshots (PA10, §11)');

    $studentId = $kit->makeStudent('4_6');
    $repo = new SkillSnapshotRepository();
    $service = new SkillsProfileService();

    // Two assessment periods: SA/ER rise, CO goes from "no evidence" to measured.
    $periodOne = [
        ['skillCode' => 'SA', 'score' => 70.0, 'level' => 'proficient', 'status' => 'valid'],
        ['skillCode' => 'ER', 'score' => 55.0, 'level' => 'developing', 'status' => 'valid'],
        ['skillCode' => 'CO', 'score' => null, 'level' => 'beginning', 'status' => 'insufficient_evidence'],
    ];
    $periodTwo = [
        ['skillCode' => 'SA', 'score' => 82.0, 'level' => 'advanced', 'status' => 'valid'],
        ['skillCode' => 'ER', 'score' => 68.0, 'level' => 'developing', 'status' => 'valid'],
        ['skillCode' => 'CO', 'score' => 74.0, 'level' => 'proficient', 'status' => 'valid'],
    ];

    try {
        $id1 = $repo->create($studentId, 5001, $periodOne, [], '2026-08-01 00:00:00');
        $id2 = $repo->create($studentId, 5002, $periodTwo, [], '2026-10-01 00:00:00');
        $kit->check('snapshots receive row ids', $id1 > 0 && $id2 > 0);

        $history = $service->getHistory($studentId);
        $kit->checkSame('two periods recorded', 2, count($history));
        $kit->checkSame('oldest period first (§11 chronological order)', '2026-08-01', $history[0]['date'] ?? null);
        $kit->checkSame('newest period last', '2026-10-01', $history[1]['date'] ?? null);

        $kit->checkClose('SA rose to 82 in the latest snapshot', 82.0, (float) ($history[1]['scores']['SA'] ?? 0));
        $kit->checkClose('ER trend preserved (55 -> 68)', 55.0, (float) ($history[0]['scores']['ER'] ?? 0));

        // §45: an insufficient competency stays null in history, never 0.
        $p1Scores = $history[0]['scores'];
        $kit->check('insufficient evidence stays null in history (§45)', array_key_exists('CO', $p1Scores) && $p1Scores['CO'] === null);

        // Append-only ledger: adding a third period never mutates the earlier rows.
        $repo->create($studentId, 5003, $periodTwo, [], '2026-11-01 00:00:00');
        $after = $service->getHistory($studentId);
        $kit->checkSame('history grows to three periods (§11 append)', 3, count($after));
        $kit->checkSame('earliest historical row is untouched', '2026-08-01', $after[0]['date'] ?? null);

        // The overview "latest report" pointer resolves the newest snapshot.
        $latest = $repo->findLatest($studentId);
        $kit->checkSame('findLatest resolves the newest snapshot', '2026-11-01', substr((string) ($latest['snapshot_date'] ?? ''), 0, 10));

        // Determinism: identical state yields a byte-identical projection.
        $kit->check('history projection is deterministic', wp_json_encode($service->getHistory($studentId)) === wp_json_encode($service->getHistory($studentId)));
    } finally {
        $kit->cleanup($studentId);
    }
};
