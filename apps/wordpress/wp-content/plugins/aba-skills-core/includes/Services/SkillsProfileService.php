<?php

namespace ABA\Core\Services;

use ABA\Core\Repositories\SkillScoreRepository;
use ABA\Core\Repositories\SkillSnapshotRepository;
use ABA\Core\Repositories\StudentRepository;

class SkillsProfileService {
    // k-anonymity floor (§8): a class average is published only when at least this
    // many learners contributed to it. Below the floor the mean could isolate (and
    // so leak) one peer's sensitive score, so that cell is suppressed.
    private const MIN_CLASS_SAMPLES = 5;

    private SkillScoreRepository $scoreRepo;
    private SkillSnapshotRepository $snapshotRepo;
    private StudentRepository $studentRepo;

    public function __construct() {
        $this->scoreRepo = new SkillScoreRepository();
        $this->snapshotRepo = new SkillSnapshotRepository();
        $this->studentRepo = new StudentRepository();
    }

    /**
     * Current projection for one measurement source. Defaults to the learner's
     * self-report; teacher / parent rows are kept separate and never blended
     * (assessment-spec.md §54).
     */
    public function getCurrentScores(int $studentId, string $sourceType = 'student'): array {
        $rows = $this->scoreRepo->getScoresByStudent($studentId, $sourceType);

        $competencyNames = [
            'SA' => 'Tự nhận thức',
            'ER' => 'Quản lý cảm xúc',
            'SM' => 'Tự quản lý',
            'CO' => 'Giao tiếp',
            'CE' => 'Hợp tác và đồng cảm',
            'PD' => 'Giải quyết vấn đề và ra quyết định',
        ];

        $results = [];
        foreach ($rows as $row) {
            $code = $row['skill_code'];
            $status = $row['score_status'] ?? 'valid';
            $rawScore = $row['score'] ?? null;
            // Insufficient evidence stores NULL; never render it as 0 (§45).
            $isInsufficient = $status === 'insufficient_evidence' || $rawScore === null || $rawScore === '';

            $results[] = [
                'skillId' => (int)$row['id'],
                'skillCode' => $code,
                'skillName' => $competencyNames[$code] ?? $code,
                'category' => $row['skill_category'],
                'score' => $isInsufficient ? null : (float)$rawScore,
                'maxScore' => 100,
                'level' => $row['level'],
                'status' => $isInsufficient ? 'insufficient_evidence' : 'valid',
                'sourceType' => $row['source_type'] ?? 'student',
                'scoringVersion' => $row['scoring_version'] ?? null,
                'updatedAt' => $row['updated_at'],
            ];
        }

        return $results;
    }

    public function getHistory(int $studentId): array {
        $snapshots = $this->snapshotRepo->getHistoryByStudent($studentId);

        $history = [];
        foreach ($snapshots as $snap) {
            $parsed = json_decode($snap['scores_json'], true);
            $scoresMap = [];

            if (!empty($parsed['scores'])) {
                foreach ($parsed['scores'] as $s) {
                    // Preserve null (insufficient evidence) in history too (§45).
                    $scoresMap[$s['skillCode']] = ($s['score'] ?? null) === null ? null : (float)$s['score'];
                }
            }

            $history[] = [
                'snapshotId' => (int)$snap['id'],
                'date' => substr($snap['snapshot_date'], 0, 10),
                'scores' => $scoresMap,
            ];
        }

        return $history;
    }

    /**
     * The learner's current score set next to the anonymized class average (§11).
     * Composition stays within Skills + Student identity (no School coupling);
     * the class *name* is intentionally omitted — the parent UI already holds it
     * from the child context (PA26), and the benchmark only exposes aggregates.
     *
     * A learner with no class (class_id 0) yields an empty benchmark rather than a
     * fabricated comparison.
     */
    public function getClassBenchmark(int $studentId, string $sourceType = 'student'): array {
        $student = $this->studentRepo->findById($studentId); // raw joined row
        $classId = (int)($student['class_id'] ?? 0);

        // The learner's own current projection drives the student side.
        $own = [];
        foreach ($this->getCurrentScores($studentId, $sourceType) as $s) {
            $own[$s['skillCode']] = $s['score']; // number|null
        }

        if ($classId <= 0) {
            return [
                'classId' => null,
                'memberCount' => 0,
                'sourceType' => $sourceType,
                'reliable' => false,
                'minSamples' => self::MIN_CLASS_SAMPLES,
                'competencies' => [],
            ];
        }

        $memberIds = array_map(static fn($r) => (int)$r['id'], $this->studentRepo->listByClass($classId));
        $agg = $this->scoreRepo->getClassAggregate($memberIds, $sourceType);
        $memberCount = count($memberIds);

        $competencies = [];
        foreach (['SA', 'ER', 'SM', 'CO', 'CE', 'PD'] as $code) {
            $hasOwn = array_key_exists($code, $own);
            $hasClass = isset($agg[$code]);
            if (!$hasOwn && !$hasClass) {
                continue;
            }
            $samples = $hasClass ? $agg[$code]['count'] : 0;
            // k-anonymity (§8): publish the class mean only when enough peers
            // contributed; a thin mean could isolate one learner's score. The
            // learner's own score always stays.
            $publish = $samples >= self::MIN_CLASS_SAMPLES;
            $competencies[] = [
                'code' => $code,
                'studentScore' => $own[$code] ?? null,
                'classAverage' => $publish ? $agg[$code]['avg'] : null,
                'samples' => $samples,
            ];
        }

        return [
            'classId' => $classId,
            'memberCount' => $memberCount,
            'sourceType' => $sourceType,
            'reliable' => $memberCount >= self::MIN_CLASS_SAMPLES,
            'minSamples' => self::MIN_CLASS_SAMPLES,
            'competencies' => $competencies,
        ];
    }

    /**
     * Longitudinal class average (PA32, §11 comparisons over time): for each
     * snapshot date on which any classmate was measured, the mean score per
     * competency across those classmates. NULL (insufficient-evidence) scores are
     * excluded so a missing measure never skews the line (§45), and a cell is only
     * published when at least MIN_CLASS_SAMPLES distinct learners contributed to it
     * (k-anonymity, §8). Only per-date aggregates are returned — never an
     * individual peer's series. A class-less (or too-small) learner yields an empty
     * trend rather than a fabricated baseline.
     *
     * @return array<int, array{date: string, scores: array<string, float>}>
     */
    public function getClassTrend(int $studentId): array {
        $student = $this->studentRepo->findById($studentId); // raw joined row
        $classId = (int)($student['class_id'] ?? 0);
        if ($classId <= 0) {
            return [];
        }

        $memberIds = array_map(static fn($r) => (int)$r['id'], $this->studentRepo->listByClass($classId));
        $snapshots = $this->snapshotRepo->getSnapshotsForStudents($memberIds);

        // date => competency => studentId => score. Keyed by student so a peer's
        // same-date retake counts once — the anonymity floor is per learner, not
        // per snapshot row.
        $byDate = [];
        foreach ($snapshots as $snap) {
            $date = substr((string)$snap['snapshot_date'], 0, 10);
            $sid = (int)$snap['student_id'];
            $parsed = json_decode((string)$snap['scores_json'], true);
            if (empty($parsed['scores'])) {
                continue;
            }
            foreach ($parsed['scores'] as $s) {
                if (($s['score'] ?? null) === null) {
                    continue; // §45 missing measure excluded
                }
                $byDate[$date][(string)$s['skillCode']][$sid] = (float)$s['score'];
            }
        }

        // ISO dates sort lexicographically == chronologically.
        ksort($byDate);

        $trend = [];
        foreach ($byDate as $date => $codes) {
            $scores = [];
            foreach ($codes as $code => $vals) {
                if (count($vals) < self::MIN_CLASS_SAMPLES) {
                    continue; // k-anonymity (§8): suppress thin cells
                }
                $scores[$code] = round(array_sum($vals) / count($vals), 1);
            }
            if ($scores) {
                $trend[] = ['date' => $date, 'scores' => $scores];
            }
        }
        return $trend;
    }
}
