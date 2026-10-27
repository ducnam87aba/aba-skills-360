<?php
/**
 * Seeds the initial published assessment version so the Assessment Engine is
 * data-driven instead of hardcoding questions in a REST controller
 * (assessment-spec.md §19-24: published versions are immutable; sessions
 * resolve their exact frozen question set from the version they used).
 *
 * The full definition (reverse flags, rubric score_value, weights, minimum
 * evidence) is the server-side scoring key and is NEVER exposed to the client
 * (§77). The controller projects only prompt + options for answering.
 *
 * Idempotent: re-running only refreshes the draft/definition, never mutating a
 * historically referenced published version number.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Database;

use ABA\Core\Repositories\AssessmentVersionRepository;

if (!defined('ABSPATH')) {
    exit;
}

class AssessmentVersionSeeder {
    /** Editorial assessment this seeded version belongs to (aba_assessment CPT id). */
    public const ASSESSMENT_ID = 1;
    public const ASSESSMENT_CODE = 'ABA-B-S';
    public const VERSION = '0.1';

    /**
     * Option marker distinct from any single version number, so bumping it makes
     * the seeder re-sweep and fill newly added bands without ever re-touching an
     * already-published historical form (§10).
     */
    private const SEED_MARKER = 'bands-v1';

    /**
     * One frozen published form per grade band (PA29). The instrument is shared
     * (assessment_id 1) but each band answers age-appropriate items; version is
     * unique per assessment (uk_assessment_version), so bands get distinct numbers.
     */
    private const BAND_FORMS = [
        ['version' => '0.1', 'grade_group' => '4_6'],
        ['version' => '0.2', 'grade_group' => '7_9'],
        ['version' => '0.3', 'grade_group' => '10_12'],
    ];

    public static function seed(): void {
        // Cheap per-request guard; the DB remains the source of truth.
        if (get_option('aba_assessment_version_seeded') === self::SEED_MARKER) {
            return;
        }

        $repo = new AssessmentVersionRepository();

        foreach (self::BAND_FORMS as $form) {
            $version = $form['version'];
            $band = $form['grade_group'];

            // Never overwrite a historically referenced published version (§19, invariant #2).
            $existing = $repo->findByVersion(self::ASSESSMENT_ID, $version);
            if ($existing && $existing['status'] === 'published') {
                continue;
            }

            $repo->upsert([
                'assessment_id' => self::ASSESSMENT_ID,
                'assessment_code' => self::ASSESSMENT_CODE,
                'version' => $version,
                'status' => 'published',
                'grade_group' => $band,
                'respondent_type' => 'student',
                'scoring_version' => 'ABA-FREQ-0.1',
                'recall_period' => 'last_2_weeks',
                'question_order' => 'fixed',
                'definition' => self::definitionForBand($band),
            ]);
        }

        update_option('aba_assessment_version_seeded', self::SEED_MARKER, false);
    }

    /** The frozen item set for a band; 4_6 is the original v0.1, others are peers. */
    private static function definitionForBand(string $band): array {
        if ($band === '7_9') {
            return self::definition79();
        }
        if ($band === '10_12') {
            return self::definition1012();
        }
        return self::definition();
    }

    /**
     * Frozen measurement definition.
     * items[] shape is exactly what the scoring strategies consume.
     */
    private static function definition(): array {
        return [
            'scoring' => [
                'scoringVersion' => 'ABA-FREQ-0.1',
                // Minimum answered, eligible items required before a competency
                // may be scored; below this => insufficient_evidence (score null)
                // (assessment-spec.md §43-45). Default 1 for this 1-item-per-skill
                // v0.1 template; per-skill overrides supported.
                'minimumEvidence' => [
                    'default' => 1,
                    'bySkill' => [],
                ],
            ],
            'items' => [
                [
                    'id' => 1,
                    'competency_code' => 'PD',
                    'indicator_code' => 'PD_1',
                    'type' => 'scenario',
                    'recall_period' => 'last_2_weeks',
                    'weight' => 1.0,
                    'prompt' => 'Khi gặp một vấn đề mới, em thường...',
                    'options' => [
                        ['id' => 'A', 'label' => 'Tự tìm nhiều cách và chọn cách phù hợp nhất', 'score_value' => 100],
                        ['id' => 'B', 'label' => 'Chờ người lớn hướng dẫn', 'score_value' => 30],
                        ['id' => 'C', 'label' => 'Thử một cách rồi dừng lại', 'score_value' => 60],
                        ['id' => 'D', 'label' => 'Nhờ bạn bè giải quyết giúp', 'score_value' => 50],
                    ],
                ],
                [
                    'id' => 2,
                    'competency_code' => 'CO',
                    'indicator_code' => 'CO_1',
                    'type' => 'scenario',
                    'recall_period' => 'last_2_weeks',
                    'weight' => 1.0,
                    'prompt' => 'Khi muốn trình bày ý kiến của mình, em...',
                    'options' => [
                        ['id' => 'A', 'label' => 'Khó nói ra trước nhóm', 'score_value' => 20],
                        ['id' => 'B', 'label' => 'Nói rõ ràng và lắng nghe người khác', 'score_value' => 100],
                        ['id' => 'C', 'label' => 'Chỉ nói khi được yêu cầu', 'score_value' => 40],
                        ['id' => 'D', 'label' => 'Nói nhỏ và thường bị ngắt lời', 'score_value' => 30],
                    ],
                ],
                [
                    'id' => 3,
                    'competency_code' => 'ER',
                    'indicator_code' => 'ER_1',
                    'type' => 'scenario',
                    'recall_period' => 'last_2_weeks',
                    'weight' => 1.0,
                    'prompt' => 'Khi tức giận hoặc buồn bã, em...',
                    'options' => [
                        ['id' => 'A', 'label' => 'Khó kiểm soát cảm xúc', 'score_value' => 20],
                        ['id' => 'B', 'label' => 'Bình tĩnh và tìm cách vượt qua', 'score_value' => 100],
                        ['id' => 'C', 'label' => 'Giữ trong lòng rồi tự nguôi', 'score_value' => 30],
                        ['id' => 'D', 'label' => 'Chia sẻ với người tin tưởng', 'score_value' => 40],
                    ],
                ],
                self::likertItem(4, 'SA', 'SA_1', 'Em nhận ra điểm mạnh và điểm cần cố gắng của bản thân.', false),
                self::likertItem(5, 'SM', 'SM_1', 'Em hoàn thành bài tập và việc được giao đúng hạn.', false),
                self::likertItem(6, 'CE', 'CE_1', 'Em hợp tác tốt và quan tâm đến cảm xúc của bạn.', false),
            ],
        ];
    }

    /**
     * Band 7_9 form (middle-school early adolescence). Same six competencies and
     * scoring shape as v0.1 so the engine is band-agnostic; prompts are age-fit.
     */
    private static function definition79(): array {
        return [
            'scoring' => [
                'scoringVersion' => 'ABA-FREQ-0.1',
                'minimumEvidence' => ['default' => 1, 'bySkill' => []],
            ],
            'items' => [
                [
                    'id' => 1,
                    'competency_code' => 'PD',
                    'indicator_code' => 'PD_1',
                    'type' => 'scenario',
                    'recall_period' => 'last_2_weeks',
                    'weight' => 1.0,
                    'prompt' => 'Khi gặp một bài toán hay vấn đề khó, em thường...',
                    'options' => [
                        ['id' => 'A', 'label' => 'Tự đặt mục tiêu và thử nhiều hướng đến khi ra', 'score_value' => 100],
                        ['id' => 'B', 'label' => 'Chờ thầy cô gợi ý cách làm', 'score_value' => 30],
                        ['id' => 'C', 'label' => 'Thử một hướng rồi bỏ', 'score_value' => 60],
                        ['id' => 'D', 'label' => 'Hỏi và chép cách của bạn', 'score_value' => 50],
                    ],
                ],
                [
                    'id' => 2,
                    'competency_code' => 'CO',
                    'indicator_code' => 'CO_1',
                    'type' => 'scenario',
                    'recall_period' => 'last_2_weeks',
                    'weight' => 1.0,
                    'prompt' => 'Khi làm việc nhóm, em...',
                    'options' => [
                        ['id' => 'A', 'label' => 'Ít dám nêu ý kiến trước nhóm', 'score_value' => 20],
                        ['id' => 'B', 'label' => 'Trình bày rõ ràng và biết lắng nghe', 'score_value' => 100],
                        ['id' => 'C', 'label' => 'Chỉ nói khi được phân công', 'score_value' => 40],
                        ['id' => 'D', 'label' => 'Nêu ý kiến nhưng dễ bị cuốn theo số đông', 'score_value' => 30],
                    ],
                ],
                [
                    'id' => 3,
                    'competency_code' => 'ER',
                    'indicator_code' => 'ER_1',
                    'type' => 'scenario',
                    'recall_period' => 'last_2_weeks',
                    'weight' => 1.0,
                    'prompt' => 'Khi bị hiểu lầm hoặc chịu áp lực, em...',
                    'options' => [
                        ['id' => 'A', 'label' => 'Nóng nảy, khó kiềm chế', 'score_value' => 20],
                        ['id' => 'B', 'label' => 'Bình tĩnh giải thích và tìm cách hóa giải', 'score_value' => 100],
                        ['id' => 'C', 'label' => 'Im lặng chịu đựng', 'score_value' => 30],
                        ['id' => 'D', 'label' => 'Chia sẻ với người mà em tin tưởng', 'score_value' => 40],
                    ],
                ],
                self::likertItem(4, 'SA', 'SA_1', 'Em biết rõ điểm mạnh và điểm cần rèn của bản thân.', false),
                self::likertItem(5, 'SM', 'SM_1', 'Em tự giác hoàn thành việc học và nhiệm vụ được giao đúng hạn.', false),
                self::likertItem(6, 'CE', 'CE_1', 'Em hợp tác ăn ý và quan tâm đến cảm xúc của các bạn.', false),
            ],
        ];
    }

    /**
     * Band 10_12 form (upper secondary, near-adult autonomy). Same instrument,
     * prompts lean toward self-direction and future orientation.
     */
    private static function definition1012(): array {
        return [
            'scoring' => [
                'scoringVersion' => 'ABA-FREQ-0.1',
                'minimumEvidence' => ['default' => 1, 'bySkill' => []],
            ],
            'items' => [
                [
                    'id' => 1,
                    'competency_code' => 'PD',
                    'indicator_code' => 'PD_1',
                    'type' => 'scenario',
                    'recall_period' => 'last_2_weeks',
                    'weight' => 1.0,
                    'prompt' => 'Trước một quyết định quan trọng, em thường...',
                    'options' => [
                        ['id' => 'A', 'label' => 'Tự phân tích lựa chọn và chịu trách nhiệm với quyết định', 'score_value' => 100],
                        ['id' => 'B', 'label' => 'Để người khác quyết định giúp', 'score_value' => 30],
                        ['id' => 'C', 'label' => 'Do dự rồi chọn đại', 'score_value' => 60],
                        ['id' => 'D', 'label' => 'Làm theo số đông', 'score_value' => 50],
                    ],
                ],
                [
                    'id' => 2,
                    'competency_code' => 'CO',
                    'indicator_code' => 'CO_1',
                    'type' => 'scenario',
                    'recall_period' => 'last_2_weeks',
                    'weight' => 1.0,
                    'prompt' => 'Trong một tranh luận, em...',
                    'options' => [
                        ['id' => 'A', 'label' => 'Tránh lên tiếng vì sợ xung đột', 'score_value' => 20],
                        ['id' => 'B', 'label' => 'Bảo vệ quan điểm bằng lập luận và tôn trọng ý kiến trái chiều', 'score_value' => 100],
                        ['id' => 'C', 'label' => 'Chỉ phát biểu khi được yêu cầu', 'score_value' => 40],
                        ['id' => 'D', 'label' => 'Dễ bị thuyết phục bởi người nói mạnh hơn', 'score_value' => 30],
                    ],
                ],
                [
                    'id' => 3,
                    'competency_code' => 'ER',
                    'indicator_code' => 'ER_1',
                    'type' => 'scenario',
                    'recall_period' => 'last_2_weeks',
                    'weight' => 1.0,
                    'prompt' => 'Khi đối mặt căng thẳng kéo dài (thi cử, định hướng tương lai), em...',
                    'options' => [
                        ['id' => 'A', 'label' => 'Dễ bùng nổ hoặc gục ngã', 'score_value' => 20],
                        ['id' => 'B', 'label' => 'Nhận diện cảm xúc và chủ động tìm cách cân bằng', 'score_value' => 100],
                        ['id' => 'C', 'label' => 'Gồng chịu đựng một mình', 'score_value' => 30],
                        ['id' => 'D', 'label' => 'Tìm lời khuyên từ người em tin tưởng', 'score_value' => 40],
                    ],
                ],
                self::likertItem(4, 'SA', 'SA_1', 'Em hiểu rõ thế mạnh và giới hạn của bản thân để định hướng tương lai.', false),
                self::likertItem(5, 'SM', 'SM_1', 'Em tự quản lý kế hoạch học tập và hoàn thành cam kết đúng hạn.', false),
                self::likertItem(6, 'CE', 'CE_1', 'Em làm việc nhóm hiệu quả và tôn trọng sự khác biệt của người khác.', false),
            ],
        ];
    }

    /**
     * Build a Likert 1-5 frequency item. Reverse-scored items flip the response
     * on the server (§29); the reverse flag is never sent to the client.
     */
    private static function likertItem(int $id, string $competency, string $indicator, string $prompt, bool $isReverse): array {
        return [
            'id' => $id,
            'competency_code' => $competency,
            'indicator_code' => $indicator,
            'type' => 'frequency',
            'recall_period' => 'last_2_weeks',
            'weight' => 1.0,
            'is_reverse' => $isReverse,
            'prompt' => $prompt,
            'options' => [
                ['value' => 1, 'label' => 'Hoàn toàn không đúng'],
                ['value' => 2, 'label' => 'Không đúng'],
                ['value' => 3, 'label' => 'Phân vân'],
                ['value' => 4, 'label' => 'Đúng'],
                ['value' => 5, 'label' => 'Hoàn toàn đúng'],
            ],
        ];
    }
}
