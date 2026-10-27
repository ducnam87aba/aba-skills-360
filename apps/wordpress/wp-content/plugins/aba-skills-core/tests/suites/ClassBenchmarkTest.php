<?php
/**
 * Suite: Class benchmark aggregate + k-anonymity floor (PA31 / PA33).
 *
 * Verifies the anonymized class average next to the learner's own score (§11):
 * the aggregate mean + sample count per competency, NULL (insufficient-evidence)
 * rows excluded so they never drag the mean (§45), measurement sources kept
 * separate (§54), a class-less learner yields an empty benchmark (never fabricated),
 * the endpoint enforces view permission (parent/child link), AND the k-anonymity
 * floor (§8): a cell's mean is published only when >= 5 distinct peers contributed
 * — a thin mean (or a small class) is suppressed so it cannot isolate one learner.
 * Determinism is asserted too.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\SkillsProfileService;
use ABA\Core\Repositories\SkillScoreRepository;

return function (AbaTestKit $kit): void {
    $kit->section('ClassBenchmarkTest — class average + k-anonymity floor (§11/§8)');

    $scores = new SkillScoreRepository();
    $service = new SkillsProfileService();

    $school = $kit->makeSchool('SCH-31', 'Trường Benchmark 31');
    $class  = $kit->makeClass($school, '3A1', 'Lớp 3A1', '4_6');   // reliable: 6 members
    $small  = $kit->makeClass($school, '3B1', 'Lớp 3B1', '4_6');    // unreliable: 2 members

    // Big class (>= 5 contributors => means are published).
    $a = $kit->makeStudent('4_6', $school, $class);
    $b = $kit->makeStudent('4_6', $school, $class);
    $c = $kit->makeStudent('4_6', $school, $class);
    $d = $kit->makeStudent('4_6', $school, $class);
    $e = $kit->makeStudent('4_6', $school, $class);
    $f = $kit->makeStudent('4_6', $school, $class);
    $z = $kit->makeStudent('4_6', $school, 0);      // class-less learner
    // Small class (below the floor => every cell is suppressed).
    $s1 = $kit->makeStudent('4_6', $school, $small);
    $s2 = $kit->makeStudent('4_6', $school, $small);

    // SA scored by all six => samples 6 >= 5 => mean published. (80+40+70+60+90+50)/6 = 65.0
    foreach ([$a => 80.0, $b => 40.0, $c => 70.0, $d => 60.0, $e => 90.0, $f => 50.0] as $sid => $v) {
        $scores->upsertScore($sid, 'SA', 'core_competency', $v, 'developing', 0, 'student', 'ABA-FREQ-0.1');
    }
    // ER: b is NULL (excluded) => 5 valid contributors still clear the floor. (100+50+60+70+80)/5 = 72.0
    $scores->upsertScore($a, 'ER', 'core_competency', 100.0, 'advanced', 0, 'student', 'ABA-FREQ-0.1');
    $scores->upsertScore($b, 'ER', 'core_competency', null, 'beginning', 0, 'student', 'ABA-FREQ-0.1', 'insufficient_evidence');
    $scores->upsertScore($c, 'ER', 'core_competency', 50.0, 'developing', 0, 'student', 'ABA-FREQ-0.1');
    $scores->upsertScore($d, 'ER', 'core_competency', 60.0, 'developing', 0, 'student', 'ABA-FREQ-0.1');
    $scores->upsertScore($e, 'ER', 'core_competency', 70.0, 'proficient', 0, 'student', 'ABA-FREQ-0.1');
    $scores->upsertScore($f, 'ER', 'core_competency', 80.0, 'proficient', 0, 'student', 'ABA-FREQ-0.1');
    // CO: only four scored => below the floor => class mean suppressed (samples kept).
    foreach ([$a => 60.0, $c => 90.0, $d => 70.0, $e => 80.0] as $sid => $v) {
        $scores->upsertScore($sid, 'CO', 'core_competency', $v, 'developing', 0, 'student', 'ABA-FREQ-0.1');
    }
    // A teacher-source SA that must NOT blend into the student projection.
    $scores->upsertScore($a, 'SA', 'core_competency', 10.0, 'beginning', 0, 'teacher', 'ABA-FREQ-0.1');
    // Small class: both score SA, but two contributors cannot be published.
    $scores->upsertScore($s1, 'SA', 'core_competency', 90.0, 'advanced', 0, 'student', 'ABA-FREQ-0.1');
    $scores->upsertScore($s2, 'SA', 'core_competency', 30.0, 'beginning', 0, 'student', 'ABA-FREQ-0.1');

    /** find one competency row by code in a benchmark result. */
    $find = static function (array $bm, string $code): ?array {
        foreach ($bm['competencies'] as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }
        return null;
    };

    try {
        $bm = $service->getClassBenchmark($a, 'student');
        $kit->checkSame('benchmark resolves the learner class', $class, $bm['classId']);
        $kit->checkSame('class has six members', 6, $bm['memberCount']);
        $kit->check('class is reliable (>= floor)', $bm['reliable'] === true);
        $kit->checkSame('reports the k-anonymity floor', 5, $bm['minSamples']);

        $sa = $find($bm, 'SA');
        $kit->check('SA present', is_array($sa));
        $kit->checkSame('SA student own score', 80.0, (float) $sa['studentScore']);
        $kit->checkClose('SA class mean over 6 published (== 65.0)', 65.0, (float) $sa['classAverage']);
        $kit->checkSame('SA samples 6', 6, $sa['samples']);

        $er = $find($bm, 'ER');
        $kit->check('ER mean excludes the NULL peer', 5, $er['samples']);
        $kit->checkClose('ER class mean over 5 valid (72.0)', 72.0, (float) $er['classAverage']);
        $kit->checkSame('ER student own score ignores teacher blend', 100.0, (float) $er['studentScore']);

        // k-anonymity (§8): four contributors is below the floor => mean suppressed,
        // but the learner's own score and the true sample count still show.
        $co = $find($bm, 'CO');
        $kit->check('CO class mean suppressed below the floor', $co['classAverage'] === null);
        $kit->checkSame('CO samples still reported (4)', 4, $co['samples']);
        $kit->checkSame('CO learner own score retained', 60.0, (float) $co['studentScore']);

        $kit->checkSame('teacher source did not leak into student SA own', 80.0, (float) $sa['studentScore']);
        $kit->check('unscored competency SM is omitted', $find($bm, 'SM') === null);

        // Determinism.
        $bm2 = $service->getClassBenchmark($a, 'student');
        $kit->check('benchmark is deterministic', $bm === $bm2);

        // Small class below the floor => class not reliable, every cell suppressed.
        $sb = $service->getClassBenchmark($s1, 'student');
        $kit->check('small class is not reliable', $sb['reliable'] === false);
        $kit->checkSame('small class memberCount 2', 2, $sb['memberCount']);
        $ssa = $find($sb, 'SA');
        $kit->check('small class SA mean suppressed', $ssa['classAverage'] === null);
        $kit->checkSame('small class SA own still shown', 90.0, (float) $ssa['studentScore']);

        // Class-less learner => empty, never fabricated.
        $z0 = $service->getClassBenchmark($z, 'student');
        $kit->check('class-less benchmark has null classId', $z0['classId'] === null);
        $kit->check('class-less not reliable', $z0['reliable'] === false);
        $kit->check('class-less competencies empty', $z0['competencies'] === []);

        // ---- REST permission (§8) -------------------------------------------
        wp_set_current_user(0);
        $anon = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $a . '/skills/benchmark'));
        $kit->checkSame('anon benchmark is rejected (401)', 401, $anon->get_status());

        $parent = $kit->makeParent('Benchmark Parent');
        $kit->linkParentStudent($parent, $a);
        wp_set_current_user($parent);
        $ok = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $a . '/skills/benchmark'));
        $kit->checkSame('linked parent reads benchmark (200)', 200, $ok->get_status());
        $body = $ok->get_data();
        $kit->checkSame('parent sees class id in benchmark', $class, $body['classId']);
        $kit->checkSame('parent sees member count', 6, $body['memberCount']);
        $kit->check('benchmark body carries reliable flag', $body['reliable'] === true);

        $stranger = $kit->makeParent('Stranger Parent');
        wp_set_current_user($stranger);
        $no = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $a . '/skills/benchmark'));
        $kit->checkSame('unlinked parent is forbidden (403)', 403, $no->get_status());

        wp_set_current_user(0);
    } finally {
        foreach ([$a, $b, $c, $d, $e, $f, $z, $s1, $s2] as $sid) {
            $kit->cleanup($sid);
        }
        if (isset($parent)) {
            $kit->cleanupUser($parent);
        }
        if (isset($stranger)) {
            $kit->cleanupUser($stranger);
        }
        $kit->cleanupClass($class);
        $kit->cleanupClass($small);
        $kit->cleanupSchool($school);
    }
};
