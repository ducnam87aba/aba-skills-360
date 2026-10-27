<?php
/**
 * Suite: ScoringEngine (pure unit — no database).
 *
 * Locks the measurement invariants of docs/assessment-spec.md that every other
 * layer depends on: Likert normalization (§31), reverse scoring (§29), weighted
 * aggregation over answered items only (§41-42), minimum-evidence => null not 0
 * (§43-45), missing exclusion (§46), scenario rubric normalization (§35),
 * descriptive overall (§93), and quality flags (§68).
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Scoring\ScoringEngine;

return function (AbaTestKit $kit): void {
    $kit->section('EngineTest — ScoringEngine (pure unit)');

    $engine = new ScoringEngine();

    /** Index competencyScores by skillCode for readable assertions. */
    $byCode = static function (array $result): array {
        $out = [];
        foreach ($result['competencyScores'] as $c) {
            $out[$c['skillCode']] = $c;
        }
        return $out;
    };

    // --- §31 Likert normalization -------------------------------------------
    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0]],
        [1 => ['response_value' => 5, 'latency_ms' => 3000]]
    );
    $kit->checkClose('Likert raw 5 normalizes to 100', 100.0, (float) $byCode($r)['SA']['score']);

    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0]],
        [1 => ['response_value' => 3, 'latency_ms' => 3000]]
    );
    $kit->checkClose('Likert raw 3 normalizes to 50 (midpoint)', 50.0, (float) $byCode($r)['SA']['score']);
    $kit->checkSame('50 maps to level "developing"', 'developing', $byCode($r)['SA']['level']);

    // --- §29 reverse scoring -------------------------------------------------
    // reverse of raw 2 => (5+1)-2 = 4 => ((4-1)/4)*100 = 75.
    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'is_reverse' => true, 'weight' => 1.0]],
        [1 => ['response_value' => 2, 'latency_ms' => 3000]]
    );
    $kit->checkClose('Reverse item: raw 2 flips to 4 => 75', 75.0, (float) $byCode($r)['SA']['score']);

    // --- §41 weighted aggregation -------------------------------------------
    // SA: item1 100 (weight 3) + item2 50 (weight 1) => (100*3 + 50*1)/4 = 87.5.
    $r = $engine->evaluate(
        [
            ['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 3.0],
            ['id' => 2, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0],
        ],
        [
            1 => ['response_value' => 5, 'latency_ms' => 3000], // 100
            2 => ['response_value' => 3, 'latency_ms' => 3000], // 50
        ]
    );
    $kit->checkClose('Weighted mean weights heavier item (=> 87.5)', 87.5, (float) $byCode($r)['SA']['score']);

    // --- §43-45 minimum evidence => null, never 0 ---------------------------
    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0]],
        [1 => ['response_value' => 5, 'latency_ms' => 3000]],
        ['minimumEvidence' => ['default' => 2, 'bySkill' => []]]
    );
    $sa = $byCode($r)['SA'];
    $kit->checkSame('Below min evidence => status insufficient_evidence', 'insufficient_evidence', $sa['status']);
    $kit->check('Below min evidence => score NULL (not 0)', $sa['score'] === null);
    $kit->check('overallScore NULL when nothing is measurable', $r['overallScore'] === null);

    // --- §46 missing excluded from numerator + denominator ------------------
    // SA has 2 eligible items; only 1 answered, 1 explicitly missing.
    $r = $engine->evaluate(
        [
            ['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0],
            ['id' => 2, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0],
        ],
        [
            1 => ['response_value' => 5, 'latency_ms' => 3000],           // 100
            2 => ['response_value' => 0, 'latency_ms' => 3000, 'missing_reason' => 'not_observed'],
        ]
    );
    $sa = $byCode($r)['SA'];
    $kit->checkClose('Missing item excluded from average (survivor = 100)', 100.0, (float) $sa['score']);
    $kit->checkSame('answeredCount counts only answered (1)', 1, $sa['answeredCount']);
    $kit->checkSame('eligibleCount counts all items (2)', 2, $sa['eligibleCount']);

    // --- §35 scenario rubric normalization ----------------------------------
    // Options {100,30,60,50} => min 30 max 100. Selected C(60) => (60-30)/(100-30)*100 = 42.86.
    $r = $engine->evaluate(
        [[
            'id' => 1,
            'type' => 'scenario',
            'competency_code' => 'PD',
            'weight' => 1.0,
            'options' => [
                ['id' => 'A', 'score_value' => 100],
                ['id' => 'B', 'score_value' => 30],
                ['id' => 'C', 'score_value' => 60],
                ['id' => 'D', 'score_value' => 50],
            ],
        ]],
        [1 => ['response_value' => 'C', 'latency_ms' => 3000]]
    );
    $kit->checkClose('Scenario rubric normalized relative to option span', 42.86, (float) $byCode($r)['PD']['score']);

    // --- §93 overall = mean of valid competencies (insufficient excluded) ---
    $r = $engine->evaluate(
        [
            ['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0],
            ['id' => 2, 'type' => 'frequency', 'competency_code' => 'CO', 'weight' => 1.0],
            ['id' => 3, 'type' => 'frequency', 'competency_code' => 'ER', 'weight' => 1.0],
        ],
        [
            1 => ['response_value' => 5, 'latency_ms' => 3000], // SA 100
            2 => ['response_value' => 3, 'latency_ms' => 3000], // CO 50
            3 => ['response_value' => 5, 'latency_ms' => 3000], // ER insufficient (needs 2)
        ],
        ['minimumEvidence' => ['default' => 1, 'bySkill' => ['ER' => 2]]]
    );
    $kit->checkSame('ER flagged insufficient via per-skill threshold', 'insufficient_evidence', $byCode($r)['ER']['status']);
    $kit->checkClose('overall averages only the valid competencies (=> 75)', 75.0, (float) $r['overallScore']);

    // --- §68 quality flags: speeding --------------------------------------
    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0]],
        [1 => ['response_value' => 5, 'latency_ms' => 1000]] // < 2000ms
    );
    $kit->check('Speeding flag raised on sub-2s response', $r['qualityFlags']['speedingFlag'] === true);

    // --- §68 quality flags: straightlining ----------------------------------
    $sq = [];
    $sr = [];
    for ($i = 1; $i <= 5; $i++) {
        $sq[] = ['id' => $i, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0];
        $sr[$i] = ['response_value' => 5, 'latency_ms' => 3000]; // 5 identical raw values
    }
    $r = $engine->evaluate($sq, $sr);
    $kit->check('Straightlining flag raised on 5 identical responses', $r['qualityFlags']['straightlining'] === true);
    $kit->check('No speeding flag when all latencies >= 2s', $r['qualityFlags']['speedingFlag'] === false);

    // --- completion rate ----------------------------------------------------
    $r = $engine->evaluate(
        [
            ['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0],
            ['id' => 2, 'type' => 'frequency', 'competency_code' => 'CO', 'weight' => 1.0],
        ],
        [1 => ['response_value' => 5, 'latency_ms' => 3000]] // only 1 of 2 answered
    );
    $kit->checkClose('completionRate reflects answered/total (50%)', 50.0, (float) $r['qualityFlags']['completionRate']);

    // --- PA8 registry: 'likert' type aliases to the Likert strategy (§9) -----
    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'likert', 'competency_code' => 'SA', 'weight' => 1.0]],
        [1 => ['response_value' => 5, 'latency_ms' => 3000]]
    );
    $kit->checkClose("Registry: type 'likert' routes to Likert strategy (5 => 100)", 100.0, (float) $byCode($r)['SA']['score']);

    // --- PA8 TeacherRatingStrategy: observer rating normalized like Likert ---
    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'teacher_rating', 'competency_code' => 'ER', 'weight' => 1.0]],
        [1 => ['response_value' => 5, 'latency_ms' => 3000]]
    );
    $kit->checkClose('Teacher rating 5/5 normalizes to 100', 100.0, (float) $byCode($r)['ER']['score']);

    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'teacher_rating', 'competency_code' => 'ER', 'weight' => 1.0]],
        [1 => ['response_value' => 3, 'latency_ms' => 3000]]
    );
    $kit->checkClose('Teacher rating 3/5 normalizes to 50', 50.0, (float) $byCode($r)['ER']['score']);

    // Custom scale ceiling (1..7): raw 4 => ((4-1)/(7-1))*100 = 50.
    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'teacher_rating', 'competency_code' => 'ER', 'scale_max' => 7, 'weight' => 1.0]],
        [1 => ['response_value' => 4, 'latency_ms' => 3000]]
    );
    $kit->checkClose('Teacher rating honors per-item scale_max ceiling (4/7 => 50)', 50.0, (float) $byCode($r)['ER']['score']);

    // Reverse teacher item: raw 2 on 1..5 => (5+1)-2=4 => 75 (§29).
    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'teacher_rating', 'competency_code' => 'ER', 'is_reverse' => true, 'weight' => 1.0]],
        [1 => ['response_value' => 2, 'latency_ms' => 3000]]
    );
    $kit->checkClose('Reverse teacher item flips (2 => 75)', 75.0, (float) $byCode($r)['ER']['score']);

    // Out-of-range teacher rating is dropped (never scored), leaving no evidence.
    $r = $engine->evaluate(
        [['id' => 1, 'type' => 'teacher_rating', 'competency_code' => 'ER', 'weight' => 1.0]],
        [1 => ['response_value' => 9, 'latency_ms' => 3000]]
    );
    $kit->check('Out-of-range teacher rating excluded => insufficient (§46)', $byCode($r)['ER']['status'] === 'insufficient_evidence');

    // Teacher-rated competency mixes cleanly alongside self-report items.
    $r = $engine->evaluate(
        [
            ['id' => 1, 'type' => 'frequency', 'competency_code' => 'SA', 'weight' => 1.0],
            ['id' => 2, 'type' => 'teacher_rating', 'competency_code' => 'SA', 'weight' => 1.0],
        ],
        [
            1 => ['response_value' => 5, 'latency_ms' => 3000], // 100
            2 => ['response_value' => 3, 'latency_ms' => 3000], // 50
        ]
    );
    $kit->checkClose('Mixed strategies aggregate into one competency mean (=> 75)', 75.0, (float) $byCode($r)['SA']['score']);
};
