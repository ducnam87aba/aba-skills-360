<?php

namespace ABA\Core\Services;

/**
 * Deterministic Recommendation Engine (AGENTS.md §13, assessment-spec.md §13).
 *
 * Version 1 is pure rules — no LLM, no randomness — so the same inputs always
 * produce the same ordered output and the suite can assert exact behaviour.
 *
 * Rules:
 *  - Every MEASURED competency scoring below the growth threshold contributes
 *    grade-group-flavoured content, largest gap first (stable: score asc, then
 *    skill code).
 *  - Insufficient evidence is unmeasured, never a low score (§45, invariant #1):
 *    it produces NO content recommendation rather than a spurious "fix this".
 *  - Output is capped so the learner/parent sees a focused next-best set.
 *
 * The public getRecommendations() shape is unchanged (list of items) so
 * ParentController keeps working; new keys are additive and optional for the
 * typed client.
 */
class RecommendationService {
    /** At or above this score a competency is not a growth priority. Public so the
     *  Learning Path can phrase the same threshold without duplicating the number. */
    public const THRESHOLD = 60.0;

    /** Cap the rail so the recommendation set stays focused. */
    private const MAX_RECOMMENDATIONS = 6;

    private const STRATEGY_VERSION = 'ABA-REC-0.2';

    /** Grade-group bands -> delivery style. Unknown bands fall back to 4_6. */
    private const BAND_STYLE = [
        '4_6'   => 'Trải nghiệm qua trò chơi và tình huống',
        '7_9'   => 'Luyện tập qua thảo luận và dự án nhóm',
        '10_12' => 'Phát triển qua dự án thực tế',
    ];

    /** The six core competencies (assessment-spec.md §26). */
    private const FOCUS = [
        'SA' => 'nhận diện điểm mạnh và điểm cần hoàn thiện của bản thân',
        'ER' => 'điều hòa cảm xúc khi gặp áp lực',
        'SM' => 'tự giác lập kế hoạch và hoàn thành nhiệm vụ',
        'CO' => 'trình bày ý kiến rõ ràng và lắng nghe chủ động',
        'CE' => 'hợp tác và thấu cảm với người khác',
        'PD' => 'phân tích vấn đề và ra quyết định có cân nhắc',
    ];

    /**
     * @param array $competencyScores list of skills shaped by SkillsProfileService
     *        (skillCode / score / status).
     * @return list<array{id,title,description,type,targetCompetency,priority,targetScore,reason,gradeGroup}>
     */
    public function getRecommendations(array $competencyScores, string $gradeGroup = '4_6'): array {
        $band = $this->normalizeBand($gradeGroup);
        $style = self::BAND_STYLE[$band];

        // Collect measured competencies below the threshold; skip unmeasured.
        $gaps = [];
        foreach ($competencyScores as $item) {
            $score = $item['score'] ?? null;
            $status = $item['status'] ?? 'valid';
            if ($status === 'insufficient_evidence' || $score === null) {
                continue;
            }
            $value = (float) $score;
            if ($value >= self::THRESHOLD) {
                continue;
            }
            $code = (string) ($item['skillCode'] ?? '');
            if (!isset(self::FOCUS[$code])) {
                continue;
            }
            $gaps[] = ['code' => $code, 'score' => $value];
        }

        // Deterministic ordering: biggest gap first, tie-break on skill code.
        usort($gaps, static function ($a, $b) {
            return ($a['score'] <=> $b['score']) ?: strcmp($a['code'], $b['code']);
        });

        $recommendations = [];
        foreach ($gaps as $rank => $gap) {
            if (count($recommendations) >= self::MAX_RECOMMENDATIONS) {
                break;
            }
            $code = $gap['code'];
            $focus = self::FOCUS[$code];
            $reason = 'Năng lực ở mức cần cải thiện (' . $this->fmt($gap['score']) . ' < ' . $this->fmt(self::THRESHOLD) . ')';

            $recommendations[] = [
                'id' => 'rec-' . $code . '-lesson',
                'title' => $style . ': ' . $focus,
                'description' => 'Bài học cho nhóm lớp ' . $band . ', giúp học sinh rèn luyện ' . $focus . '.',
                'type' => 'lesson',
                'targetCompetency' => $code,
                'priority' => count($recommendations) + 1,
                'targetScore' => $gap['score'],
                'reason' => $reason,
                'gradeGroup' => $band,
            ];

            if (count($recommendations) >= self::MAX_RECOMMENDATIONS) {
                break;
            }
            $recommendations[] = [
                'id' => 'rec-' . $code . '-home',
                'title' => 'Bài tập cùng gia đình: ' . $focus,
                'description' => 'Gợi ý phụ huynh đồng hành 10-15 phút mỗi ngày để con luyện ' . $focus . ' (nhóm lớp ' . $band . ').',
                'type' => 'home_exercise',
                'targetCompetency' => $code,
                'priority' => count($recommendations) + 1,
                'targetScore' => $gap['score'],
                'reason' => 'Củng cố tại nhà cho ưu tiên #' . ($rank + 1),
                'gradeGroup' => $band,
            ];
        }

        return $recommendations;
    }

    /**
     * Full envelope for the REST endpoint, so the client can show provenance
     * (strategy version + threshold + resolved grade group).
     */
    public function build(array $competencyScores, string $gradeGroup = '4_6'): array {
        $band = $this->normalizeBand($gradeGroup);
        return [
            'strategyVersion' => self::STRATEGY_VERSION,
            'threshold' => self::THRESHOLD,
            'gradeGroup' => $band,
            'items' => $this->getRecommendations($competencyScores, $gradeGroup),
        ];
    }

    private function normalizeBand(string $gradeGroup): string {
        $g = strtolower(trim($gradeGroup));
        return isset(self::BAND_STYLE[$g]) ? $g : '4_6';
    }

    private function fmt(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }
}
