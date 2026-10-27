<?php

namespace ABA\Core\Services;

use ABA\Core\Repositories\AssessmentSessionRepository;
use ABA\Core\Repositories\AssessmentResponseRepository;
use ABA\Core\Repositories\AssessmentVersionRepository;
use ABA\Core\Repositories\ScoreRunRepository;
use ABA\Core\Repositories\SkillScoreRepository;
use ABA\Core\Repositories\SkillSnapshotRepository;
use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Repositories\XpTransactionRepository;
use ABA\Core\Scoring\ScoringEngine;
use ABA\Core\Exceptions\ApiException;

class AssessmentService {
    private AssessmentSessionRepository $sessionRepo;
    private AssessmentResponseRepository $responseRepo;
    private AssessmentVersionRepository $versionRepo;
    private ScoreRunRepository $scoreRunRepo;
    private SkillScoreRepository $skillScoreRepo;
    private SkillSnapshotRepository $snapshotRepo;
    private StudentRepository $studentRepo;
    private XpTransactionRepository $xpRepo;
    private ScoringEngine $scoringEngine;

    /** XP awarded once per completed assessment, independent of answers (§80-81). */
    private const COMPLETION_XP = 50;

    public function __construct() {
        $this->sessionRepo = new AssessmentSessionRepository();
        $this->responseRepo = new AssessmentResponseRepository();
        $this->versionRepo = new AssessmentVersionRepository();
        $this->scoreRunRepo = new ScoreRunRepository();
        $this->skillScoreRepo = new SkillScoreRepository();
        $this->snapshotRepo = new SkillSnapshotRepository();
        $this->studentRepo = new StudentRepository();
        $this->xpRepo = new XpTransactionRepository();
        $this->scoringEngine = new ScoringEngine();
    }

    /**
     * Start or resume an assessment against the published form for the learner's
     * grade band (PA29). The session freezes assessment_version + respondent_type
     * + grade_group so later rescoring reads the exact definition it was answered
     * under (§10, §25).
     *
     * @return array {session: array, items: array, resume: bool}
     */
    public function startSession(int $assessmentId, int $studentId): array {
        // Resume: reuse an active in-progress session rather than duplicating (§27).
        $existing = $this->sessionRepo->findInProgress($assessmentId, $studentId);
        if ($existing) {
            $version = $this->versionRepo->findByVersion($assessmentId, (string)$existing['assessment_version']);
            $definition = $version ? $this->versionRepo->getDefinition($version) : ['items' => []];
            return ['session' => $existing, 'items' => $definition['items'], 'resume' => true];
        }

        $student = $this->studentRepo->findById($studentId);
        $gradeGroup = (string)($student['grade_group'] ?? '');

        // PA29: freeze the published form that matches the learner's band, falling
        // back to the general latest published version for an unseeded band.
        $version = $this->versionRepo->findPublishedForBand($assessmentId, $gradeGroup);
        if (!$version) {
            throw new ApiException('ASSESSMENT_NOT_AVAILABLE', 'No published assessment version is available.', 403);
        }
        $definition = $this->versionRepo->getDefinition($version);
        $itemCount = count($definition['items']);
        if ($itemCount === 0) {
            throw new ApiException('ASSESSMENT_NOT_AVAILABLE', 'Published assessment has no questions.', 403);
        }

        $sessionId = $this->sessionRepo->create([
            'assessment_id' => $assessmentId,
            'student_id' => $studentId,
            'school_id' => (int)($student['school_id'] ?? 1),
            'assessment_version' => (string)$version['version'],
            'respondent_type' => (string)($version['respondent_type'] ?? 'student'),
            'grade_group' => $gradeGroup !== '' ? $gradeGroup : (string)($version['grade_group'] ?? ''),
            'scoring_strategy' => (string)($definition['scoring']['scoringVersion'] ?? 'standard'),
            'total_steps' => $itemCount,
        ]);

        return ['session' => $this->sessionRepo->findById($sessionId), 'items' => $definition['items'], 'resume' => false];
    }

    /**
     * Autosave one response. Rejects writes to a completed session (immutability,
     * §27 / api-contract §29 409 SESSION_COMPLETED) and unsupported answers (§422).
     */
    public function recordResponse(int $sessionId, int $studentId, int $questionId, $answer, ?int $latencyMs = null, ?string $missingReason = null): array {
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            throw new ApiException('SESSION_NOT_FOUND', 'Assessment session not found.', 404);
        }
        if ($session['status'] === 'completed') {
            throw new ApiException('SESSION_COMPLETED', 'Responses cannot be written to a completed session.', 409);
        }

        // Normalize the answer payload (likert int | scenario selected option | missing).
        $value = $this->normalizeAnswer($answer);
        if ($value === null && ($missingReason === null || $missingReason === '')) {
            throw new ApiException('INVALID_RESPONSE', 'An answer or a missing reason is required.', 422);
        }

        $this->responseRepo->saveResponse($sessionId, $questionId, $studentId, (string)($value ?? ''), $latencyMs, $missingReason);

        return [
            'saved' => true,
            'questionId' => $questionId,
            'progress' => $this->progress($sessionId, (int)$session['total_steps']),
        ];
    }

    /**
     * Complete + score the session. Idempotent: a repeated call returns the stored
     * result and never re-awards XP (§31, invariant around completion). Every
     * scoring execution is recorded as a score run; history is never overwritten
     * (§52, §114, invariant #3).
     */
    public function completeSession(int $sessionId): array {
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            throw new ApiException('SESSION_NOT_FOUND', 'Assessment session not found.', 404);
        }

        // Idempotent replay: rebuild the response from the last completed run.
        if ($session['status'] === 'completed') {
            return $this->replayCompleted($session);
        }

        $studentId = (int)$session['student_id'];
        $assessmentId = (int)$session['assessment_id'];
        $assessmentVersion = (string)$session['assessment_version'];
        $respondentType = (string)($session['respondent_type'] ?? 'student');

        // Resolve the FROZEN definition the session was started under (§22).
        $version = $this->versionRepo->findByVersion($assessmentId, $assessmentVersion);
        if (!$version) {
            throw new ApiException('ASSESSMENT_VERSION_NOT_FOUND', 'The frozen assessment version is missing.', 500);
        }
        $definition = $this->versionRepo->getDefinition($version);
        $scoring = $definition['scoring'] ?? [];
        $scoringVersion = (string)($scoring['scoringVersion'] ?? $version['scoring_version'] ?? 'ABA-FREQ-0.1');
        $minimumEvidence = $scoring['minimumEvidence'] ?? ['default' => 1, 'bySkill' => []];

        // Open the score run ledger (§52).
        $runId = $this->scoreRunRepo->start($sessionId, $studentId, $assessmentVersion, $scoringVersion, $respondentType);

        $responses = $this->responseRepo->getResponsesBySession($sessionId);

        try {
            $evaluation = $this->scoringEngine->evaluate($definition['items'], $responses, [
                'scoringVersion' => $scoringVersion,
                'minimumEvidence' => $minimumEvidence,
            ]);
        } catch (\Throwable $e) {
            $this->scoreRunRepo->markFailed($runId, $e->getMessage());
            throw new ApiException('SCORING_FAILED', 'Scoring could not be completed.', 500);
        }

        // Persist current projection with provenance; null score for no-evidence (§45, §53).
        foreach ($evaluation['competencyScores'] as $cScore) {
            $this->skillScoreRepo->upsertScore(
                $studentId,
                $cScore['skillCode'],
                $cScore['category'],
                $cScore['score'] === null ? null : (float)$cScore['score'],
                $cScore['level'],
                $sessionId,
                $respondentType,
                $scoringVersion,
                $cScore['status'] ?? 'valid'
            );
        }

        $snapshotId = $this->snapshotRepo->create(
            $studentId,
            $sessionId,
            $evaluation['competencyScores'],
            $evaluation['qualityFlags']
        );

        $this->sessionRepo->updateStatus($sessionId, 'completed', $evaluation['overallScore']);

        // XP ledger, idempotent per session: +50 regardless of which answers (§80-81).
        $this->xpRepo->recordTransaction(
            $studentId,
            self::COMPLETION_XP,
            'assessment',
            (string)$sessionId,
            'Hoàn thành bài đánh giá năng lực 360°',
            'assessment_completed:' . $sessionId
        );

        $this->scoreRunRepo->complete($runId, $evaluation['competencyScores'], $evaluation['qualityFlags']);

        return [
            'sessionId' => $sessionId,
            'status' => 'completed',
            'overallScore' => $evaluation['overallScore'],
            'snapshotId' => $snapshotId,
            'scores' => $evaluation['competencyScores'],
            'qualityFlags' => $evaluation['qualityFlags'],
            'scoringVersion' => $scoringVersion,
            'xpAwarded' => self::COMPLETION_XP,
            'idempotent' => false,
        ];
    }

    /** Read-only session state for resume (api-contract §28). */
    public function getSessionState(int $sessionId): array {
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            throw new ApiException('ASSESSMENT_SESSION_NOT_FOUND', 'Assessment session not found.', 404);
        }
        return [
            'id' => (int)$session['id'],
            'assessmentId' => (int)$session['assessment_id'],
            'assessmentVersion' => $session['assessment_version'],
            'studentId' => (int)$session['student_id'],
            'status' => $session['status'],
            'startedAt' => $session['started_at'],
            'progress' => $this->progress($sessionId, (int)$session['total_steps']),
        ];
    }

    /**
     * Public metadata for an assessment (api-contract §26). Never returns the
     * question bank or scoring key; the client sees only descriptive fields.
     */
    public function getAssessmentMeta(int $assessmentId): array {
        $version = $this->versionRepo->findPublished($assessmentId);
        if (!$version) {
            throw new ApiException('ASSESSMENT_NOT_FOUND', 'Assessment not found.', 404);
        }
        $definition = $this->versionRepo->getDefinition($version);
        $title = function_exists('get_the_title') ? get_the_title($assessmentId) : '';

        return [
            'id' => $assessmentId,
            'code' => $version['assessment_code'],
            'title' => $title !== '' ? $title : (string)$version['assessment_code'],
            'version' => (string)$version['version'],
            'gradeGroup' => (string)($version['grade_group'] ?? ''),
            'respondentType' => (string)($version['respondent_type'] ?? 'student'),
            'recallPeriod' => (string)($version['recall_period'] ?? 'last_2_weeks'),
            'questionCount' => count($definition['items']),
        ];
    }

    /** Frozen items for a session's version, for the next-question batch (§30). */
    public function getSessionItems(int $sessionId): array {
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            throw new ApiException('ASSESSMENT_SESSION_NOT_FOUND', 'Assessment session not found.', 404);
        }
        $version = $this->versionRepo->findByVersion((int)$session['assessment_id'], (string)$session['assessment_version']);
        $definition = $version ? $this->versionRepo->getDefinition($version) : ['items' => []];
        return $definition['items'];
    }

    private function replayCompleted(array $session): array {
        $sessionId = (int)$session['id'];
        $latest = $this->scoreRunRepo->findLatestCompleted($sessionId);
        $scores = [];
        $qualityFlags = [];
        if ($latest) {
            $scores = json_decode((string)$latest['scores_json'], true) ?: [];
            $qualityFlags = json_decode((string)$latest['quality_flags_json'], true) ?: [];
        }
        return [
            'sessionId' => $sessionId,
            'status' => 'completed',
            'overallScore' => $session['normalized_score'] !== null ? (float)$session['normalized_score'] : null,
            'snapshotId' => null,
            'scores' => $scores,
            'qualityFlags' => $qualityFlags,
            'scoringVersion' => $latest['scoring_version'] ?? null,
            'xpAwarded' => 0,
            'idempotent' => true,
        ];
    }

    private function normalizeAnswer($answer): ?string {
        if ($answer === null) {
            return null;
        }
        if (is_array($answer)) {
            if (isset($answer['selectedOption'])) {
                return (string)$answer['selectedOption'];
            }
            if (array_key_exists('value', $answer)) {
                return (string)$answer['value'];
            }
            return null;
        }
        $stringified = trim((string)$answer);
        return $stringified === '' ? null : $stringified;
    }

    private function progress(int $sessionId, int $total): array {
        $answered = $this->responseRepo->countAnswered($sessionId);
        return [
            'answered' => $answered,
            'total' => $total,
            'percent' => $total > 0 ? round(($answered / $total) * 100, 2) : 0.0,
        ];
    }
}
