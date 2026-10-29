<?php
/**
 * Assembles the parent child overview (api-contract.md §37). Cross-domain reads
 * are composed here rather than in the REST controller, and every skill figure
 * respects insufficient-evidence nulls (never rendered as 0, assessment-spec §45).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Services;

use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Repositories\SkillSnapshotRepository;
use ABA\Core\Repositories\LessonProgressRepository;
use ABA\Core\Repositories\MissionProgressRepository;
use ABA\Core\Repositories\XpTransactionRepository;
use ABA\Core\Repositories\ClassRepository;
use ABA\Core\Repositories\EventRepository;

if (!defined('ABSPATH')) {
    exit;
}

class ParentOverviewService {
    private StudentRepository $studentRepo;
    private SkillsProfileService $skillsService;
    private SkillSnapshotRepository $snapshotRepo;
    private LessonProgressRepository $lessonRepo;
    private MissionProgressRepository $missionRepo;
    private XpTransactionRepository $xpRepo;
    private GamificationService $gamification;
    private ClassRepository $classRepo;
    private EventRepository $events;

    public function __construct() {
        $this->studentRepo = new StudentRepository();
        $this->skillsService = new SkillsProfileService();
        $this->snapshotRepo = new SkillSnapshotRepository();
        $this->lessonRepo = new LessonProgressRepository();
        $this->missionRepo = new MissionProgressRepository();
        $this->xpRepo = new XpTransactionRepository();
        $this->gamification = new GamificationService();
        $this->classRepo = new ClassRepository();
        $this->events = new EventRepository();
    }

    /**
     * Enrich a parent's children list with the class context each learner sits in
     * (PA26). This is cross-domain composition — the Parent view consuming School
     * reads — kept in the service layer so the controller stays thin (§4, §5).
     * Additive and isolated: only this parent-facing projection gains the `class`
     * key; the roster endpoints that share toApiShape are untouched. A child with
     * no valid class gets `class => null` rather than a fabricated row (§8).
     *
     * @param list<array> $profiles StudentProfile-shaped rows.
     * @return list<array>
     */
    public function decorateChildren(array $profiles): array {
        foreach ($profiles as &$profile) {
            $classId = (int) ($profile['classId'] ?? 0);
            $profile['class'] = $this->classRepo->contextFor($classId);
        }
        unset($profile);
        return $profiles;
    }

    /**
     * @return array|null null when the student does not exist.
     */
    public function build(int $studentId): ?array {
        $student = $this->studentRepo->findById($studentId);
        if (!$student) {
            return null;
        }
        $profile = $this->studentRepo->toApiShape($student);

        $since = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
        $scores = $this->skillsService->getCurrentScores($studentId);

        // Only measured skills feed strengths/growth; null = no evidence (§45).
        $measured = array_values(array_filter($scores, static function ($s) {
            return ($s['status'] ?? 'valid') !== 'insufficient_evidence' && $s['score'] !== null;
        }));

        $sorted = $measured;
        usort($sorted, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $strengths = array_slice(array_map([$this, 'toSkillRef'], array_filter($sorted, static fn($s) => $s['score'] >= 75)), 0, 3);
        $growth = array_slice(array_map([$this, 'toSkillRef'], array_filter($sorted, static fn($s) => $s['score'] < 60)), 0, 3);

        $latest = $this->snapshotRepo->findLatest($studentId);

        return [
            'student' => [
                'id' => $profile['id'],
                'displayName' => $profile['fullName'],
                'grade' => $profile['gradeLevel'],
                // PA26: the class + homeroom teacher this child sits in.
                'class' => $this->classRepo->contextFor((int) ($profile['classId'] ?? 0)),
            ],
            'summary' => [
                'currentStreak' => $profile['currentStreak'],
                'totalXp' => $profile['totalXp'],
                'weekly' => [
                    'lessonsCompleted' => $this->lessonRepo->countCompletedSince($studentId, $since),
                    'missionsCompleted' => $this->missionRepo->countCompletedSince($studentId, $since),
                ],
            ],
            'skills' => [
                'topStrengths' => array_values($strengths),
                'priorityGrowthAreas' => array_values($growth),
                'insufficientEvidence' => array_values(array_map(
                    static fn($s) => $s['skillCode'],
                    array_filter($scores, static fn($s) => ($s['status'] ?? 'valid') === 'insufficient_evidence')
                )),
            ],
            'latestReport' => $latest ? [
                'id' => (int)$latest['id'],
                'type' => 'assessment',
            ] : null,
        ];
    }

    private function toSkillRef(array $s): array {
        return [
            'key' => $s['skillCode'],
            'name' => $s['skillName'],
            'score' => (float)$s['score'],
            'level' => $s['level'],
        ];
    }

    /**
     * PA40 — the child's recent activity feed for the parent "Hoạt động" screen.
     * Composed entirely from ground-truth state, never a fabricated counter
     * (AGENTS.md §12): lessons from the progress table (real dates + XP), and
     * badges + missions from the derived achievement timeline. Weekly XP is
     * SUM'd from the XP ledger over the same 7-day window the weekly missions use.
     * @return array{studentId:int,weeklyXp:int,items:list<array>}|null null when
     *         the student does not exist (controller turns this into a 404).
     */
    public function buildActivityFeed(int $studentId): ?array {
        if (!$this->studentRepo->findById($studentId)) {
            return null;
        }

        $items = [];

        // 1) Recent completed lessons — title resolved through the single
        //    learning-path label so the parent sees the real unit name.
        foreach ($this->lessonRepo->getRecentCompleted($studentId, 12) as $row) {
            $nodeId = (string) ($row['node_id'] ?? '');
            $items[] = [
                'id' => 'lesson:' . $nodeId,
                'type' => 'lesson',
                'title' => (LearningPathService::titleForNode($nodeId) ?? 'Hoàn thành bài học'),
                'occurredAt' => $this->toIso((string) ($row['completed_at'] ?? '')),
                'xpEarned' => (int) ($row['xp_awarded'] ?? 0),
                'score' => isset($row['score']) ? (int) round((float) $row['score']) : null,
            ];
        }

        // 2) Badges + completed missions — reuse the derived achievement timeline
        //    (§11 durable first-earned dates). Mission titles come from the live
        //    mission catalogue so nothing is hard-coded here.
        $achievements = $this->gamification->buildAchievements($studentId);
        $missionTitles = [];
        foreach ($this->gamification->buildMissions($studentId) as $m) {
            $missionTitles[(int) $m['id']] = (string) $m['title'];
        }
        foreach ($achievements['timeline'] as $entry) {
            if (($entry['kind'] ?? '') === 'badge') {
                $items[] = [
                    'id' => 'badge:' . ($entry['code'] ?? ''),
                    'type' => 'badge',
                    'title' => 'Đạt danh hiệu: ' . ($entry['title'] ?? ''),
                    'occurredAt' => $entry['earnedAt'] ?? null,
                    'xpEarned' => null,
                    'score' => null,
                ];
            } elseif (($entry['kind'] ?? '') === 'mission') {
                $mid = (int) ($entry['missionId'] ?? 0);
                $items[] = [
                    'id' => 'mission:' . $mid . ':' . ($entry['periodDate'] ?? ''),
                    'type' => 'mission',
                    'title' => 'Hoàn thành nhiệm vụ: ' . ($missionTitles[$mid] ?? ('#' . $mid)),
                    'occurredAt' => $entry['earnedAt'] ?? null,
                    'xpEarned' => null,
                    'score' => null,
                ];
            }
        }

        // 3) PA57 — a new device signed in. The one item in this feed that is
        //    about the ACCOUNT rather than about learning: it is what turns
        //    "which devices hold my child" (PA56) from a screen you must
        //    remember to open into an alert the family actually receives. The
        //    label is all the server ever stored, so nothing replayable is here.
        foreach ($this->events->listForStudent($studentId, [EventRepository::CREDENTIAL_DEVICE_ADDED], 10) as $row) {
            $payload = json_decode((string) ($row->payload_json ?? ''), true) ?: [];
            $label = trim((string) ($payload['label'] ?? ''));
            $items[] = [
                'id' => 'device:' . (int) $row->id,
                'type' => 'device',
                'title' => $label !== '' ? 'Thiết bị mới đăng nhập: ' . $label : 'Thiết bị mới đăng nhập',
                'occurredAt' => $this->toIso((string) ($row->created_at ?? '')),
                'xpEarned' => null,
                'score' => null,
            ];
        }

        // 4) PA58 — somebody was guessing the child's password. PA53 already
        //    records ONE row per lock engagement (a burst of five is one event,
        //    not five), and never the offending address: the family needs the
        //    warning, not the IP (§8). Without this the loudest security signal
        //    the platform produces was visible only to staff.
        foreach ($this->events->listForStudent($studentId, [EventRepository::LOGIN_THROTTLED], 5) as $row) {
            $payload = json_decode((string) ($row->payload_json ?? ''), true) ?: [];
            $failures = (int) ($payload['failures'] ?? 0);
            $minutes = (int) round(((int) ($payload['retryAfter'] ?? 0)) / 60);
            // The source bucket watches one address spraying MANY accounts, so it
            // must not claim this child was the target — only that it happened.
            $title = (string) ($payload['scope'] ?? '') === 'source'
                ? 'Phát hiện dò mật khẩu từ cùng một nơi'
                : 'Nhiều lần đăng nhập sai vào tài khoản của con';
            $facts = [];
            if ($failures > 0) {
                $facts[] = $failures . ' lần sai';
            }
            if ($minutes > 0) {
                $facts[] = 'tạm khoá ' . $minutes . ' phút';
            }
            if ($facts !== []) {
                $title .= ' (' . implode(', ', $facts) . ')';
            }
            $items[] = [
                'id' => 'security:' . (int) $row->id,
                'type' => 'security',
                'title' => $title,
                'occurredAt' => $this->toIso((string) ($row->created_at ?? '')),
                'xpEarned' => null,
                'score' => null,
            ];
        }

        // 5) PA59 — the same question for a sign-in that mints no app token and
        //    so is invisible to the PA57 alert. Its own id prefix keeps the two
        //    apart while both read as a sign-in on screen; the payload only ever
        //    held the door and a device label (§8). PA60 splits the wording: a
        //    script replaying the password is not "a browser", and a parent who
        //    reads it as one would look for a device that was never involved.
        foreach ($this->events->listForStudent($studentId, [EventRepository::CREDENTIAL_SESSION_OPENED], 10) as $row) {
            $payload = json_decode((string) ($row->payload_json ?? ''), true) ?: [];
            $label = trim((string) ($payload['label'] ?? ''));
            // Rows written before PA60 carry no door; they were all cookie.
            $xmlrpc = (string) ($payload['door'] ?? '') === CredentialSessionService::DOOR_XMLRPC;
            $title = $xmlrpc ? 'Đăng nhập qua ứng dụng khác' : 'Đăng nhập bằng trình duyệt';
            if ($label !== '') {
                $title .= ': ' . $label;
            }
            $items[] = [
                'id' => 'session:' . (int) $row->id,
                'type' => 'device',
                'title' => $title,
                'occurredAt' => $this->toIso((string) ($row->created_at ?? '')),
                'xpEarned' => null,
                'score' => null,
            ];
        }

        // Newest first; ISO-8601 instants sort lexicographically, nulls sink.
        usort($items, static function ($a, $b) {
            return strcmp((string) ($b['occurredAt'] ?? ''), (string) ($a['occurredAt'] ?? ''));
        });
        $items = array_slice($items, 0, 15);

        $sinceWeek = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
        return [
            'studentId' => $studentId,
            'weeklyXp' => (int) $this->xpRepo->getSumSince($studentId, $sinceWeek),
            'items' => $items,
        ];
    }

    /** GMT datetime from the DB => the contract's ISO-8601 (Z) instant. */
    private function toIso(string $mysqlDatetime): ?string {
        if ($mysqlDatetime === '') {
            return null;
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : $mysqlDatetime;
    }
}
