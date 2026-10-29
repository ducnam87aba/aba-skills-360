<?php
/**
 * Credential audit trail (PA49, AGENTS.md §8 + §12).
 *
 * Sensitive account operations — a staff issuing a login, a staff rotating it,
 * a learner swapping the handout password for a private one — are recorded on the
 * append-only wp_aba_events ledger rather than a mutable "last modified" column,
 * so history can never be silently overwritten and the current state is always
 * derived by reading the log back (§12). Each row carries the acting user id, so
 * a viewer sees *who* acted, not just that something happened.
 *
 * Security contract for this data (§8, §1875): the payload NEVER holds a
 * password, hash, or app token — only non-secret context (login name, whether
 * the secret was generated or staff-typed, whether a change was forced). The
 * read path is permission-gated by the controller (requireViewStudent), never
 * here.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Services;

use ABA\Core\Repositories\EventRepository;

if (!defined('ABSPATH')) {
    exit;
}

class CredentialAuditService {
    /**
     * PA61 — the four things a credential row can be about. The screen filters
     * on these, so they are part of the contract: an account action (issued,
     * reset, changed, expired), a device (added, revoked), a sign-in (a session
     * opened, or every session ended), or a lockout after failed guesses.
     */
    public const KIND_ACCOUNT = 'account';
    public const KIND_DEVICE  = 'device';
    public const KIND_SESSION = 'session';
    public const KIND_LOCKOUT = 'lockout';
    public const KINDS = [self::KIND_ACCOUNT, self::KIND_DEVICE, self::KIND_SESSION, self::KIND_LOCKOUT];

    /**
     * PA61 — how many rows the history screen may read. The activity FEED caps
     * each kind at 5/10 because it is a teaser; this is the ledger itself, so
     * the cap is only there to keep one long-lived account from turning the
     * endpoint into a table scan. It is deliberately well above what a family
     * will ever scroll through.
     */
    public const HISTORY_LIMIT = 200;

    private EventRepository $events;

    public function __construct() {
        $this->events = new EventRepository();
    }

    /**
     * Record that a staff member first issued a login for a learner. The
     * password itself is discarded by the caller the instant it is handed over —
     * we store only whether it was generated (=> forced change) or staff-typed.
     *
     * PA51 — `$sessionsRevoked` counts the live logins the rotation ended. It is
     * a non-secret operational fact, and it is the only proof the family gets
     * that a device which held the old handout was actually cut off (§8, §12).
     */
    public function recordIssue(int $actorUserId, int $studentId, string $username, bool $mustChange, int $sessionsRevoked = 0): int {
        return $this->events->record($actorUserId, $studentId, EventRepository::CREDENTIAL_ISSUED, [
            'username'        => $username,
            'method'          => $mustChange ? 'generated' : 'staff',
            'mustChange'      => $mustChange,
            'sessionsRevoked' => $sessionsRevoked,
        ]);
    }

    /** Record that a staff member explicitly rotated (reset) a live login. */
    public function recordReset(int $actorUserId, int $studentId, string $username, bool $mustChange, int $sessionsRevoked = 0): int {
        return $this->events->record($actorUserId, $studentId, EventRepository::CREDENTIAL_RESET, [
            'username'        => $username,
            'method'          => $mustChange ? 'generated' : 'staff',
            'mustChange'      => $mustChange,
            'sessionsRevoked' => $sessionsRevoked,
        ]);
    }

    /**
     * Record that the learner changed their OWN password (the forced first-login
     * swap, or any later self-service change). The actor is the learner, so no
     * username is needed — the row's user_id already identifies them.
     */
    public function recordSelfChange(int $userId, int $studentId): int {
        return $this->events->record($userId, $studentId, EventRepository::CREDENTIAL_CHANGED, []);
    }

    /**
     * PA50 — record that a credential aged past the rotation window and the
     * next sign-in was forced to a change. It is a system policy trigger, but
     * the actor is the signing-in learner (the row's user_id), so a viewer reads
     * it as an event on their own account. No secret is stored, only that a
     * change is now required (§8, §12).
     */
    public function recordExpired(int $userId, int $studentId): int {
        return $this->events->record($userId, $studentId, EventRepository::CREDENTIAL_EXPIRED, [
            'mustChange' => true,
        ]);
    }

    /**
     * PA52 — record that an actor ended every live login of an account (a
     * parent who suspect a shared secret, or staff cleaning up after a reset).
     * The counts only: a session token or uuid is as sensitive as the password
     * it stands in for and must never be written here (§8). The actor is the
     * signed-in user, so the family can see who pulled the plug — including
     * "chính bạn" when the learner did it themselves.
     */
    public function recordSessionsRevoked(int $actorUserId, int $studentId, int $appPasswords, int $sessions): int {
        return $this->events->record($actorUserId, $studentId, EventRepository::CREDENTIAL_SESSIONS_REVOKED, [
            'appPasswords' => $appPasswords,
            'sessions'     => $sessions,
        ]);
    }

    /**
     * PA57 — record that a NEW device signed in and now holds the account. The
     * actor is the learner themselves (they are the one who authenticated), so
     * the family reads it as an event on the child's account rather than as a
     * staff action. Only the label is stored: the token, its uuid and the handle
     * that could end it are all as sensitive as the password (§8).
     */
    public function recordDeviceAdded(int $userId, int $studentId, string $label): int {
        return $this->events->record($userId, $studentId, EventRepository::CREDENTIAL_DEVICE_ADDED, [
            'label' => $label,
        ]);
    }

    /**
     * PA59 — record that a session was opened with the ACCOUNT password.
     *
     * PA57 announces a new app token, which is what the headless client mints;
     * a cookie sign-in (wp-login.php, or the non-headless fallback) mints no
     * token and therefore used to leave no trace a family could see — the very
     * place a leaked hand-written password would be used. PA60 adds the XML-RPC
     * door, where the same secret is replayed by a script; `door` is what keeps
     * the two distinguishable without inventing a second event. The label is the
     * same friendly word the device list shows, never the agent string or the
     * address.
     */
    public function recordSessionOpened(int $userId, int $studentId, string $label, string $door = 'cookie'): int {
        return $this->events->record($userId, $studentId, EventRepository::CREDENTIAL_SESSION_OPENED, [
            'door'  => $door,
            'label' => $label,
        ]);
    }

    /**
     * PA56 — record that ONE device was signed out while the others stayed
     * signed in. The label is the same friendly word the family saw on screen
     * ("Điện thoại"), never the raw agent, the token or the handle: the handle
     * is what would let someone end that device (§8).
     */
    public function recordDeviceRevoked(int $actorUserId, int $studentId, string $label): int {
        return $this->events->record($actorUserId, $studentId, EventRepository::CREDENTIAL_DEVICE_REVOKED, [
            'label' => $label,
        ]);
    }

    /**
     * PA53 — record that a credential (or a whole source address) was locked out
     * after repeated failures. Someone guessing a learner's password is exactly
     * the kind of event the family and the school must be able to see, so it
     * lands on the same append-only trail (§12). Only non-secret facts: which
     * bucket tripped, how long the wait is, how many failures it took. The
     * attempted password and the client address are never written (§8).
     *
     * A username that resolves to no account is still recorded, with null ids,
     * so probing for non-existent logins is not invisible to staff — that row
     * simply never shows in a family's history.
     */
    public function recordThrottled(?int $userId, ?int $studentId, string $scope, int $retryAfter, int $failures): int {
        return $this->events->record($userId, $studentId, EventRepository::LOGIN_THROTTLED, [
            'scope'      => $scope,
            'retryAfter' => $retryAfter,
            'failures'   => $failures,
        ]);
    }

    /**
     * The learner's credential history, newest first, projected for a viewer.
     * Resolves each acting user id to a display name (or a stable self marker)
     * so the UI never shows a raw id; strips anything that is not safe to echo.
     *
     * PA61 — every row now says which KIND of thing it is (an account action, a
     * device, a sign-in, a lockout) and carries the non-secret detail the newer
     * ledgers already hold: the device label, which door the password came
     * through, and how many failures an hour of lock cost. Without it a parent
     * saw "Thiết bị mới đăng nhập" with no word about WHICH device — the data was
     * in the ledger, just never projected.
     *
     * @param string $kind One of KINDS, or '' for everything. An unknown value is
     *                     ignored here; the route validates it before this point.
     * @return list<array{id:int,action:string,kind:string,actionLabel:string,actorLabel:string,createdAt:string,method:string,mustChange:bool,label:?string,door:?string,failures:?int,retryAfter:?int}>
     */
    public function listFor(int $studentId, ?int $viewerUserId = null, string $kind = ''): array {
        // An unknown filter means "no filter", never "no rows": an empty list
        // reads as "nothing ever happened to this account", which is the one
        // thing this screen must not claim by accident. The route rejects the
        // value first; this is the belt.
        $kind = in_array($kind, self::KINDS, true) ? $kind : '';
        $rows = $this->events->listForStudent($studentId, [
            EventRepository::CREDENTIAL_ISSUED,
            EventRepository::CREDENTIAL_RESET,
            EventRepository::CREDENTIAL_CHANGED,
            EventRepository::CREDENTIAL_EXPIRED,
            EventRepository::CREDENTIAL_SESSIONS_REVOKED,
            EventRepository::CREDENTIAL_DEVICE_REVOKED,
            EventRepository::CREDENTIAL_DEVICE_ADDED,
            EventRepository::CREDENTIAL_SESSION_OPENED,
            EventRepository::LOGIN_THROTTLED,
        ], self::HISTORY_LIMIT);
        $out = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->payload_json ?? ''), true) ?: [];
            $actorId = (int) ($row->user_id ?? 0);
            $action  = (string) ($row->event_name ?? '');
            $rowKind = self::kindFor($action);
            if ($kind !== '' && $kind !== $rowKind) {
                continue;
            }
            $label = trim((string) ($payload['label'] ?? ''));
            $door  = (string) ($payload['door'] ?? '');
            $out[] = [
                'id'           => (int) $row->id,
                'action'       => $action,
                'kind'         => $rowKind,
                'actionLabel'  => $this->actionLabel($action, $payload),
                'actorLabel'   => $this->actorLabel($actorId, $viewerUserId),
                'method'       => (string) ($payload['method'] ?? ''),
                'mustChange'   => !empty($payload['mustChange']),
                'createdAt'    => $this->toIso((string) $row->created_at),
                // Absent detail is null, never '' or 0: a screen can tell "no
                // device was named" from "a device named nothing".
                'label'        => $label !== '' ? $label : null,
                'door'         => in_array($door, ['cookie', 'xmlrpc'], true) ? $door : null,
                'failures'     => (int) ($payload['failures'] ?? 0) > 0 ? (int) $payload['failures'] : null,
                'retryAfter'   => (int) ($payload['retryAfter'] ?? 0) > 0 ? (int) $payload['retryAfter'] : null,
            ];
        }
        return $out;
    }

    /**
     * PA61 — the filter dimension a row belongs to. Grouping, not permission:
     * every kind still goes through the same §8 gate on the way out.
     */
    public static function kindFor(string $action): string {
        switch ($action) {
            case EventRepository::CREDENTIAL_DEVICE_ADDED:
            case EventRepository::CREDENTIAL_DEVICE_REVOKED:
                return self::KIND_DEVICE;
            case EventRepository::CREDENTIAL_SESSION_OPENED:
            case EventRepository::CREDENTIAL_SESSIONS_REVOKED:
                return self::KIND_SESSION;
            case EventRepository::LOGIN_THROTTLED:
                return self::KIND_LOCKOUT;
            default:
                return self::KIND_ACCOUNT;
        }
    }

    /**
     * Stable, viewer-facing verb for each event kind. `$payload` is only read
     * where the same event covers two situations that must not read alike —
     * a password typed into a page vs replayed by a script (PA60). Rows written
     * before the `door` field existed read as the browser case, which is what
     * they were.
     */
    private function actionLabel(string $action, array $payload = []): string {
        switch ($action) {
            case EventRepository::CREDENTIAL_ISSUED:
                return 'Cấp tài khoản đăng nhập';
            case EventRepository::CREDENTIAL_RESET:
                return 'Đặt lại mật khẩu';
            case EventRepository::CREDENTIAL_CHANGED:
                return 'Đổi mật khẩu';
            case EventRepository::CREDENTIAL_EXPIRED:
                return 'Mật khẩu hết hạn — buộc đổi';
            case EventRepository::CREDENTIAL_SESSIONS_REVOKED:
                return 'Đăng xuất mọi thiết bị';
            case EventRepository::CREDENTIAL_DEVICE_REVOKED:
                return 'Đăng xuất một thiết bị';
            case EventRepository::CREDENTIAL_DEVICE_ADDED:
                return 'Thiết bị mới đăng nhập';
            case EventRepository::CREDENTIAL_SESSION_OPENED:
                return (string) ($payload['door'] ?? '') === CredentialSessionService::DOOR_XMLRPC
                    ? 'Đăng nhập bằng mật khẩu (ứng dụng khác)'
                    : 'Đăng nhập bằng trình duyệt';
            case EventRepository::LOGIN_THROTTLED:
                return 'Tạm khoá do thử sai nhiều lần';
            default:
                return $action;
        }
    }

    /**
     * A non-sensitive actor name. A self-change is labelled "chính học sinh"
     * rather than echoing the (possibly staff) id; a staff action shows their
     * display name so the family can see which adult managed the account.
     */
    private function actorLabel(int $actorId, ?int $viewerUserId): string {
        if ($actorId <= 0) {
            return 'Hệ thống';
        }
        if ($viewerUserId !== null && $actorId === $viewerUserId) {
            return 'Chính bạn';
        }
        $user = get_userdata($actorId);
        $name = $user ? trim((string) $user->display_name) : '';
        return $name !== '' ? $name : 'Nhân viên nhà trường';
    }

    /** GMT datetime from the DB => the contract's ISO-8601 (Z) instant. */
    private function toIso(string $mysqlDatetime): string {
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : $mysqlDatetime;
    }
}
