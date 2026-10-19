<?php

namespace ABA\Core\Services;

use ABA\Core\Repositories\AssessmentSessionRepository;
use ABA\Core\Repositories\AssessmentResponseRepository;
use ABA\Core\Repositories\SkillScoreRepository;
use ABA\Core\Repositories\SkillSnapshotRepository;
use ABA\Core\Repositories\XpTransactionRepository;
use ABA\Core\Scoring\ScoringEngine;

class AssessmentService {
    private AssessmentSessionRepository $sessionRepo;
    private AssessmentResponseRepository $responseRepo;
    private SkillScoreRepository $skillScoreRepo;
    private SkillSnapshotRepository $snapshotRepo;
    private XpTransactionRepository $xpRepo;
    private ScoringEngine $scoringEngine;

    public function __construct() {
        $this->sessionRepo = new AssessmentSessionRepository();
        $this->responseRepo = new AssessmentResponseRepository();
        $this->skillScoreRepo = new SkillScoreRepository();
        $this->snapshotRepo = new SkillSnapshotRepository();
        $this->xpRepo = new XpTransactionRepository();
        $this->scoringEngine = new ScoringEngine();
    }

    /**
     * Start a new assessment session
     */
    public function startSession(int $assessmentId, int $studentId, int $schoolId = 1): array {
        $sessionId = $this->sessionRepo->create([
            'assessment_id' => $assessmentId,
            'student_id' => $studentId,
            'school_id' => $schoolId,
            'assessment_version' => '1.0.0',
            'scoring_strategy' => 'standard',
            'total_steps' => 6,
        ]);

        return $this->sessionRepo->findById($sessionId);
    }

    /**
     * Save an individual question response
     */
    public function saveResponse(int $sessionId, int $questionId, int $studentId, $value, ?int $latencyMs = null): bool {
        return $this->responseRepo->saveResponse($sessionId, $questionId, $studentId, (string)$value, $latencyMs);
    }

    /**
     * Complete the session, score via ScoringEngine, generate SkillSnapshot, update SkillScores, award XP
     */
    public function completeSession(int $sessionId, array $questionDefinitions): array {
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            throw new \Exception('Assessment session not found.');
        }

        $studentId = (int)$session['student_id'];
        $responses = $this->responseRepo->getResponsesBySession($sessionId);

        // Run Scoring Engine
        $evaluation = $this->scoringEngine->evaluate($questionDefinitions, $responses);

        // Update session status and score
        $this->sessionRepo->updateStatus($sessionId, 'completed', $evaluation['overallScore']);

        // Update current Skill Scores in wp_aba_skill_scores
        foreach ($evaluation['competencyScores'] as $cScore) {
            $this->skillScoreRepo->upsertScore(
                $studentId,
                $cScore['skillCode'],
                $cScore['category'],
                (float)$cScore['score'],
                $cScore['level'],
                $sessionId
            );
        }

        // Generate persistent immutable Skill Snapshot
        $snapshotId = $this->snapshotRepo->create(
            $studentId,
            $sessionId,
            $evaluation['competencyScores'],
            $evaluation['qualityFlags']
        );

        // Record XP in ledger: +50 XP for assessment completion
        $this->xpRepo->recordTransaction(
            $studentId,
            50,
            'assessment',
            (string)$sessionId,
            'Hoàn thành bài đánh giá năng lực 360°'
        );

        return [
            'sessionId' => $sessionId,
            'status' => 'completed',
            'overallScore' => $evaluation['overallScore'],
            'snapshotId' => $snapshotId,
            'scores' => $evaluation['competencyScores'],
            'qualityFlags' => $evaluation['qualityFlags'],
            'xpAwarded' => 50,
        ];
    }
}
