<?php

namespace ABA\Core\Scoring;

/**
 * Teacher Rating Scoring Strategy (Likert, observer-rated).
 *
 * Same normalization family as the self-report Likert item (assessment-spec.md
 * §31) but semantically distinct: the respondent is a teacher rating an
 * indicator, not the learner reporting a frequency. Keeping it a first-class
 * strategy (AGENTS.md §9) lets a teacher-rated version produce scores under
 * source_type 'teacher' without reusing the frequency label, and its rows stay
 * isolated from the learner's own scores (§54).
 *
 * Supports a per-item scale ceiling (default 5) and reverse items (§29).
 */
class TeacherRatingStrategy implements ScoringStrategyInterface {
    const SCALE_MIN = 1;
    const DEFAULT_SCALE_MAX = 5;

    public function scoreItems(array $questions, array $responses): array {
        $scoredItems = [];

        foreach ($questions as $q) {
            $qId = (int)$q['id'];
            if (!isset($responses[$qId])) {
                continue;
            }

            // Not-yet-observed indicators are excluded, never coerced to 0 (§46).
            if (!empty($responses[$qId]['missing_reason'])) {
                continue;
            }

            $scaleMax = (int)($q['scale_max'] ?? self::DEFAULT_SCALE_MAX);
            if ($scaleMax < 2) {
                $scaleMax = self::DEFAULT_SCALE_MAX;
            }

            $raw = (int)$responses[$qId]['response_value'];
            if ($raw < self::SCALE_MIN || $raw > $scaleMax) {
                continue;
            }

            $isReverse = !empty($q['is_reverse']);
            $scoreValue = $isReverse ? (($scaleMax + self::SCALE_MIN) - $raw) : $raw;

            // Normalize the observer rating to the shared 0-100 scale (§31).
            $normalized = (($scoreValue - self::SCALE_MIN) / ($scaleMax - self::SCALE_MIN)) * 100;

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
