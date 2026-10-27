<?php
/**
 * Parent Report generation (Report domain, AGENTS.md §21 priority 3).
 *
 * Composes a skills-summary report from the Skills Profile + Recommendation
 * Engine (deterministic, no LLM), and — for PA20 — persists an immutable
 * snapshot per generation so parents gain a longitudinal history instead of a
 * single value recomputed on every request. Report delivery is presentation
 * agnostic: the Next.js client owns CSV/print export (see lib/reportExport).
 *
 * Boundaries (§5): consumes SkillsProfileService + RecommendationService rather
 * than reaching into those domains' storage; owns only report persistence via
 * ReportRepository.
 */

namespace ABA\Core\Services;

use ABA\Core\Repositories\ReportRepository;
use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Repositories\EventRepository;

class ReportService {
    private StudentRepository $students;
    private SkillsProfileService $skills;
    private RecommendationService $recs;
    private ReportRepository $reports;
    private EventRepository $events;

    public function __construct() {
        $this->students = new StudentRepository();
        $this->skills = new SkillsProfileService();
        $this->recs = new RecommendationService();
        $this->reports = new ReportRepository();
        $this->events = new EventRepository();
    }

    /**
     * Compose the current report from measured skills + recommendations.
     * Returns null when the student does not exist. No id / generatedAt here:
     * those are assigned by the storage layer (or the transient fallback).
     *
     * @return ?array<string,mixed>
     */
    public function compose(int $studentId): ?array {
        $child = $this->students->findById($studentId);
        if (!$child) {
            return null;
        }

        $scores = $this->skills->getCurrentScores($studentId);
        $gradeGroup = $child['grade_group'] ?? '4_6';
        $recs = $this->recs->getRecommendations($scores, $gradeGroup);

        $overallSum = 0.0;
        $measured = 0;
        $strengths = [];
        $growth = [];
        $insufficient = [];
        foreach ($scores as $s) {
            // Insufficient evidence is unmeasured, NOT zero (spec §45, invariant #1).
            if (($s['status'] ?? 'valid') === 'insufficient_evidence' || $s['score'] === null) {
                $insufficient[] = $s['skillName'];
                continue;
            }
            $score = (float)$s['score'];
            $overallSum += $score;
            $measured++;
            if ($score >= 75) {
                $strengths[] = 'Năng lực ' . $s['skillName'] . ' đạt mức ' . $s['level'];
            } elseif ($score < 60) {
                $growth[] = 'Cần tăng cường ' . $s['skillName'] . ' qua tình huống thực hành';
            }
        }
        // Average over measured competencies only; null when nothing is measurable.
        $overall = $measured > 0 ? (int)round($overallSum / $measured) : null;

        $headline = $overall === null
            ? 'Chưa đủ bằng chứng để tính điểm tổng quan năng lực.'
            : 'Học sinh ' . ($child['full_name'] ?? trim(($child['last_name'] ?? '') . ' ' . ($child['first_name'] ?? ''))) . ' có điểm tổng quan ' . $overall . '/100 across ' . $measured . ' năng lực cốt lõi đã đo được.';
        if ($insufficient) {
            $headline .= ' Chưa đo được: ' . implode(', ', $insufficient) . '.';
        }

        return [
            'studentId' => $studentId,
            'title' => 'Báo cáo Định kỳ Năng lực Kỹ năng Tháng ' . gmdate('m/Y'),
            'summary' => $headline,
            'overallScore' => $overall,
            'measuredCompetencies' => $measured,
            'insufficientEvidence' => $insufficient,
            'strengths' => $strengths ?: ['Đang thu thập dữ liệu đánh giá'],
            'areasForGrowth' => $growth ?: ['Tiếp tục duy trì luyện tập đều đặn'],
            'recommendations' => $recs,
        ];
    }

    /**
     * Compose and persist an immutable snapshot. Each call appends a new row
     * (longitudinal history, §11) and returns the report stamped with its
     * durable id + generation instant.
     *
     * @return ?array<string,mixed> null when the student does not exist.
     */
    public function generate(int $studentId): ?array {
        $payload = $this->compose($studentId);
        if ($payload === null) {
            return null;
        }
        $id = $this->reports->create($studentId, 'skills_summary', $payload['title'], $payload);
        // PA21 — delivery starts the moment the snapshot exists: log it on the
        // append-only event ledger (read status is derived from this log later).
        $this->events->record(\get_current_user_id() ?: null, $studentId, EventRepository::REPORT_GENERATED, [
            'reportId'   => $id,
            'reportType' => 'skills_summary',
        ]);
        $payload['id'] = $id;
        $payload['reportType'] = 'skills_summary';
        $payload['generatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        return $payload;
    }

    /**
     * Stored report history (newest first). When nothing has been persisted yet,
     * fall back to a single transient composition so the parent endpoint keeps
     * returning a report on a learner's first visit — backward compatible with
     * the pre-PA20 on-the-fly behaviour.
     *
     * @return list<array<string,mixed>>
     */
    public function listOrCompose(int $studentId): array {
        $stored = $this->reports->listForStudent($studentId);
        if ($stored !== []) {
            return $stored;
        }
        $fresh = $this->compose($studentId);
        if ($fresh === null) {
            return [];
        }
        // Transient report: id mirrors the student (no durable row yet).
        $fresh['id'] = $studentId;
        $fresh['reportType'] = 'skills_summary';
        $fresh['generatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        return [$fresh];
    }

    /** A single stored snapshot by id (scoped to the student), or null. */
    public function find(int $reportId, int $studentId): ?array {
        foreach ($this->reports->listForStudent($studentId) as $r) {
            if ((int)$r['id'] === $reportId) {
                // PA21: stamp the derived delivery/read status onto the detail view.
                $r['delivery'] = $this->deliveryStatus($studentId, $reportId);
                return $r;
            }
        }
        return null;
    }

    /**
     * PA21 — delivery/read state, always *derived* from the event ledger (never a
     * stored counter): delivered once the snapshot exists, read when at least one
     * report_read event is logged for it. firstReadAt is the earliest receipt and
     * never moves; views counts every open.
     *
     * @return array{delivered:bool,read:bool,views:int,firstReadAt:?string,lastReadAt:?string}
     */
    public function deliveryStatus(int $studentId, int $reportId): array {
        $reads = $this->events->readsFor($studentId, $reportId);
        return [
            'delivered'   => true,
            'read'        => $reads !== [],
            'views'       => count($reads),
            'firstReadAt' => $reads !== [] ? $this->toIso((string)$reads[0]) : null,
            'lastReadAt'  => $reads !== [] ? $this->toIso((string)end($reads)) : null,
        ];
    }

    /**
     * PA21 — record that the acting parent opened (acknowledged) a report. Every
     * call appends one report_read receipt, so re-opening a report is counted as
     * engagement while the first-read moment stays fixed (§11/§12).
     *
     * @return array the freshly derived delivery status.
     */
    public function markRead(int $studentId, int $reportId, ?int $userId): array {
        $this->events->record($userId, $studentId, EventRepository::REPORT_READ, [
            'reportId' => $reportId,
        ]);
        return $this->deliveryStatus($studentId, $reportId);
    }

    /** GMT datetime from the DB => the contract's ISO-8601 (Z) instant. */
    private function toIso(string $mysqlDatetime): string {
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : $mysqlDatetime;
    }
}
