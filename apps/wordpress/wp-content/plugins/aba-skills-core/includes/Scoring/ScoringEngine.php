<?php

namespace ABA\Core\Scoring;

/**
 * ScoringEngine
 * Core measurement engine adhering to docs/assessment-spec.md
 *
 * Aggregation (§41): skill_score = SUM(normalized * weight) / SUM(weight),
 * over ELIGIBLE ANSWERED items only. Missing items enter neither the
 * numerator nor the denominator (§42, §46). A skill is only scored when it
 * meets the version's minimum-evidence threshold; otherwise it returns
 * status "insufficient_evidence" with score null (§43-45, invariant #12).
 */
class ScoringEngine {
    /**
     * Pluggable scoring strategies keyed by a stable strategy id (AGENTS.md §9).
     * Adding a new measurement family means registering a ScoringStrategyInterface
     * here — not editing the aggregation loop below.
     * @var array<string, ScoringStrategyInterface>
     */
    private array $strategies;

    /** Question type -> strategy id. Unlisted types fall back to scenario. */
    private const TYPE_TO_STRATEGY = [
        'frequency'      => 'likert',
        'likert'         => 'likert',
        'scenario'       => 'scenario',
        'teacher_rating' => 'teacher',
    ];
    private const DEFAULT_STRATEGY = 'scenario';

    /** Fixed merge order keeps the scored-item sequence deterministic. */
    private const STRATEGY_ORDER = ['likert', 'scenario', 'teacher'];

    public function __construct() {
        $this->strategies = [
            'likert'   => new FrequencyScoringStrategy(),
            'scenario' => new ScenarioScoringStrategy(),
            'teacher'  => new TeacherRatingStrategy(),
        ];
    }

    /**
     * @param array $config {
     *   scoringVersion: string,
     *   minimumEvidence: { default: int, bySkill: {SKILL: int} }
     * }
     */
    public function evaluate(array $questions, array $responses, array $config = []): array {
        $scoringVersion = $config['scoringVersion'] ?? 'ABA-FREQ-0.1';
        $minEvidence = $config['minimumEvidence'] ?? ['default' => 1, 'bySkill' => []];

        // Group items by their resolved scoring strategy (registry, AGENTS.md §9).
        $groups = array_fill_keys(array_keys($this->strategies), []);
        $weights = [];
        $eligibleByCompetency = [];

        foreach ($questions as $q) {
            $type = (string)($q['type'] ?? 'scenario');
            $strategyKey = self::TYPE_TO_STRATEGY[$type] ?? self::DEFAULT_STRATEGY;
            $groups[$strategyKey][(int)$q['id']] = $q;
            $weights[(int)$q['id']] = (float)($q['weight'] ?? 1.0);
            $code = $q['competency_code'] ?? null;
            if ($code !== null) {
                $eligibleByCompetency[$code] = ($eligibleByCompetency[$code] ?? 0) + 1;
            }
        }

        // Score each group with its own strategy and merge in a fixed, deterministic
        // order so the response sequence (used by the straightlining flag) stays
        // stable regardless of how item types are interleaved in the definition.
        $allScoredItems = [];
        foreach (self::STRATEGY_ORDER as $key) {
            if (isset($this->strategies[$key])) {
                $allScoredItems += $this->strategies[$key]->scoreItems($groups[$key] ?? [], $responses);
            }
        }

        // Bucket eligible competency codes (stable order).
        $competencyBuckets = [];
        foreach ($questions as $q) {
            $code = $q['competency_code'] ?? null;
            if ($code !== null && !isset($competencyBuckets[$code])) {
                $competencyBuckets[$code] = [];
            }
        }

        $latencies = [];
        $rawSequence = [];

        foreach ($allScoredItems as $item) {
            $code = $item['competency_code'];
            if (array_key_exists($code, $competencyBuckets)) {
                $competencyBuckets[$code][] = [
                    'normalized' => (float)$item['normalized_score'],
                    'weight' => $weights[(int)$item['question_id']] ?? 1.0,
                ];
            }
            if (isset($item['latency_ms']) && $item['latency_ms'] > 0) {
                $latencies[] = $item['latency_ms'];
            }
            $rawSequence[] = $item['raw_value'];
        }

        $competencyScores = [];
        $totalScoresSum = 0.0;
        $totalCompetenciesWithData = 0;

        $competencyNames = [
            'SA' => 'Tự nhận thức',
            'ER' => 'Quản lý cảm xúc',
            'SM' => 'Tự quản lý',
            'CO' => 'Giao tiếp',
            'CE' => 'Hợp tác và đồng cảm',
            'PD' => 'Giải quyết vấn đề và ra quyết định',
        ];

        foreach ($competencyBuckets as $code => $entries) {
            $answeredCount = count($entries);
            $eligibleCount = $eligibleByCompetency[$code] ?? $answeredCount;
            $required = $minEvidence['bySkill'][$code] ?? ($minEvidence['default'] ?? 1);

            if ($answeredCount === 0 || $answeredCount < $required) {
                // Insufficient evidence => null score, never 0 (§45).
                $competencyScores[$code] = [
                    'skillCode' => $code,
                    'skillName' => $competencyNames[$code] ?? $code,
                    'category' => 'core_competency',
                    'score' => null,
                    'maxScore' => 100,
                    'level' => 'insufficient_evidence',
                    'status' => 'insufficient_evidence',
                    'answeredCount' => $answeredCount,
                    'eligibleCount' => $eligibleCount,
                    'scoringVersion' => $scoringVersion,
                    'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ];
                continue;
            }

            $weightedSum = 0.0;
            $weightSum = 0.0;
            foreach ($entries as $e) {
                $weightedSum += $e['normalized'] * $e['weight'];
                $weightSum += $e['weight'];
            }
            $avg = $weightSum > 0 ? round($weightedSum / $weightSum, 2) : 0.0;
            $totalScoresSum += $avg;
            $totalCompetenciesWithData++;

            $competencyScores[$code] = [
                'skillCode' => $code,
                'skillName' => $competencyNames[$code] ?? $code,
                'category' => 'core_competency',
                'score' => $avg,
                'maxScore' => 100,
                'level' => $this->determineLevel($avg),
                'status' => 'valid',
                'answeredCount' => $answeredCount,
                'eligibleCount' => $eligibleCount,
                'scoringVersion' => $scoringVersion,
                'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }

        // Descriptive summary only, NOT an official composite measurement (§93).
        $overallScore = $totalCompetenciesWithData > 0
            ? round($totalScoresSum / $totalCompetenciesWithData, 2)
            : null;

        $totalQuestions = count($questions);
        $answeredCount = count($allScoredItems);
        $completionRate = $totalQuestions > 0 ? round(($answeredCount / $totalQuestions) * 100, 2) : 0;

        $speedingFlag = false;
        foreach ($latencies as $lat) {
            if ($lat < 2000) { // < 2 seconds per item (quality metadata only, §68)
                $speedingFlag = true;
                break;
            }
        }

        $avgLatency = count($latencies) > 0 ? (int)(array_sum($latencies) / count($latencies)) : 0;
        $straightlining = $this->detectStraightlining($rawSequence);

        $qualityFlags = [
            'completionRate' => $completionRate,
            'speedingFlag' => $speedingFlag,
            'straightlining' => $straightlining,
            'averageLatencyMs' => $avgLatency,
        ];

        return [
            'overallScore' => $overallScore,
            'competencyScores' => array_values($competencyScores),
            'scoredItems' => $allScoredItems,
            'qualityFlags' => $qualityFlags,
            'scoringVersion' => $scoringVersion,
        ];
    }

    private function determineLevel(float $score): string {
        if ($score >= 85) {
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

    private function detectStraightlining(array $sequence): bool {
        if (count($sequence) < 5) {
            return false;
        }

        $consecutive = 1;
        $last = null;
        foreach ($sequence as $val) {
            if ($last !== null && $val === $last) {
                $consecutive++;
                if ($consecutive >= 5) {
                    return true;
                }
            } else {
                $consecutive = 1;
                $last = $val;
            }
        }
        return false;
    }
}
