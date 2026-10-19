<?php

namespace ABA\Core\Scoring;

interface ScoringStrategyInterface {
    /**
     * Score a list of responses for a set of questions
     *
     * @param array $questions Keyed by question_id or list of question definitions
     * @param array $responses Keyed by question_id containing response_value and latency_ms
     * @return array Calculated item scores and metadata
     */
    public function scoreItems(array $questions, array $responses): array;
}
