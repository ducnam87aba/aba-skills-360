<?php
/**
 * Suite: Assessment pipeline (end-to-end against the live published version).
 *
 * Exercises the real AssessmentService against the seeded ABA-B-S v0.1 (6 items:
 * PD/CO/ER scenario + SA/SM/CE likert). Verifies: version freeze at start
 * (§10/§25), resume reuse (§27), missing => insufficient_evidence persisted as
 * NULL (§43-46), descriptive overall, XP awarded once and idempotent on replay
 * (§80-81), a completed score run is recorded (§52), and a completed session is
 * immutable against further writes (SESSION_COMPLETED 409).
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\AssessmentService;
use ABA\Core\Repositories\SkillScoreRepository;
use ABA\Core\Repositories\ScoreRunRepository;
use ABA\Core\Exceptions\ApiException;

return function (AbaTestKit $kit): void {
    $kit->section('PipelineTest — assessment end-to-end');

    global $wpdb;
    $p = $wpdb->prefix;

    $studentId = $kit->makeStudent();
    $service = new AssessmentService();

    try {
        // --- start freezes the published version --------------------------
        $started = $service->startSession(1, $studentId);
        $session = $started['session'];
        $sessionId = (int) $session['id'];

        $kit->check('new session is not a resume', $started['resume'] === false);
        $kit->checkSame('definition exposes 6 items', 6, count($started['items']));
        $kit->checkSame('total_steps frozen to 6', 6, (int) $session['total_steps']);
        $kit->checkSame('version frozen to seeded 0.1', '0.1', (string) $session['assessment_version']);
        $kit->checkSame('respondent_type frozen to student', 'student', (string) $session['respondent_type']);

        // --- resume reuses the active session -----------------------------
        $again = $service->startSession(1, $studentId);
        $kit->check('second start resumes (no duplicate)', $again['resume'] === true && (int) $again['session']['id'] === $sessionId);

        // --- answer 5 items, mark CE as missing ---------------------------
        $service->recordResponse($sessionId, $studentId, 1, 'A', 3000); // PD -> 100
        $service->recordResponse($sessionId, $studentId, 2, 'B', 3000); // CO -> 100
        $service->recordResponse($sessionId, $studentId, 3, 'B', 3000); // ER -> 100
        $service->recordResponse($sessionId, $studentId, 4, 5, 3000);   // SA -> 100
        $service->recordResponse($sessionId, $studentId, 5, 3, 3000);   // SM -> 50
        $service->recordResponse($sessionId, $studentId, 6, null, 0, 'not_observed'); // CE missing

        // --- complete + score ---------------------------------------------
        $result = $service->completeSession($sessionId);

        $byCode = [];
        foreach ($result['scores'] as $c) {
            $byCode[$c['skillCode']] = $c;
        }

        $kit->checkSame('status completed', 'completed', $result['status']);
        $kit->checkSame('scoring version reported', 'ABA-FREQ-0.1', $result['scoringVersion']);
        $kit->checkClose('PD scenario best option => 100', 100.0, (float) $byCode['PD']['score']);
        $kit->checkSame('PD level advanced at 100', 'advanced', $byCode['PD']['level']);
        $kit->checkClose('SM likert raw 3 => 50', 50.0, (float) $byCode['SM']['score']);
        $kit->checkSame('SM level developing at 50', 'developing', $byCode['SM']['level']);
        $kit->check('CE missing => score NULL', $byCode['CE']['score'] === null);
        $kit->checkSame('CE missing => insufficient_evidence', 'insufficient_evidence', $byCode['CE']['status']);
        $kit->checkClose('overall = mean of 5 valid competencies (90)', 90.0, (float) $result['overallScore']);
        $kit->checkSame('completion XP awarded once (50)', 50, $result['xpAwarded']);
        $kit->check('first completion is not idempotent replay', $result['idempotent'] === false);

        // --- NULL persisted to the projection, never 0 (§45) --------------
        $rows = (new SkillScoreRepository())->getScoresByStudent($studentId, 'student');
        $ceRow = null;
        foreach ($rows as $row) {
            if ($row['skill_code'] === 'CE') {
                $ceRow = $row;
            }
        }
        $kit->check('CE persisted with NULL score in DB', $ceRow !== null && $ceRow['score'] === null);
        $kit->checkSame('CE persisted score_status insufficient_evidence', 'insufficient_evidence', $ceRow['score_status'] ?? null);

        // --- a completed score run is recorded (§52) ----------------------
        $run = (new ScoreRunRepository())->findLatestCompleted($sessionId);
        $kit->check('score run recorded as completed', $run !== null && $run['status'] === 'completed');

        // --- XP ledger idempotency ----------------------------------------
        $xpCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}aba_xp_transactions WHERE source_type = 'assessment' AND source_id = %s",
            (string) $sessionId
        ));
        $kit->checkSame('one assessment XP row for the session', 1, $xpCount);

        $replay = $service->completeSession($sessionId);
        $kit->check('replay flagged idempotent', $replay['idempotent'] === true);
        $kit->checkSame('replay awards no extra XP', 0, $replay['xpAwarded']);

        $xpCount2 = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}aba_xp_transactions WHERE source_type = 'assessment' AND source_id = %s",
            (string) $sessionId
        ));
        $kit->checkSame('XP still single row after replay', 1, $xpCount2);

        // --- completed session is immutable -------------------------------
        $threw = false;
        try {
            $service->recordResponse($sessionId, $studentId, 1, 'B', 1000);
        } catch (ApiException $e) {
            $threw = $e->apiCode() === 'SESSION_COMPLETED' && $e->status() === 409;
        }
        $kit->check('writing to a completed session is rejected (409 SESSION_COMPLETED)', $threw);
    } finally {
        $kit->cleanup($studentId);
    }
};
