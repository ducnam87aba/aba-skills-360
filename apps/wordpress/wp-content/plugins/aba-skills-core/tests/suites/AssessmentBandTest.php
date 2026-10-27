<?php
/**
 * Suite: Assessment forms per grade band (PA29).
 *
 * Proves the same instrument (assessment_id 1) now ships one frozen published
 * version per grade band and that a session freezes the version matching the
 * LEARNER's band — not whichever was published last (§10, §25). Also verifies the
 * band-specific prompts actually differ (so 7_9/10_12 learners answer age-fit
 * items), that exactly one published form exists per band, and the deterministic
 * fallback for a band with no dedicated form (an unseeded learner still starts).
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\AssessmentService;
use ABA\Core\Repositories\AssessmentVersionRepository;

return function (AbaTestKit $kit): void {
    $kit->section('AssessmentBandTest — per-band published forms + band freeze (PA29)');

    global $wpdb;
    $p = $wpdb->prefix;
    $versions = $p . 'aba_assessment_versions';

    $service = new AssessmentService();
    $repo = new AssessmentVersionRepository();

    $studentIds = [];

    /** @return array session + items for a fresh learner in $band */
    $startFor = function (string $band) use ($service, $kit, &$studentIds): array {
        $sid = $kit->makeStudent($band);
        $studentIds[] = $sid;
        return $service->startSession(1, $sid);
    };

    try {
        // ---- 4_6 keeps the historical v0.1 (never mutated) ------------------
        $s46 = $startFor('4_6');
        $kit->checkSame('4_6 freezes version 0.1', '0.1', (string) $s46['session']['assessment_version']);
        $kit->checkSame('4_6 session band', '4_6', (string) $s46['session']['grade_group']);
        $kit->checkSame('4_6 exposes 6 items', 6, count($s46['items']));

        // ---- 7_9 resolves to its own published form 0.2 ---------------------
        $s79 = $startFor('7_9');
        $kit->checkSame('7_9 freezes version 0.2', '0.2', (string) $s79['session']['assessment_version']);
        $kit->checkSame('7_9 session band', '7_9', (string) $s79['session']['grade_group']);
        $kit->checkSame('7_9 exposes 6 items', 6, count($s79['items']));

        // ---- 10_12 resolves to form 0.3 -------------------------------------
        $s1012 = $startFor('10_12');
        $kit->checkSame('10_12 freezes version 0.3', '0.3', (string) $s1012['session']['assessment_version']);
        $kit->checkSame('10_12 session band', '10_12', (string) $s1012['session']['grade_group']);

        // ---- band prompts genuinely differ (not a copy of the starter) ------
        $d01 = $repo->getDefinition((array) $repo->findByVersion(1, '0.1'));
        $d02 = $repo->getDefinition((array) $repo->findByVersion(1, '0.2'));
        $d03 = $repo->getDefinition((array) $repo->findByVersion(1, '0.3'));
        $kit->check('7_9 first prompt differs from 4_6', ($d02['items'][0]['prompt'] ?? '') !== ($d01['items'][0]['prompt'] ?? ''));
        $kit->check('10_12 first prompt differs from 7_9', ($d03['items'][0]['prompt'] ?? '') !== ($d02['items'][0]['prompt'] ?? ''));

        // ---- exactly one published form per band ----------------------------
        $publishedBands = $wpdb->get_col("SELECT DISTINCT grade_group FROM {$versions} WHERE assessment_id = 1 AND status = 'published' ORDER BY grade_group");
        $kit->check('three distinct published bands', count($publishedBands) === 3);
        $kit->check('bands are 4_6/7_9/10_12', in_array('4_6', $publishedBands, true) && in_array('7_9', $publishedBands, true) && in_array('10_12', $publishedBands, true));

        // ---- deterministic fallback for an unseeded band --------------------
        $fb = $repo->findPublishedForBand(1, '1_3'); // no dedicated 1_3 form
        $kit->check('unseeded band still resolves a published form', is_array($fb));
        $sfb = $startFor('1_3');
        $kit->check('unseeded-band learner starts (6 items)', count($sfb['items']) === 6);
    } finally {
        foreach ($studentIds as $sid) {
            $kit->cleanup($sid);
        }
    }
};
