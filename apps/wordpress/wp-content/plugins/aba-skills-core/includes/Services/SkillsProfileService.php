<?php

namespace ABA\Core\Services;

use ABA\Core\Repositories\SkillScoreRepository;
use ABA\Core\Repositories\SkillSnapshotRepository;

class SkillsProfileService {
    private SkillScoreRepository $scoreRepo;
    private SkillSnapshotRepository $snapshotRepo;

    public function __construct() {
        $this->scoreRepo = new SkillScoreRepository();
        $this->snapshotRepo = new SkillSnapshotRepository();
    }

    public function getCurrentScores(int $studentId): array {
        $rows = $this->scoreRepo->getScoresByStudent($studentId);

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
            $results[] = [
                'skillId' => (int)$row['id'],
                'skillCode' => $code,
                'skillName' => $competencyNames[$code] ?? $code,
                'category' => $row['skill_category'],
                'score' => (float)$row['score'],
                'maxScore' => 100,
                'level' => $row['level'],
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
                    $scoresMap[$s['skillCode']] = (float)$s['score'];
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
}
