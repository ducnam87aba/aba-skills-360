<?php
/**
 * Suite: Learning Path derivation from the Recommendation Engine (PA7).
 *
 * The trail used to be a fixed CO-only script. Now units are ORDERED by the
 * learner's measured growth gaps (RecommendationService), gated by real
 * progress (LessonProgressRepository), and insufficient-evidence competencies
 * still get a unit but never masquerade as a priority (§45, §13).
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\SkillScoreRepository;
use ABA\Core\Repositories\LessonProgressRepository;
use ABA\Core\Services\LearningPathService;

return function (AbaTestKit $kit): void {
    $kit->section('LearningPathTest — gap-ordered, progress-gated path (PA7)');

    $studentId = $kit->makeStudent('4_6');

    try {
        $scores = new SkillScoreRepository();
        $progress = new LessonProgressRepository();
        $service = new LearningPathService();

        // ER(20) and CO(40) are the two biggest gaps; SA/SM/PD are strong.
        // CE is unmeasured (insufficient) — present, but never a priority.
        $scores->upsertScore($studentId, 'ER', 'core_competency', 20.0, 'beginning', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $scores->upsertScore($studentId, 'CO', 'core_competency', 40.0, 'developing', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $scores->upsertScore($studentId, 'SA', 'core_competency', 90.0, 'advanced', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $scores->upsertScore($studentId, 'SM', 'core_competency', 85.0, 'advanced', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $scores->upsertScore($studentId, 'PD', 'core_competency', 80.0, 'proficient', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $scores->upsertScore($studentId, 'CE', 'core_competency', null, 'insufficient_evidence', 0, 'student', 'ABA-FREQ-0.1', 'insufficient_evidence');

        $path = $service->build($studentId);

        $targets = array_map(static fn($u) => $u['competencyTarget'], $path['units']);

        $kit->checkSame('all six competencies get a unit', 6, count($path['units']));
        // Biggest gap first (ER 20 before CO 40), then the strong/unmeasured tail
        // in canonical order (SA, SM, CE, PD).
        $kit->checkSame('units ordered biggest-gap first, tail canonical', ['ER', 'CO', 'SA', 'SM', 'CE', 'PD'], $targets);
        $kit->checkSame('first unit is the active gap unit', 'active', $path['units'][0]['status']);
        $kit->checkSame('second unit stays locked until the first is done', 'locked', $path['units'][1]['status']);
        $kit->checkSame('current pointer sits on the active gap unit', 'unit-er', $path['currentUnitId']);
        $kit->checkSame('current node is the first unfinished node', 'unit-er-node-1', $path['currentNodeId']);

        $erStatuses = array_map(static fn($n) => $n['status'], $path['units'][0]['nodes']);
        $kit->checkSame('single frontier: only the first node is unlocked', ['unlocked', 'locked', 'locked'], $erStatuses);

        // CE is unmeasured but still present — and never ahead of a real gap.
        $ceIndex = array_search('CE', $targets, true);
        $kit->check('insufficient competency ordered after measured gaps', $ceIndex > 1);

        // Rationale (§20): each unit carries the learner's score, a gap flag and a
        // deterministic reason, so the path explains why it sits where it does.
        $unitByCode = [];
        foreach ($path['units'] as $u) {
            $unitByCode[$u['competencyTarget']] = $u;
        }

        $er = $unitByCode['ER'];
        $kit->checkSame('gap unit exposes the learner score', 20.0, (float) $er['competencyScore']);
        $kit->check('gap unit is flagged a priority', $er['isPriority'] === true);
        $kit->check('gap reason leads with the priority + cites the score',
            strpos($er['reason'], 'Ưu tiên') === 0 && strpos($er['reason'], '20') !== false);

        $sa = $unitByCode['SA'];
        $kit->check('strong competency is not a priority', $sa['isPriority'] === false);
        $kit->checkSame('strong competency score exposed', 90.0, (float) $sa['competencyScore']);
        $kit->check('strong competency reason says solid', strpos($sa['reason'], 'vững') !== false);

        $ce = $unitByCode['CE'];
        $kit->check('unmeasured competency score is null (never fabricated)', $ce['competencyScore'] === null);
        $kit->check('unmeasured competency is not a priority', $ce['isPriority'] === false);
        $kit->check('unmeasured reason asks to update the measure', strpos($ce['reason'], 'Chưa đủ') === 0);

        // Completing the first node advances the frontier within the active unit.
        $progress->recordProgress($studentId, 'unit-er-node-1', 'completed', 100.0, 30);
        $path2 = $service->build($studentId);
        $er2 = array_map(static fn($n) => $n['status'], $path2['units'][0]['nodes']);
        $kit->checkSame('done node becomes completed', ['completed', 'unlocked', 'locked'], $er2);
        $kit->checkSame('current pointer advances to the new frontier', 'unit-er-node-2', $path2['currentNodeId']);

        // Finishing the whole ER unit promotes it to completed and hands 'active'
        // to the next gap (CO).
        $progress->recordProgress($studentId, 'unit-er-node-2', 'completed', 100.0, 60);
        $progress->recordProgress($studentId, 'unit-er-node-3', 'completed', 100.0, 100);
        $path3 = $service->build($studentId);
        $kit->checkSame('fully-done gap unit is marked completed', 'completed', $path3['units'][0]['status']);
        $kit->checkSame('next gap becomes the new active unit', 'active', $path3['units'][1]['status']);
        $kit->checkSame('current pointer moves to the CO unit', 'unit-co', $path3['currentUnitId']);

        // Determinism: same state => byte-identical projection.
        $a = wp_json_encode($service->build($studentId));
        $b = wp_json_encode($service->build($studentId));
        $kit->check('path build is deterministic for identical state', $a === $b);
    } finally {
        $kit->cleanup($studentId);
    }
};
