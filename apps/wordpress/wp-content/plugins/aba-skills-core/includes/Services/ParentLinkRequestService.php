<?php
/**
 * Parent link-request flow (PA42).
 *
 * Business logic per AGENTS.md §4: the controller stays a thin transport layer,
 * the repository owns persistence, and this service decides the outcome of a
 * submission: unknown code, an already-linked learner, a live pending request,
 * or a fresh/re-opened request. Approval is a separate, administrator-only
 * operation (§8) — submitting never grants access by itself; only approve()
 * writes the wp_aba_parent_student row that Authorization::isParentOf reads.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Services;

use ABA\Core\Repositories\ParentLinkRequestRepository;
use ABA\Core\Repositories\StudentRepository;

if (!defined('ABSPATH')) {
    exit;
}

class ParentLinkRequestService {
    /** Relationship whitelist; anything else falls back to the neutral term. */
    private const RELATIONSHIPS = ['father', 'mother', 'guardian', 'parent'];

    private ParentLinkRequestRepository $repo;
    private StudentRepository $students;

    public function __construct() {
        $this->repo = new ParentLinkRequestRepository();
        $this->students = new StudentRepository();
    }

    /**
     * Outcomes:
     *   not_found      — code matches no active learner (controller => 404;
     *                    the message never reveals anything about the account).
     *   already_linked — an active parent_student row already exists.
     *   exists         — a pending request for the same pair is reused.
     *   created        — new request, or a rejected one re-opened.
     */
    public function submit(int $parentUserId, string $studentCode, string $relationship): array {
        $code = strtoupper(trim($studentCode));
        if ($code === '' || $parentUserId <= 0) {
            return ['status' => 'not_found'];
        }
        $relationship = in_array($relationship, self::RELATIONSHIPS, true) ? $relationship : 'guardian';

        $student = $this->students->findByCode($code);
        if ($student === null) {
            return ['status' => 'not_found'];
        }
        $studentId = (int) $student['id'];

        if ($this->repo->hasActiveLink($parentUserId, $studentId)) {
            return ['status' => 'already_linked'];
        }

        $existing = $this->repo->findByParentAndStudent($parentUserId, $studentId);
        if ($existing !== null) {
            if ($existing['status'] === 'pending') {
                return ['status' => 'exists', 'request' => $this->repo->toApiShape($existing)];
            }
            if ($existing['status'] === 'approved') {
                // Approved but the link itself went inactive (e.g. unlinked
                // again): approve() re-grants idempotently.
                $this->repo->ensureActiveLink($parentUserId, $studentId, $relationship);
                return ['status' => 'already_linked'];
            }
            $this->repo->reopen((int) $existing['id'], $relationship);
            return ['status' => 'created', 'request' => $this->repo->toApiShape($this->repo->find((int) $existing['id']))];
        }

        $id = $this->repo->create($parentUserId, $studentId, $code, $relationship);
        if ($id === null) {
            return ['status' => 'not_found'];
        }
        return ['status' => 'created', 'request' => $this->repo->toApiShape($this->repo->find($id))];
    }

    public function listForParent(int $parentUserId): array {
        return $this->repo->listForParent($parentUserId);
    }

    /**
     * PA43 — the administrator's review queue (pending by default). The service
     * stays the single entry point so the controller never talks to the
     * repository directly (§4); the caller's platform-user right is enforced at
     * the REST permission callback before this is ever reached (§8).
     */
    public function listForAdmin(string $status = 'pending'): array {
        return $this->repo->listForAdmin($status);
    }

    /** Administrator approved: record the review, THEN write the access grant. */
    public function approve(int $requestId): ?array {
        return $this->review($requestId, 'approved');
    }

    public function reject(int $requestId): ?array {
        return $this->review($requestId, 'rejected');
    }

    private function review(int $requestId, string $decision): ?array {
        $request = $this->repo->find($requestId);
        // Only a pending request can move; a reviewed one is immutable history.
        if ($request === null || $request['status'] !== 'pending') {
            return null;
        }
        $this->repo->markReviewed($requestId, $decision, \get_current_user_id());
        if ($decision === 'approved') {
            $this->repo->ensureActiveLink(
                (int) $request['parent_user_id'],
                (int) $request['student_id'],
                $request['relationship']
            );
        }
        $fresh = $this->repo->find($requestId);
        return $fresh === null ? null : $this->repo->toApiShape($fresh);
    }
}
