<?php

namespace ABA\Core\Scoring;

/**
 * Scenario Scoring Strategy
 * Implements Sections 33-36 of docs/assessment-spec.md
 */
class ScenarioScoringStrategy implements ScoringStrategyInterface {
    public function scoreItems(array $questions, array $responses): array {
        $scoredItems = [];

        foreach ($questions as $q) {
            $qId = (int)$q['id'];
            if (!isset($responses[$qId])) {
                continue;
            }

            $selectedOptionId = $responses[$qId]['response_value'];
            $points = 0;

            if (!empty($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $opt) {
                    if ($opt['id'] == $selectedOptionId) {
                        $points = (float)($opt['score_value'] ?? 0);
                        break;
                    }
                }
            }

            $scoredItems[$qId] = [
                'question_id' => $qId,
                'competency_code' => $q['competency_code'],
                'indicator_code' => $q['indicator_code'] ?? '',
                'raw_value' => $selectedOptionId,
                'score_value' => $points,
                'normalized_score' => round($points, 2),
                'latency_ms' => $responses[$qId]['latency_ms'] ?? 0,
            ];
        }

        return $scoredItems;
    }
}
