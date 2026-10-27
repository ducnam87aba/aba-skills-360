<?php
/**
 * Suite: Multi-source skill-score isolation (PA1 regression).
 *
 * Proves assessment-spec.md §54: student / teacher / parent measurements for the
 * SAME skill live in separate, non-blended rows under the widened
 * uk_student_skill_source key. Before migration v5 a teacher submission would
 * overwrite the learner's projection via ON DUPLICATE KEY UPDATE.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\SkillScoreRepository;
use ABA\Core\Services\SkillsProfileService;

return function (AbaTestKit $kit): void {
    $kit->section('MultiSourceTest — per-source isolation');

    $studentId = $kit->makeStudent();

    try {
        $repo = new SkillScoreRepository();
        $profile = new SkillsProfileService();

        // Learner self-report and a teacher observation of the SAME skill (CO).
        $repo->upsertScore($studentId, 'CO', 'core_competency', 40.0, 'developing', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $repo->upsertScore($studentId, 'CO', 'core_competency', 80.0, 'proficient', 0, 'teacher', 'ABA-FREQ-0.1', 'valid');

        $all = $repo->getScoresByStudent($studentId);
        $kit->checkSame('Two sources coexist for one skill (2 rows)', 2, count($all));

        $teacherRows = $repo->getScoresByStudent($studentId, 'teacher');
        $kit->checkSame('teacher filter returns exactly one row', 1, count($teacherRows));
        $kit->checkClose('teacher row keeps its own score (80)', 80.0, (float) $teacherRows[0]['score']);

        $sources = $repo->getSourcesForStudent($studentId);
        sort($sources);
        $kit->checkSame('distinct sources recorded', ['student', 'teacher'], $sources);

        // Default projection must be the learner's self-report, never blended.
        $current = $profile->getCurrentScores($studentId);
        $kit->checkSame('default projection is self-report only (1 row)', 1, count($current));
        $kit->checkClose('self-report CO stays 40 (not averaged with teacher)', 40.0, (float) $current[0]['score']);

        $currentTeacher = $profile->getCurrentScores($studentId, 'teacher');
        $kit->checkClose('teacher projection reads 80', 80.0, (float) $currentTeacher[0]['score']);

        // Re-submitting the learner's own measurement updates only the student row.
        $repo->upsertScore($studentId, 'CO', 'core_competency', 55.0, 'developing', 0, 'student', 'ABA-FREQ-0.1', 'valid');
        $kit->checkClose('student re-submit updates own row (55)', 55.0, (float) $profile->getCurrentScores($studentId)[0]['score']);
        $kit->checkClose('teacher row untouched by student re-submit (80)', 80.0, (float) $profile->getCurrentScores($studentId, 'teacher')[0]['score']);
    } finally {
        $kit->cleanup($studentId);
    }
};
