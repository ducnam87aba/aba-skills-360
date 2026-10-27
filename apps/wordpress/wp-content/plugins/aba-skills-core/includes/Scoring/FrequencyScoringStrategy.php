<?php

namespace ABA\Core\Scoring;

/**
 * Frequency Scoring Strategy (Likert 1..5)
 * Implements Sections 14, 28, 29, 31 of docs/assessment-spec.md
 */
class FrequencyScoringStrategy implements ScoringStrategyInterface {
    const SCALE_MIN = 1;
    const SCALE_MAX = 5;

    public function scoreItems(array $questions, array $responses): array {
        $scoredItems = [];

        foreach ($questions as $q) {
            $qId = (int)$q['id'];
            if (!isset($responses[$qId])) {
                continue;
            }

            // Missing responses (explicit reason) are excluded from scoring,
            // never coerced to a numeric value (spec §15-16, §46).
            if (!empty($responses[$qId]['missing_reason'])) {
                continue;
            }

            $raw = (int)$responses[$qId]['response_value'];
            // Bound check
            if ($raw < self::SCALE_MIN || $raw > self::SCALE_MAX) {
                continue;
            }

            $isReverse = !empty($q['is_reverse']);
            $scoreValue = $isReverse ? ((self::SCALE_MAX + self::SCALE_MIN) - $raw) : $raw;

            // Normalize to 0 - 100
            $normalized = (($scoreValue - self::SCALE_MIN) / (self::SCALE_MAX - self::SCALE_MIN)) * 100;

            $scoredItems[$qId] = [
                'question_id' => $qId,
                'competency_code' => $q['competency_code'],
                'indicator_code' => $q['indicator_code'] ?? '',
                'raw_value' => $raw,
                'score_value' => $scoreValue,
                'normalized_score' => round($normalized, 2),
                'latency_ms' => $responses[$qId]['latency_ms'] ?? 0,
            ];
        }

        return $scoredItems;
    }
}
