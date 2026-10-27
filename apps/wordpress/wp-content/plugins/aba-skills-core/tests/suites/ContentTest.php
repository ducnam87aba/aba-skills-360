<?php
/**
 * Suite: Content Library (PA17, multi-band PA17b).
 *
 * Proves the lesson library is seeded into CPTs for all three grade bands, that
 * a (competency, type, band) triple resolves deterministically to a real lesson
 * of the *right band* — with the fallback kept only for genuinely unknown bands
 * — and that the Learning Path binds each of its nodes to that content via an
 * additive `contentId` without disturbing gap ordering or progress gating.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\ContentRepository;
use ABA\Core\Services\LessonService;
use ABA\Core\Services\LearningPathService;

return function (AbaTestKit $kit): void {
    $kit->section('ContentTest — seeded lesson library + node binding (PA17)');

    $content = new ContentRepository();
    $lessons = new LessonService();

    // The library is shared editorial content, not per-student data — reads need
    // no principal. Idempotent seeding means the counts below are stable:
    // 18 lessons x 3 bands (PA17b).
    $kit->check('library seeded for all bands (>=54 published lessons)', $content->countLessons() >= 54);

    $coLesson = $content->findLessonId('CO', 'lesson', '4_6');
    $kit->check('CO lesson resolves to a real post id', is_int($coLesson) && $coLesson > 0);

    $dto = $coLesson !== null ? $content->getLesson($coLesson) : null;
    $kit->check('getLesson projects a DTO', is_array($dto));
    $kit->checkSame('DTO competency matches request', 'CO', $dto['competencyTarget'] ?? null);
    $kit->checkSame('DTO lesson type is preserved', 'lesson', $dto['type'] ?? null);
    $kit->check('DTO carries a non-empty body', trim((string) ($dto['content'] ?? '')) !== '');
    $kit->check('DTO carries an xp reward', (int) ($dto['xpReward'] ?? 0) > 0);
    $kit->checkSame('unknown lesson => null', null, $content->getLesson(99999999));

    // Determinism: the same triple always resolves to the same lesson.
    $kit->checkSame('lesson binding is deterministic', $coLesson, $content->findLessonId('CO', 'lesson', '4_6'));

    // ---- PA17b: dedicated band content, fallback kept for unknown bands ----
    $co79  = $content->findLessonId('CO', 'lesson', '7_9');
    $co1012 = $content->findLessonId('CO', 'lesson', '10_12');
    $kit->check('7_9 CO lesson resolves', is_int($co79) && $co79 > 0);
    $kit->check('10_12 CO lesson resolves', is_int($co1012) && $co1012 > 0);
    $kit->check('band lessons are distinct posts', $co79 !== $co1012 && $co79 !== $coLesson);
    $dto79 = $co79 !== null ? $content->getLesson($co79) : null;
    $kit->checkSame('7_9 DTO carries its own band', '7_9', $dto79['gradeGroup'] ?? null);
    $kit->check('7_9 body differs from the 4_6 starter', trim((string) ($dto79['content'] ?? '')) !== trim((string) ($dto['content'] ?? '')));
    // Fallback now applies only to a band the library truly does not cover.
    $kit->checkSame('unknown band still falls back to a real lesson', $coLesson, $content->findLessonId('CO', 'lesson', '99_99'));

    // Catalog scoping + service delegation. Per competency: 3 types x 3 bands.
    $kit->check('catalog lists the whole library', count($content->listLessons(null)) >= 54);
    $kit->checkSame('CO catalog has three node types for each band', 9, count($content->listLessons('CO')));
    $kit->checkSame('LessonService delegates to the repository', $coLesson, $lessons->lessonIdFor('CO', 'lesson', '4_6'));
    $kit->checkSame('LessonService resolves band content too', $co79, $lessons->lessonIdFor('CO', 'lesson', '7_9'));

    // The Learning Path binds every node to matching content, additively.
    $studentId = $kit->makeStudent('4_6');
    $teenId    = $kit->makeStudent('7_9');
    try {
        $path = (new LearningPathService())->build($studentId);

        $bound = 0;
        $total = 0;
        $consistent = true;
        foreach ($path['units'] as $unit) {
            $code = $unit['competencyTarget'];
            foreach ($unit['nodes'] as $node) {
                $total++;
                $expected = $content->findLessonId($code, $node['type'], '4_6');
                if (($node['contentId'] ?? null) === $expected) {
                    $bound++;
                } else {
                    $consistent = false;
                }
            }
        }
        $kit->checkSame('path emits eighteen nodes', 18, $total);
        $kit->checkSame('every node is bound to the matching lesson', 18, $bound);
        $kit->check('node->lesson binding is consistent with the repository', $consistent);

        // PA17b: a 7_9 learner's path binds to 7_9 lessons, never the starter.
        $teenPath = (new LearningPathService())->build($teenId);
        $bandOk = true;
        $teenTotal = 0;
        foreach ($teenPath['units'] as $unit) {
            $code = $unit['competencyTarget'];
            foreach ($unit['nodes'] as $node) {
                $teenTotal++;
                $lesson = $content->getLesson((int) ($node['contentId'] ?? 0));
                if (($lesson['gradeGroup'] ?? '') !== '7_9') {
                    $bandOk = false;
                }
            }
        }
        $kit->checkSame('teen path emits eighteen nodes', 18, $teenTotal);
        $kit->check('teen path binds only 7_9 content', $bandOk);
        // Determinism across builds for the same band.
        $teenPath2 = (new LearningPathService())->build($teenId);
        $kit->check('teen path is deterministic', json_encode($teenPath['units']) === json_encode($teenPath2['units']));
    } finally {
        $kit->cleanup($studentId);
        $kit->cleanup($teenId);
    }
};
