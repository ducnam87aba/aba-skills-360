<?php

namespace ABA\Core\Scoring;

/**
 * ScoringEngine
 * Core measurement engine adhering to docs/assessment-spec.md
 */
class ScoringEngine {
    private FrequencyScoringStrategy $frequencyStrategy;
    private ScenarioScoringStrategy $scenarioStrategy;

    public function __construct() {
        $this->frequencyStrategy = new FrequencyScoringStrategy();
        $this->scenarioStrategy = new ScenarioScoringStrategy();
    }

    /**
     * Evaluate complete assessment responses
     */
    public function evaluate(array $questions, array $responses): array {
        $freqQuestions = [];
        $scenarioQuestions = [];

        foreach ($questions as $q) {
            $type = $q['type'] ?? 'scenario';
            if ($type === 'frequency') {
                $freqQuestions[$q['id']] = $q;
            } else {
                $scenarioQuestions[$q['id']] = $q;
            }
        }

        // Score both groups
        $freqScored = $this->frequencyStrategy->scoreItems($freqQuestions, $responses);
        $scenarioScored = $this->scenarioStrategy->scoreItems($scenarioQuestions, $responses);
        $allScoredItems = $freqScored + $scenarioScored;

        // Group by competency (SA, ER, SM, CO, CE, PD)
        $competencyBuckets = [
            'SA' => [],
            'ER' => [],
            'SM' => [],
            'CO' => [],
            'CE' => [],
            'PD' => [],
        ];

        $latencies = [];
        $rawSequence = [];

        foreach ($allScoredItems as $item) {
            $code = $item['competency_code'];
            if (isset($competencyBuckets[$code])) {
                $competencyBuckets[$code][] = $item['normalized_score'];
            }
            if (isset($item['latency_ms']) && $item['latency_ms'] > 0) {
                $latencies[] = $item['latency_ms'];
            }
            $rawSequence[] = $item['raw_value'];
        }

        // Calculate aggregated competency scores
        $competencyScores = [];
        $totalScoresSum = 0;
        $totalCompetenciesWithData = 0;

        $competencyNames = [
            'SA' => 'Tự nhận thức',
            'ER' => 'Quản lý cảm xúc',
            'SM' => 'Tự quản lý',
            'CO' => 'Giao tiếp',
            'CE' => 'Hợp tác và đồng cảm',
            'PD' => 'Giải quyết vấn đề và ra quyết định',
        ];

        foreach ($competencyBuckets as $code => $scores) {
            if (count($scores) > 0) {
                $avg = array_sum($scores) / count($scores);
                $roundedAvg = round($avg, 2);
                $totalScoresSum += $roundedAvg;
                $totalCompetenciesWithData++;
            } else {
                $roundedAvg = 0.0;
            }

            $competencyScores[$code] = [
                'skillCode' => $code,
                'skillName' => $competencyNames[$code] ?? $code,
                'category' => 'core_competency',
                'score' => $roundedAvg,
                'maxScore' => 100,
                'level' => $this->determineLevel($roundedAvg),
                'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }

        $overallScore = $totalCompetenciesWithData > 0 
            ? round($totalScoresSum / $totalCompetenciesWithData, 2) 
            : 0.0;

        // Quality Flags
        $totalQuestions = count($questions);
        $answeredCount = count($allScoredItems);
        $completionRate = $totalQuestions > 0 ? round(($answeredCount / $totalQuestions) * 100, 2) : 0;

        $speedingFlag = false;
        foreach ($latencies as $lat) {
            if ($lat < 2000) { // < 2 seconds per item
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

    private function detectStraightlining(array $sequence): boolean {
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
