<?php
/**
 * Credential session revocation (PA51, AGENTS.md §8).
 *
 * Changing a password and ending a login are two different things in WordPress.
 * `wp_authenticate_application_password()` validates an app password against
 * that app password's OWN hash, never against `user_pass`, and neither
 * `wp_set_password()` nor `wp_update_user()` deletes app passwords or session
 * tokens. So without an explicit revocation, a rotated credential leaves every
 * existing client working: the staff "reset" is cosmetic while the person
 * holding the old handout keeps full API access.
 *
 * This service is the single place that ends a credential's live sessions, so
 * the login flow, the self-service change and the staff issue/reset path all
 * behave identically. It deliberately filters revocation by OUR app name: an
 * administrator's manually issued application password is not ours to destroy.
 *
 * PA54 — a session now belongs to a DEVICE, not to the account. Signing in on a
 * second phone used to recycle the one token and silently sign the first device
 * out, which also made "sign out all devices" the only sign-out that existed.
 * Each sign-in mints its own labelled token, sign-out ends exactly the caller's,
 * and the account keeps at most MAX_DEVICES (least recently used goes first).
 * A password change or a staff reset still ends every device on purpose.
 *
 * PA55 — per-device tokens are only half a story if nobody can see them. This
 * service now also projects the account's own devices as rows a family screen
 * can read (label, when it signed in, when it was last used) and ends one of
 * them by a derived handle. The handle is an HMAC of the token's uuid, so the
 * uuid and the token never leave the server (§8) while the client still gets a
 * stable, non-guessable id to act on.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Services;

use ABA\Core\Auth\Authorization;

if (!defined('ABSPATH')) {
    exit;
}

class CredentialSessionService {
    /**
     * Prefix of every app password this plugin mints.
     *
     * PA54 — one token per DEVICE, so the stored name is the prefix plus the
     * device label (`ABA headless session · Điện thoại`). Matching is by prefix
     * so tokens minted before PA54 (bare name) stay ours, and an administrator's
     * hand-issued app can never be mistaken for one of them.
     */
    public const APP_NAME = 'ABA headless session';

    /** How many devices one account may keep signed in at once (PA54). */
    public const MAX_DEVICES = 6;

    /** Between the plugin prefix and the device label. */
    private const LABEL_SEPARATOR = ' · ';

    /** PA60 — the two doors that accept the account password and mint no token. */
    public const DOOR_COOKIE = 'cookie';
    public const DOOR_XMLRPC = 'xmlrpc';

    /**
     * PA60 — how long an XML-RPC sign-in stays quiet after being announced.
     *
     * An XML-RPC client re-authenticates on EVERY call rather than holding a
     * session, so one row per call would push a week of learning out of a
     * fifteen-row feed in an hour. One announcement per window still answers the
     * question the family asks ("somebody had the password"); a second sign-in
     * inside the window is the same client doing the same thing.
     */
    private const XMLRPC_ANNOUNCE_SECONDS = 3600;

    /** Transient key prefix for that window; public so it can be cleared. */
    public const XMLRPC_KEY_PREFIX = 'aba_xmlrpc_seen_';

    /**
     * PA59 — users already announced in THIS request. wp_login and the REST
     * cookie branch would otherwise both write for the same sign-in, turning one
     * login into two alerts in a family's feed.
     *
     * @var array<int,bool>
     */
    private static array $browserAnnounced = [];

    /** The name stored on a device token; no label means the legacy bare name. */
    public static function appNameFor(string $device = ''): string {
        $device = trim($device);
        return $device === '' ? self::APP_NAME : self::APP_NAME . self::LABEL_SEPARATOR . $device;
    }

    /** Is this app password one of ours (rather than an admin's by hand)? */
    public static function isOurApp(string $name): bool {
        return strpos($name, self::APP_NAME) === 0;
    }

    /**
     * PA55 — the opaque id a client uses to name one device. Derived, never
     * stored: the same uuid always yields the same handle on this site, and the
     * uuid itself (let alone the token) never leaves the server (§8).
     */
    public static function deviceHandle(string $uuid): string {
        return substr(hash_hmac('sha256', $uuid, wp_salt('auth')), 0, 24);
    }

    /**
     * PA55 — the human part of a token name, for the "your devices" list. A
     * token minted before PA54 carries only the bare prefix, so it reads as an
     * unnamed device rather than an empty cell.
     */
    public static function labelOf(string $name): string {
        if (!self::isOurApp($name)) {
            return '';
        }
        // Compare the separator BEFORE trimming: trim() eats the leading space,
        // and ' · ' then no longer matches, leaving a stray dot on the label.
        $label = (string) substr($name, strlen(self::APP_NAME));
        if (strpos($label, self::LABEL_SEPARATOR) === 0) {
            $label = (string) substr($label, strlen(self::LABEL_SEPARATOR));
        }
        $label = trim($label);
        return $label === '' ? 'Thiết bị' : $label;
    }

    /** This plugin's own app passwords for a user, in core's order. */
    private function ours(int $userId): array {
        if ($userId <= 0 || !class_exists('WP_Application_Passwords')) {
            return [];
        }
        require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
        $apps = [];
        foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $app) {
            if (self::isOurApp((string) ($app['name'] ?? ''))) {
                $apps[] = $app;
            }
        }
        return $apps;
    }

    /**
     * PA55 — the signed-in account's own live devices, the one asking first and
     * the rest by recency. Each row carries only what a family screen needs: a
     * label, when it signed in, when it was last used, and whether it is the
     * device making the request.
     *
     * @return array<int, array{handle:string,label:string,createdAt:string,lastUsedAt:string|null,current:bool}>
     */
    public function devices(int $userId, string $currentUuid = ''): array {
        $rows = [];
        foreach ($this->ours($userId) as $app) {
            $uuid = (string) ($app['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $created = (int) ($app['created'] ?? 0);
            $used = (int) ($app['last_used'] ?? 0);
            $rows[] = [
                'handle'     => self::deviceHandle($uuid),
                'label'      => self::labelOf((string) ($app['name'] ?? '')),
                'createdAt'  => $created > 0 ? gmdate('Y-m-d\TH:i:s\Z', $created) : '',
                'lastUsedAt' => $used > 0 ? gmdate('Y-m-d\TH:i:s\Z', $used) : null,
                'current'    => $currentUuid !== '' && $uuid === $currentUuid,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['current'] !== $b['current']) {
                return $a['current'] ? -1 : 1;
            }
            $left = (string) ($a['lastUsedAt'] ?? $a['createdAt']);
            $right = (string) ($b['lastUsedAt'] ?? $b['createdAt']);
            return strcmp($right, $left);
        });
        return $rows;
    }

    /**
     * PA55 — end ONE device by its handle.
     *
     * Returns 'revoked', or 'notFound' for a handle that is not one of this
     * account's live tokens — including another account's, so a guessed handle
     * can never reach across accounts (§8). A token that vanished between the
     * listing and the call also lands on 'notFound': the truthful answer there
     * is that this device is no longer signed in.
     */
    public function revokeDevice(int $userId, string $handle): string {
        $handle = trim($handle);
        if ($userId <= 0 || $handle === '') {
            return 'notFound';
        }
        foreach ($this->ours($userId) as $app) {
            $uuid = (string) ($app['uuid'] ?? '');
            if ($uuid === '' || !hash_equals(self::deviceHandle($uuid), $handle)) {
                continue;
            }
            return is_wp_error(\WP_Application_Passwords::delete_application_password($userId, $uuid))
                ? 'notFound'
                : 'revoked';
        }
        return 'notFound';
    }

    /**
     * PA55 — "sign out everywhere else": end every device token except the one
     * this request presented. Called from a cookie session, where no token was
     * presented, there is no device to spare and every token ends — the caller
     * asked to cut off other devices and cannot be told which one is "here".
     */
    public function revokeOtherDevices(int $userId, string $currentUuid = ''): int {
        $ended = 0;
        foreach ($this->ours($userId) as $app) {
            $uuid = (string) ($app['uuid'] ?? '');
            if ($uuid === '' || ($currentUuid !== '' && $uuid === $currentUuid)) {
                continue;
            }
            if (!is_wp_error(\WP_Application_Passwords::delete_application_password($userId, $uuid))) {
                $ended++;
            }
        }
        return $ended;
    }

    /**
     * PA54 — a readable label for the device behind a sign-in. A parent cannot
     * act on "Mozilla/5.0 (Linux; Android 13…)", so the raw agent is reduced to
     * the one fact they need; anything unrecognised gets no label at all rather
     * than a guess. Only ever used to name a token, never returned to a client.
     */
    public static function deviceLabel(string $userAgent): string {
        $ua = strtolower($userAgent);
        if ($ua === '') {
            return '';
        }
        if (preg_match('/iphone|ipod|windows phone|android.+mobile/', $ua) === 1) {
            return 'Điện thoại';
        }
        if (preg_match('/ipad|tablet|android/', $ua) === 1) {
            return 'Máy tính bảng';
        }
        if (preg_match('/windows|macintosh|mac os x|linux|x11|cros/', $ua) === 1) {
            return 'Máy tính';
        }
        return '';
    }

    /**
     * Delete this plugin's own app password(s) for a user, keeping any app an
     * administrator issued by hand. Returns how many tokens died, so the caller
     * can report a truthful "sessions ended" count.
     */
    public function revokeAppPasswords(int $userId): int {
        $deleted = 0;
        foreach ($this->ours($userId) as $app) {
            $uuid = (string) ($app['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            if (!is_wp_error(\WP_Application_Passwords::delete_application_password($userId, $uuid))) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * PA54 — end ONE device's login, leaving the family's other devices signed
     * in. Returns false for an unknown uuid or a token that is not ours, so a
     * caller can never be steered into deleting an administrator's app.
     */
    public function revokeAppPassword(int $userId, string $uuid): bool {
        if ($userId <= 0 || $uuid === '' || !class_exists('WP_Application_Passwords')) {
            return false;
        }
        require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
        foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $app) {
            if ((string) ($app['uuid'] ?? '') !== $uuid) {
                continue;
            }
            if (!self::isOurApp((string) ($app['name'] ?? ''))) {
                return false;
            }
            return !is_wp_error(\WP_Application_Passwords::delete_application_password($userId, $uuid));
        }
        return false;
    }

    /**
     * PA54 — keep the account's most recently used devices and drop the rest.
     * Without a cap every sign-in would add a token forever; ordering by
     * `last_used` (core stamps it on each Basic request) means the tablet left
     * in a drawer goes before the phone in daily use.
     */
    public function pruneAppPasswords(int $userId, int $keep = self::MAX_DEVICES): int {
        if ($userId <= 0 || $keep < 1) {
            return 0;
        }
        $apps = $this->ours($userId);
        if (count($apps) <= $keep) {
            return 0;
        }
        $seenAt = static function (array $app): int {
            return (int) ($app['last_used'] ?? 0) ?: (int) ($app['created'] ?? 0);
        };
        // Newest first, then keep the first $keep. A token that has never been
        // used carries no `last_used`, and several sign-ins can share the same
        // `created` second, so the tie is broken by position: core appends new
        // tokens, which makes the later entry the later sign-in.
        $rows = [];
        foreach ($apps as $index => $app) {
            $rows[] = ['index' => $index, 'seen' => $seenAt($app), 'app' => $app];
        }
        usort($rows, static function (array $a, array $b): int {
            return [$b['seen'], $b['index']] <=> [$a['seen'], $a['index']];
        });
        $deleted = 0;
        foreach (array_slice($rows, $keep) as $row) {
            $uuid = (string) ($row['app']['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            if (!is_wp_error(\WP_Application_Passwords::delete_application_password($userId, $uuid))) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * PA57 — announce that a device just gained access to a learner's account.
     *
     * PA55/PA56 can tell a family which devices are signed in, but only if they
     * go and look; a device that appeared overnight would stay invisible until
     * somebody opened the screen. This writes the event at the moment the token
     * is minted, so the parent's activity feed and the learner's own login
     * history both carry it (§8, §12).
     *
     * The payload is the label the device stated and nothing else — the token,
     * its uuid and the handle that could end it are as sensitive as the password.
     * A user with no learner record (staff, a parent) has no ledger to write to
     * and gets 0, which is not an error: those accounts have no family screen.
     */
    public function recordNewDevice(int $userId, string $device = ''): int {
        if ($userId <= 0) {
            return 0;
        }
        $studentId = Authorization::studentIdForUser($userId);
        if ($studentId <= 0) {
            return 0;
        }
        // Resolved through the same naming rule the token carries, so the alert,
        // the device list and the history can never disagree about the label.
        $label = self::labelOf(self::appNameFor($device));
        return (new CredentialAuditService())->recordDeviceAdded($userId, $studentId, $label);
    }

    /**
     * PA59 — hook the canonical "a password sign-in just succeeded" action.
     *
     * wp-login.php and every wp_signon() caller fire `wp_login`; this plugin's
     * /auth/login does NOT (it authenticates directly), so the controller calls
     * recordBrowserLogin() itself. Deliberately NOT hooked on `set_auth_cookie`:
     * /auth/refresh re-mints the cookie for an ALREADY signed-in caller, and a
     * refresh must never read as a new sign-in.
     */
    public static function register(): void {
        add_action('wp_login', [self::class, 'onLogin'], 10, 2);
        // PA60 — XML-RPC authenticates through wp_authenticate() and never fires
        // wp_login (core's server has no success hook of its own), so this filter
        // is the only place that door can be seen. Priority 999 puts us AFTER the
        // PA53 throttle, which may have replaced the result with a lock error.
        add_filter('authenticate', [self::class, 'onXmlrpcLogin'], 999, 3);
    }

    /**
     * @param string          $userLogin Core always passes the login name.
     * @param \WP_User|string $user      Core 5.9+ also passes the user object.
     */
    public static function onLogin($userLogin, $user = null): void {
        $userId = $user instanceof \WP_User ? (int) $user->ID : 0;
        if ($userId === 0) {
            $found = get_user_by('login', (string) $userLogin);
            $userId = $found ? (int) $found->ID : 0;
        }
        self::recordBrowserLogin($userId, self::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    }

    /**
     * PA60 — the XML-RPC door.
     *
     * Core's XML-RPC server calls wp_authenticate() for every authenticated call
     * and fires no action once authentication succeeds, so a successful sign-in
     * there used to leave no trace at all — while the browser doors did. This is
     * an observer, not a gate: the result is returned untouched, and anything the
     * throttle already turned into an error stays an error.
     *
     * @param \WP_User|\WP_Error|null $user     Result of the filters before us.
     * @param mixed                   $username Raw login (email allowed).
     * @param mixed                   $password Raw secret — never read here.
     * @return \WP_User|\WP_Error|null
     */
    public static function onXmlrpcLogin($user, $username = '', $password = '') {
        if ($user instanceof \WP_User && self::isXmlrpcRequest()) {
            self::recordBrowserLogin(
                (int) $user->ID,
                self::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
                self::DOOR_XMLRPC
            );
        }
        return $user;
    }

    /**
     * PA60 — is this an XML-RPC request? Core exposes XMLRPC_REQUEST as a
     * process-wide constant, and a constant cannot be un-defined once a test has
     * set it, so the answer goes through a filter — the same seam PA53 uses for
     * the client address. Production keeps core's own verdict by default.
     */
    public static function isXmlrpcRequest(): bool {
        return (bool) apply_filters('aba_is_xmlrpc_request', defined('XMLRPC_REQUEST'));
    }

    /**
     * PA59 — announce a session opened with the account password.
     *
     * PA57 covers the headless flow: a sign-in there mints an app token and is
     * announced. A browser sign-in mints no token, so before this the one path a
     * leaked handout password would be used on left no trace the family could
     * see. PA60 adds the third door — XML-RPC — where the same secret is replayed
     * by a script instead of a page. The payload is the door and the device label
     * and nothing else (§8): never the agent string, the address or a token.
     *
     * Accounts with no learner record (staff, parents) have no family ledger and
     * get 0, which is not an error.
     *
     * @param string $deviceLabel The friendly label ALREADY derived by the caller
     *                            (deviceLabelFor() for the REST door, deviceLabel()
     *                            for wp_login and XML-RPC) — not a raw User-Agent.
     *                            Deriving it twice would turn 'Điện thoại' into ''.
     * @param string $door        DOOR_COOKIE (default) or DOOR_XMLRPC.
     */
    public static function recordBrowserLogin(int $userId, string $deviceLabel = '', string $door = self::DOOR_COOKIE): int {
        if ($userId <= 0 || isset(self::$browserAnnounced[$userId])) {
            return 0;
        }
        $door = $door === self::DOOR_XMLRPC ? self::DOOR_XMLRPC : self::DOOR_COOKIE;
        $windowKey = self::XMLRPC_KEY_PREFIX . $userId;
        if ($door === self::DOOR_XMLRPC && get_transient($windowKey)) {
            return 0;
        }
        $studentId = Authorization::studentIdForUser($userId);
        if ($studentId <= 0) {
            return 0;
        }
        self::$browserAnnounced[$userId] = true;
        $id = (new CredentialAuditService())->recordSessionOpened($userId, $studentId, trim($deviceLabel), $door);
        // The window is armed only once the row exists: a failed write must not
        // buy an hour of silence.
        if ($door === self::DOOR_XMLRPC && $id > 0) {
            set_transient($windowKey, 1, self::XMLRPC_ANNOUNCE_SECONDS);
        }
        return $id;
    }

    /**
     * Destroy every cookie session of the given user and return how many were
     * live. Note: core's `wp_destroy_all_sessions()` only ever acts on the
     * CURRENT user and takes no argument, so a staff acting on a learner must go
     * through the token manager directly — the naive call would silently no-op.
     */
    public function destroySessions(int $userId): int {
        if ($userId <= 0 || !class_exists('WP_Session_Tokens')) {
            return 0;
        }
        $manager = \WP_Session_Tokens::get_instance($userId);
        $live = count($manager->get_all());
        $manager->destroy_all();
        return $live;
    }

    /**
     * PA52 — how many logins are live right now, without revealing any of them.
     *
     * Deliberately counts only: a token, uuid or hash must never leave the
     * server (§8). `sessions` are cookie logins; `appPasswords` are the Basic
     * tokens the headless app mints one of per device sign-in (PA54).
     *
     * @return array{appPasswords:int,sessions:int,total:int}
     */
    public function inventory(int $userId): array {
        if ($userId <= 0) {
            return ['appPasswords' => 0, 'sessions' => 0, 'total' => 0];
        }
        $apps = count($this->ours($userId));
        $sessions = class_exists('WP_Session_Tokens')
            ? count(\WP_Session_Tokens::get_instance($userId)->get_all())
            : 0;
        return [
            'appPasswords' => $apps,
            'sessions'     => $sessions,
            'total'        => $apps + $sessions,
        ];
    }

    /**
     * End every live login of this account — both credential channels.
     *
     * @return array{appPasswords:int,sessions:int}
     */
    public function revokeAll(int $userId): array {
        return [
            'appPasswords' => $this->revokeAppPasswords($userId),
            'sessions'     => $this->destroySessions($userId),
        ];
    }
}
