<?php
/**
 * Suite: ParentOverviewService composition (api-contract.md §37).
 *
 * Verifies the parent view partitions skills into strengths (>=75) and priority
 * growth areas (<60) using only measured competencies, and that an
 * insufficient-evidence skill is surfaced as such rather than silently dropped
 * or rendered as 0 (assessment-spec.md §45).
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\SkillScoreRepository;
use ABA\Core\Repositories\SkillSnapshotRepository;
use ABA\Core\Repositories\LessonProgressRepository;
use ABA\Core\Repositories\XpTransactionRepository;
use ABA\Core\Repositories\MissionProgressRepository;
use ABA\Core\Services\ParentOverviewService;
use ABA\Core\Services\LearningPathService;

return function (AbaTestKit $kit): void {
    $kit->section('ParentOverviewTest — strengths / growth / insufficient');

    $studentId = $kit->makeStudent();

    try {
        $repo = new SkillScoreRepository();
        // CO 90 and PD 80 are strengths; ER 40 is a growth area; SA is unmeasured.
        $repo->upsertScore($studentId, 'CO', 'core_competency', 90.0, 'advanced', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $repo->upsertScore($studentId, 'PD', 'core_competency', 80.0, 'proficient', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $repo->upsertScore($studentId, 'ER', 'core_competency', 40.0, 'beginning', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $repo->upsertScore($studentId, 'SA', 'core_competency', null, 'insufficient_evidence', 0, 'student', 'ABA-FREQ-0.1', 'insufficient_evidence');

        $overview = (new ParentOverviewService())->build($studentId);

        $kit->check('overview built for existing student', $overview !== null);
        $kit->checkSame('student id surfaced', $studentId, $overview['student']['id'] ?? null);

        $strengths = array_column($overview['skills']['topStrengths'], 'key');
        $growth = array_column($overview['skills']['priorityGrowthAreas'], 'key');
        $insufficient = $overview['skills']['insufficientEvidence'];

        $kit->check('CO (90) is a strength', in_array('CO', $strengths, true));
        $kit->check('PD (80) is a strength', in_array('PD', $strengths, true));
        $kit->check('ER (40) is not a strength', !in_array('ER', $strengths, true));
        $kit->check('ER (40) is a priority growth area', in_array('ER', $growth, true));
        $kit->check('SA surfaces as insufficient evidence (not 0)', in_array('SA', $insufficient, true));
        $kit->check('SA excluded from strengths', !in_array('SA', $strengths, true));
        $kit->check('SA excluded from growth areas', !in_array('SA', $growth, true));

        // The overview is null-safe on a fresh student with no learning activity.
        $kit->checkSame('totalXp defaults to 0', 0, $overview['summary']['totalXp']);
        $kit->check('no report pointer before any snapshot', $overview['latestReport'] === null);

        // A snapshot wires the "latest report" pointer.
        (new SkillSnapshotRepository())->create($studentId, 0, [['skillCode' => 'CO', 'score' => 90]], []);
        $withReport = (new ParentOverviewService())->build($studentId);
        $kit->check('latest report pointer appears after a snapshot', is_array($withReport['latestReport']));
        $kit->checkSame('latest report type is assessment', 'assessment', $withReport['latestReport']['type'] ?? null);

        // ---- PA40: parent activity feed derives from real state (§12) ----
        // Shared node-label helper is the single source of lesson titles.
        $kit->checkSame(
            'titleForNode resolves a real unit label',
            'Bài học nền tảng: Hợp tác và thấu cảm',
            LearningPathService::titleForNode('unit-ce-node-1')
        );
        $kit->check('titleForNode returns null for an unknown id', LearningPathService::titleForNode('unit-xx-node-9') === null);

        // Seed ground truth only: lesson progress + the XP ledger, a completed
        // mission, and an assessment row so FIRST_ASSESSMENT unlocks by itself.
        $lessons = new LessonProgressRepository();
        $lessons->recordProgress($studentId, 'unit-ce-node-1', 'completed', 100.0, 30);
        $lessons->recordProgress($studentId, 'unit-ce-node-2', 'completed', 100.0, 60);
        $xp = new XpTransactionRepository();
        $xp->recordTransaction($studentId, 30, 'lesson', 'unit-ce-node-1', 'Lesson PA40', 'pa40-lesson-1');
        $xp->recordTransaction($studentId, 60, 'lesson', 'unit-ce-node-2', 'Lesson PA40', 'pa40-lesson-2');
        $xp->recordTransaction($studentId, 50, 'assessment', 'a1', 'Assessment PA40', 'pa40-assess');
        (new MissionProgressRepository())->upsert($studentId, 1, '2026-10-20', 1, 1, true, gmdate('Y-m-d H:i:s', strtotime('-2 day')));

        $feed = (new ParentOverviewService())->buildActivityFeed($studentId);
        $kit->check('activity feed built', is_array($feed));
        $kit->checkSame('feed echoes the student id', $studentId, $feed['studentId'] ?? null);
        $kit->check('weekly XP is SUM from the ledger (>= 140)', ($feed['weeklyXp'] ?? 0) >= 140);

        $types = array_column($feed['items'] ?? [], 'type');
        $kit->check('feed contains a real lesson completion', in_array('lesson', $types, true));
        $kit->check('feed contains the derived badge unlock', in_array('badge', $types, true));
        $kit->check('feed contains the completed mission', in_array('mission', $types, true));

        // FIRST_ASSESSMENT surfaces from the ledger, but a streak-7 badge must NOT
        // appear without a 7-day run — the exact fabrication the old hard-coded
        // activities page showed, which this feed is built to eliminate.
        $titles = array_column($feed['items'] ?? [], 'title');
        $joined = implode(' || ', array_map('strval', $titles));
        $kit->check('FIRST_ASSESSMENT badge surfaces from the ledger', strpos($joined, 'Người Dám Thử') !== false);
        $kit->check('STREAK_7 badge does NOT surface without a 7-day run', strpos($joined, 'Chiến Binh Bền Bỉ') === false);

        // Newest-first over ISO-8601 instants.
        $stamps = array_values(array_filter(array_column($feed['items'] ?? [], 'occurredAt')));
        $sorted = $stamps;
        rsort($sorted);
        $kit->check('feed is ordered newest-first', $stamps === $sorted);

        $kit->check('unknown student yields no feed (controller -> 404)', (new ParentOverviewService())->buildActivityFeed(999999) === null);
    } finally {
        $kit->cleanup($studentId);
    }
};
