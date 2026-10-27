<?php

namespace ABA\Core\Services;

use ABA\Core\Repositories\LessonProgressRepository;
use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Repositories\ContentRepository;

/**
 * Learning Path assembly (Learning domain).
 *
 * Replaces the previously hardcoded trail (PA7) with a deterministic build that
 * ORDER the units from the Recommendation Engine: the learner's biggest measured
 * gaps surface first, so the path is driven by measurement, not a fixed script.
 *
 * Boundaries (AGENTS.md §5): this is a Learning service. It *consumes*
 * RecommendationService for gap ordering rather than pushing path logic into the
 * Recommendation domain. Progress gating is read from LessonProgressRepository.
 *
 * Determinism: same scores + same progress => identical units, ids and statuses.
 * Insufficient-evidence competencies are still given a unit (there is always a
 * next step), but they sort after the measured gaps because we have no signal to
 * prioritize them.
 */
class LearningPathService {
    private SkillsProfileService $skills;
    private RecommendationService $recs;
    private LessonProgressRepository $progress;
    private StudentRepository $students;
    private ContentRepository $content;

    public function __construct() {
        $this->skills = new SkillsProfileService();
        $this->recs = new RecommendationService();
        $this->progress = new LessonProgressRepository();
        $this->students = new StudentRepository();
        $this->content = new ContentRepository();
    }

    /** Canonical competency order used as the stable tie-breaker. */
    private const ALL_CODES = ['SA', 'ER', 'SM', 'CO', 'CE', 'PD'];

    private const UNIT_TITLES = [
        'SA' => 'Nhận diện bản thân',
        'ER' => 'Điều hòa cảm xúc',
        'SM' => 'Tự quản lý và kỷ luật',
        'CO' => 'Giao tiếp chủ động',
        'CE' => 'Hợp tác và thấu cảm',
        'PD' => 'Giải quyết vấn đề',
    ];

    private const UNIT_DESCS = [
        'SA' => 'Gọi tên cảm xúc, nhận biết điểm mạnh và điểm cần hoàn thiện',
        'ER' => 'Hạ nhiệt và chọn phản ứng an toàn khi cảm xúc dâng cao',
        'SM' => 'Bắt đầu, kiên trì và hoàn thành nhiệm vụ phù hợp tuổi',
        'CO' => 'Lắng nghe chủ động và diễn đạt ý kiến rõ ràng',
        'CE' => 'Phối hợp nhóm, chia sẻ và hỗ trợ bạn bè',
        'PD' => 'Nhận diện vấn đề, cân nhắc lựa chọn và hệ quả',
    ];

    /** Node template shared by every unit: lesson -> quiz -> boss challenge. */
    private const NODE_TEMPLATE = [
        ['type' => 'lesson', 'xp' => 30, 'label' => 'Bài học nền tảng'],
        ['type' => 'quiz', 'xp' => 60, 'label' => 'Kiểm tra nhanh'],
        ['type' => 'boss_challenge', 'xp' => 100, 'label' => 'Thử thách cuối chương'],
    ];

    /**
     * @return array{studentId:int,currentUnitId:string,currentNodeId:string,units:list<array>}
     */
    public function build(int $studentId, string $source = 'student'): array {
        $student = $this->students->findById($studentId);
        $gradeGroup = (string) ($student['grade_group'] ?? '4_6');

        $scores = $this->skills->getCurrentScores($studentId, $source);
        $recommendations = $this->recs->getRecommendations($scores, $gradeGroup);

        // Gap ordering: unique competencies in recommendation priority order,
        // then every remaining competency in canonical order (deterministic).
        $gapOrder = [];
        foreach ($recommendations as $rec) {
            $code = (string) ($rec['targetCompetency'] ?? '');
            if ($code !== '' && !in_array($code, $gapOrder, true)) {
                $gapOrder[] = $code;
            }
        }
        $ordered = array_merge($gapOrder, array_values(array_diff(self::ALL_CODES, $gapOrder)));

        // Learner's current score + status per competency, so each unit can explain
        // WHY it sits where it does (§20 clear progression). Keyed by code.
        $scoreByCode = [];
        $statusByCode = [];
        foreach ($scores as $s) {
            $code = (string) ($s['skillCode'] ?? '');
            if ($code === '') {
                continue;
            }
            $scoreByCode[$code] = $s['score'] ?? null;
            $statusByCode[$code] = (string) ($s['status'] ?? 'valid');
        }
        $threshold = RecommendationService::THRESHOLD;

        $completed = $this->progress->getCompletedNodeIds($studentId);

        $units = [];
        $currentUnitId = '';
        $currentNodeId = '';
        $activeAssigned = false;

        foreach ($ordered as $index => $code) {
            $unitId = 'unit-' . strtolower($code);
            $template = self::NODE_TEMPLATE;

            $nodeIds = [];
            foreach ($template as $k => $t) {
                $nodeIds[] = $unitId . '-node-' . ($k + 1);
            }
            $allDone = $nodeIds !== [] && count(array_intersect($nodeIds, $completed)) === count($nodeIds);

            if ($allDone) {
                $unitStatus = 'completed';
            } elseif (!$activeAssigned) {
                $unitStatus = 'active';
                $activeAssigned = true;
            } else {
                $unitStatus = 'locked';
            }

            $nodes = [];
            $frontierFound = false;
            $firstUnlocked = '';
            foreach ($template as $k => $t) {
                $nodeId = $nodeIds[$k];
                $isDone = in_array($nodeId, $completed, true);

                if ($isDone) {
                    $status = 'completed';
                } elseif ($unitStatus !== 'active') {
                    $status = 'locked';
                } elseif (!$frontierFound) {
                    $status = 'unlocked';
                    $frontierFound = true;
                    $firstUnlocked = $nodeId;
                } else {
                    $status = 'locked';
                }

                $node = [
                    'id' => $nodeId,
                    'title' => $t['label'] . ': ' . self::UNIT_TITLES[$code],
                    'description' => self::UNIT_DESCS[$code],
                    'type' => $t['type'],
                    'status' => $status,
                    'xpReward' => $t['xp'],
                    'order' => $k + 1,
                ];
                if ($isDone) {
                    $node['score'] = 100;
                }
                // Bind the deterministic node to a real Content Library lesson
                // (PA17). Additive: when no content has been seeded the node is
                // byte-identical to the pre-PA17 projection, so gap ordering and
                // progress gating stay untouched.
                $contentId = $this->content->findLessonId($code, $t['type'], $gradeGroup);
                if ($contentId !== null) {
                    $node['contentId'] = $contentId;
                }
                $nodes[] = $node;
            }

            if ($unitStatus === 'active' && $currentUnitId === '') {
                $currentUnitId = $unitId;
                $currentNodeId = $firstUnlocked;
            }

            // Rationale (§20): surface the gap signal that put this unit in order.
            $score = $scoreByCode[$code] ?? null;
            $status = $statusByCode[$code] ?? 'valid';
            $isPriority = in_array($code, $gapOrder, true);
            if ($status === 'insufficient_evidence' || $score === null) {
                $reason = 'Chưa đủ dữ liệu đo — học chương này để cập nhật năng lực.';
            } elseif ($isPriority) {
                $reason = 'Ưu tiên rèn luyện: ' . self::UNIT_TITLES[$code] . ' đang ở mức ' . $this->fmt((float) $score) . ' (< ' . $this->fmt($threshold) . ').';
            } else {
                $reason = 'Nền tảng đã vững (' . $this->fmt((float) $score) . ') — học để củng cố và mở rộng.';
            }

            $units[] = [
                'id' => $unitId,
                'title' => 'Chương ' . ($index + 1) . ': ' . self::UNIT_TITLES[$code],
                'description' => self::UNIT_DESCS[$code],
                'competencyTarget' => $code,
                'competencyScore' => $score === null ? null : (float) $score,
                'isPriority' => $isPriority,
                'reason' => $reason,
                'order' => $index + 1,
                'status' => $unitStatus,
                'nodes' => $nodes,
            ];
        }

        // Everything complete => point at the final unit's last node (trail done).
        if ($currentUnitId === '' && $units !== []) {
            $last = $units[count($units) - 1];
            $currentUnitId = $last['id'];
            $lastNode = $last['nodes'][count($last['nodes']) - 1];
            $currentNodeId = $lastNode['id'];
        }

        return [
            'studentId' => $studentId,
            'currentUnitId' => $currentUnitId,
            'currentNodeId' => $currentNodeId,
            'units' => $units,
        ];
    }

    /** Trim a score for human phrasing: 45.0 -> 45, 62.5 -> 62.5. */
    private function fmt(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    /**
     * Human label for a learning-path node id (`unit-{code}-node-{n}`), derived
     * from the same NODE_TEMPLATE + UNIT_TITLES the path is built with — a single
     * source of truth, so the parent activity feed never re-invents lesson names.
     * Returns null for an id this builder does not recognise.
     */
    public static function titleForNode(string $nodeId): ?string {
        if (preg_match('/^unit-([a-z]{2})-node-(\d+)$/', $nodeId, $m) === 1) {
            $code = strtoupper($m[1]);
            $index = (int) $m[2] - 1;
            if (isset(self::UNIT_TITLES[$code]) && isset(self::NODE_TEMPLATE[$index])) {
                return self::NODE_TEMPLATE[$index]['label'] . ': ' . self::UNIT_TITLES[$code];
            }
        }
        return null;
    }
}
