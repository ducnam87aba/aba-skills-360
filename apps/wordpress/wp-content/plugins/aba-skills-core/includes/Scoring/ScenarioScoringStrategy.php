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

            // Missing responses are excluded, never scored as zero (§46).
            if (!empty($responses[$qId]['missing_reason'])) {
                continue;
            }

            $selectedOptionId = $responses[$qId]['response_value'];
            $points = null;
            $rubricValues = [];

            if (!empty($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $opt) {
                    $rubricValues[] = (float)($opt['score_value'] ?? 0);
                    if ($opt['id'] == $selectedOptionId) {
                        $points = (float)($opt['score_value'] ?? 0);
                    }
                }
            }

            // Unmatched option id => invalid response, excluded from scoring.
            if ($points === null) {
                continue;
            }

            // Normalize the rubric to 0-100 (§35): ((s-min)/(max-min))*100.
            $min = $rubricValues ? min($rubricValues) : 0.0;
            $max = $rubricValues ? max($rubricValues) : 0.0;
            $normalized = ($max > $min)
                ? (($points - $min) / ($max - $min)) * 100
                : 0.0;

            $scoredItems[$qId] = [
                'question_id' => $qId,
                'competency_code' => $q['competency_code'],
                'indicator_code' => $q['indicator_code'] ?? '',
                'raw_value' => $selectedOptionId,
                'score_value' => $points,
                'normalized_score' => round($normalized, 2),
                'latency_ms' => $responses[$qId]['latency_ms'] ?? 0,
            ];
        }

        return $scoredItems;
    }
}
