<?php
/**
 * Suite: Deterministic Recommendation Engine (PA4).
 *
 * AGENTS.md §13 requires v1 rules to be deterministic and testable. This locks:
 * coverage of all six competencies, the "biggest gap first" ordering, the
 * grade-group content flavor with a safe fallback, the output cap, and — the
 * key safety rule — that an insufficient-evidence skill is never treated as a
 * low score and thus never generates a content recommendation (§45).
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\RecommendationService;

return function (AbaTestKit $kit): void {
    $kit->section('RecommendationTest — deterministic rules');

    $svc = new RecommendationService();

    // One weak competency => its lesson + home exercise.
    $recs = $svc->getRecommendations([
        ['skillCode' => 'CO', 'score' => 40.0, 'status' => 'valid'],
    ], '4_6');
    $kit->checkSame('single gap => 2 items', 2, count($recs));
    $kit->checkSame('first item is the lesson', 'rec-CO-lesson', $recs[0]['id'] ?? null);
    $kit->checkSame('lesson type preserved', 'lesson', $recs[0]['type'] ?? null);
    $kit->checkSame('second item is the home exercise', 'home_exercise', $recs[1]['type'] ?? null);
    $kit->checkSame('priorities are 1-based', 1, $recs[0]['priority'] ?? null);
    $kit->check('4_6 band uses play-based delivery', strpos($recs[0]['title'], 'Trải nghiệm') !== false);

    // Competencies at/above threshold are not growth priorities.
    $kit->checkSame('proficient competency yields nothing', 0, count($svc->getRecommendations([
        ['skillCode' => 'CO', 'score' => 80.0, 'status' => 'valid'],
    ], '4_6')));

    // §45: insufficient evidence is unmeasured, never a low score.
    $kit->checkSame('insufficient evidence => no recommendation', 0, count($svc->getRecommendations([
        ['skillCode' => 'CO', 'score' => null, 'status' => 'insufficient_evidence'],
    ], '4_6')));

    // Ordering: biggest gap first.
    $ordered = $svc->getRecommendations([
        ['skillCode' => 'CO', 'score' => 40.0, 'status' => 'valid'],
        ['skillCode' => 'SA', 'score' => 20.0, 'status' => 'valid'],
    ], '4_6');
    $kit->checkSame('lowest score leads (SA)', 'SA', $ordered[0]['targetCompetency'] ?? null);
    $kit->checkSame('second gap follows (CO)', 'CO', $ordered[2]['targetCompetency'] ?? null);

    // Grade-group flavoring + fallback.
    $band79 = $svc->getRecommendations([['skillCode' => 'CO', 'score' => 40.0, 'status' => 'valid']], '7_9');
    $kit->check('7_9 band uses discussion/project delivery', strpos($band79[0]['title'], 'thảo luận') !== false);

    $fallback = $svc->build([['skillCode' => 'CO', 'score' => 40.0, 'status' => 'valid']], 'unknown');
    $kit->checkSame('unknown band falls back to 4_6', '4_6', $fallback['gradeGroup']);

    // Output cap: every competency weak => still bounded to 6 items, and only
    // the three biggest gaps survive.
    $all = $svc->getRecommendations([
        ['skillCode' => 'PD', 'score' => 10.0, 'status' => 'valid'],
        ['skillCode' => 'CO', 'score' => 20.0, 'status' => 'valid'],
        ['skillCode' => 'ER', 'score' => 30.0, 'status' => 'valid'],
        ['skillCode' => 'SA', 'score' => 40.0, 'status' => 'valid'],
        ['skillCode' => 'SM', 'score' => 50.0, 'status' => 'valid'],
        ['skillCode' => 'CE', 'score' => 59.0, 'status' => 'valid'],
    ], '4_6');
    $kit->checkSame('recommendations are capped at 6', 6, count($all));
    $codes = array_unique(array_column($all, 'targetCompetency'));
    sort($codes);
    $kit->checkSame('only the three lowest gaps survive', ['CO', 'ER', 'PD'], $codes);

    // Non-core / unknown skill codes are ignored.
    $kit->checkSame('unknown skill code ignored', 0, count($svc->getRecommendations([
        ['skillCode' => 'ZZ', 'score' => 10.0, 'status' => 'valid'],
    ], '4_6')));

    // Determinism: identical inputs => identical output.
    $input = [
        ['skillCode' => 'CO', 'score' => 40.0, 'status' => 'valid'],
        ['skillCode' => 'SA', 'score' => 20.0, 'status' => 'valid'],
    ];
    $a = wp_json_encode($svc->getRecommendations($input, '7_9'));
    $b = wp_json_encode($svc->getRecommendations($input, '7_9'));
    $kit->check('engine is deterministic across calls', $a === $b);

    // Envelope provenance for the REST endpoint.
    $envelope = $svc->build([['skillCode' => 'CO', 'score' => 40.0, 'status' => 'valid']], '4_6');
    $kit->checkSame('strategy version reported', 'ABA-REC-0.2', $envelope['strategyVersion'] ?? null);
    $kit->checkSame('threshold reported', 60, (int) ($envelope['threshold'] ?? 0));
    $kit->check('envelope carries items list', is_array($envelope['items'] ?? null) && count($envelope['items']) === 2);
};
