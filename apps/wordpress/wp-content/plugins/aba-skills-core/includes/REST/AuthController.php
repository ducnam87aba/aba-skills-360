<?php
/**
 * Authentication endpoints (api-contract.md §11-13).
 *
 * Uses WordPress' native cookie session — the contract explicitly allows
 * "HTTP-only cookies rather than JSON tokens" (§279) and the fixed stack has no
 * JWT dependency (AGENTS.md §14). The password is never echoed back; only the
 * user DTO is returned (§1875 forbids leaking secrets).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

use function ABA\Core\Auth\aba_error;
use function ABA\Core\Auth\aba_user_dto;

use ABA\Core\Auth\Authorization;
use ABA\Core\Services\CredentialAuditService;
use ABA\Core\Services\CredentialSessionService;
use ABA\Core\Services\LoginThrottleService;

if (!defined('ABSPATH')) {
    exit;
}

class AuthController {
    /**
     * Prefix of the app-password name this plugin mints; one token per device
     * since PA54, so the stored name continues with the device label.
     * PA51 — the canonical value lives in CredentialSessionService (a Service,
     * so the dependency points REST -> Service), aliased here for callers that
     * already read it from the controller.
     */
    public const APP_NAME = \ABA\Core\Services\CredentialSessionService::APP_NAME;

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        register_rest_route($namespace, '/auth/login', [
            'methods' => 'POST',
            'callback' => [$this, 'login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/auth/logout', [
            'methods' => 'POST',
            'callback' => [$this, 'logout'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/auth/refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'refresh'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // PA48 — forced first-login password change. The learner replaces a
        // staff-generated handout password with their own secret. The session
        // alone is not enough: the CURRENT password must be presented again so
        // a hijacked persisted app token cannot lock the family out.
        register_rest_route($namespace, '/auth/change-password', [
            'methods' => 'POST',
            'callback' => [$this, 'changePassword'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // PA14: same-origin clients (Next via the /wp-json rewrite) authenticate
        // with the WP cookie session, so state-changing calls must also carry the
        // wp_rest CSRF nonce. This hands the current session its nonce to attach
        // as X-WP-Nonce. Anonymous callers get a valid nonce for their (logged-out)
        // session but must log in first for it to authorize anything.
        register_rest_route($namespace, '/auth/nonce', [
            'methods' => 'GET',
            'callback' => [$this, 'nonce'],
            'permission_callback' => '__return_true',
        ]);

        // PA55 — the caller's OWN live devices. Identity is the session, never an
        // id in the URL, so these routes can only ever act on the caller's
        // account (§8). Nothing replayable comes back: a row carries a handle
        // derived from the token's uuid, a device label and two timestamps.
        register_rest_route($namespace, '/me/devices', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'devices'],
                'permission_callback' => 'is_user_logged_in',
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'revokeOtherDevices'],
                'permission_callback' => 'is_user_logged_in',
            ],
        ]);

        register_rest_route($namespace, '/me/devices/(?P<handle>[A-Za-z0-9]{16,64})', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'revokeDevice'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public function login(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $request->get_json_params() ?: [];
        $username = (string)($params['username'] ?? '');
        $password = (string)($params['password'] ?? '');

        if ($username === '' || $password === '') {
            return aba_error('INVALID_REQUEST', 'Username and password are required.', 400);
        }

        // PA53 — the attempt counter no longer lives here. It sits in
        // LoginThrottleService on the `authenticate` filter, which this call and
        // wp-login.php / XML-RPC all pass through, so there is one ledger of
        // attempts instead of one per door (PA19 guarded only this route).
        $user = wp_authenticate($username, $password);
        if (is_wp_error($user)) {
            if ($user->get_error_code() === LoginThrottleService::ERROR_CODE) {
                $data = (array) $user->get_error_data();
                $details = (array) ($data['details'] ?? []);
                // 429 + retryAfter: the client can tell the family how long to
                // wait instead of a vague "try later" (ErrorNormalizer also sets
                // the Retry-After header from these details).
                return aba_error('ACCOUNT_LOCKED', LoginThrottleService::MESSAGE, 429, [
                    'retryAfter' => (int) ($details['retryAfter'] ?? 0),
                ]);
            }
            // Uniform message — never reveal whether the account exists (§1875).
            return aba_error('INVALID_CREDENTIALS', 'Invalid username or password.', 401);
        }

        if (get_user_meta($user->ID, 'aba_account_status', true) === 'disabled') {
            return aba_error('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        wp_set_current_user($user->ID);

        // PA50 — rotation reminder. Evaluate the credential's age at sign-in; a
        // lapsed handout arms aba_must_change_password, so the DTO below carries
        // mustChangePassword=true and the client routes to the change screen.
        (new \ABA\Core\Services\StudentService())->enforceCredentialExpiry($user->ID);

        // PA37 — the headless front end authenticates with an Application
        // Password replayed via HTTP Basic on every later request (the scheme
        // WP 6.5+ retains for app passwords). Core only reveals an app password
        // once, at creation — so each sign-in mints a fresh one.
        //
        // PA54 — per DEVICE. This used to delete the account's single named token
        // first, so signing in on a phone silently signed the tablet out. Each
        // device now keeps its own labelled token (capped by MAX_DEVICES), which
        // is also what makes "sign out this device" expressible at all.
        $token = self::issueAppPassword($user->ID, self::deviceLabelFor($request));

        // PA39 — the cookie is the credential only in the non-headless fallback.
        // When a Basic token was issued we deliberately do NOT set a session
        // cookie: the Next /wp-json rewrite makes the backend same-origin, so a
        // browser would replay this cookie on later GETs, and core's
        // rest_cookie_check_errors() then wipes the Basic-authenticated user
        // when no X-WP-Nonce rides along — the exact 401 that forced the
        // bootstrap's priority-80 guard. Suppressing the cookie removes that root
        // cause; the guard now stands only as defence-in-depth against a stray
        // /wp-admin session on the same host.
        if ($token === null) {
            wp_set_auth_cookie($user->ID, true);
            // PA59 — this is the browser path: no app token was minted, so PA57's
            // device alert cannot fire and nothing else would record the sign-in.
            // Announced after the cookie exists; the service guards against a
            // wp_login hook firing for the same request, so one login is one row.
            CredentialSessionService::recordBrowserLogin($user->ID, self::deviceLabelFor($request));
        }

        return rest_ensure_response(['user' => aba_user_dto($user), 'token' => $token]);
    }

    /**
     * Create a fresh app password for THIS sign-in and return its plaintext, or
     * null when app passwords are unavailable in this environment. The client
     * never sees the real password — only the 24-char app token.
     * Driven through WP_Application_Passwords directly: this build ships no
     * procedural wrapper, and the class file is required on demand.
     *
     * PA54 — the token is named for the device and left alone alongside the
     * account's other devices. Pruning happens first, keeping room for this one,
     * so the cap holds even if token creation fails afterwards.
     */
    private static function issueAppPassword(int $userId, string $device = ''): ?string {
        if (!class_exists('WP_Application_Passwords')) {
            return null;
        }
        require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
        if (!apply_filters('wp_is_application_passwords_available', true)
            || !apply_filters('wp_is_application_passwords_available_for_user', true, $userId)) {
            return null;
        }
        $sessions = new CredentialSessionService();
        $sessions->pruneAppPasswords($userId, CredentialSessionService::MAX_DEVICES - 1);
        $result = \WP_Application_Passwords::create_new_application_password($userId, [
            'name' => CredentialSessionService::appNameFor($device),
            'uuid' => wp_generate_password(16, false),
        ]);
        if (is_wp_error($result)) {
            return null;
        }
        // PA57 — a device that did not hold this account a second ago now does,
        // and the family should not have to go looking to find out. Announced
        // only after a token really exists, so a failed sign-in stays silent.
        $sessions->recordNewDevice($userId, $device);
        // Core returns [ rawPassword, item ] — hand back the raw 24-char token.
        return (string) $result[0];
    }

    /**
     * PA54 — which device is signing in. A client may state it explicitly
     * (`X-ABA-Device`, for native apps and a future device screen); otherwise it
     * is read off the User-Agent. The value only ever names a token, so it is
     * truncated and stripped of anything a name should not carry.
     */
    private static function deviceLabelFor(WP_REST_Request $request): string {
        $stated = sanitize_text_field(wp_html_excerpt((string) $request->get_header('X-ABA-Device'), 32, ''));
        if ($stated !== '') {
            return $stated;
        }
        return CredentialSessionService::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    /**
     * PA54 — the app password THIS request presented, or null when it did not
     * use one. Core records the uuid while authenticating the request; the
     * header check keeps that value tied to credentials actually presented, so
     * a cookie-authenticated sign-out can never borrow a uuid from elsewhere.
     */
    private static function currentAppPasswordUuid(): ?string {
        if (!function_exists('rest_get_authenticated_app_password')) {
            return null;
        }
        $usedBasic = isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        if (!$usedBasic) {
            return null;
        }
        $uuid = rest_get_authenticated_app_password();
        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * Sign out THIS device (api-contract §12: 204 No Content).
     *
     * PA54 — the account can be signed in on several devices, so a blanket
     * revoke here would sign the family out of every phone because one of them
     * tapped "log out". Over Basic, core tells us which app password
     * authenticated this request, so exactly that token dies; the legacy cookie
     * fallback destroys only this browser's session. Ending every device remains
     * available where it belongs: a password change or a staff reset.
     */
    public function logout(WP_REST_Request $request): WP_REST_Response {
        $user = wp_get_current_user();
        if ($user && $user->ID !== 0) {
            $uuid = self::currentAppPasswordUuid();
            if ($uuid !== null) {
                (new CredentialSessionService())->revokeAppPassword($user->ID, $uuid);
            } else {
                wp_logout();
            }
            wp_set_current_user(0);
        }
        return new WP_REST_Response(null, 204);
    }

    /**
     * PA55 — the caller's own live devices, the one asking first.
     *
     * A parent or learner can finally see "which machines am I still signed in
     * on" without being handed a token: the rows carry a derived handle only.
     */
    public function devices(WP_REST_Request $request): WP_REST_Response {
        $user = wp_get_current_user();
        $rows = (new CredentialSessionService())
            ->devices((int) $user->ID, (string) self::currentAppPasswordUuid());
        return rest_ensure_response([
            'devices'    => $rows,
            'count'      => count($rows),
            'maxDevices' => CredentialSessionService::MAX_DEVICES,
        ]);
    }

    /**
     * PA55 — end ONE device of the caller's own account.
     *
     * A handle that resolves to nothing is a 404, never a silent 204: "already
     * signed out" and "that id was never yours" must not look identical (§8).
     */
    public function revokeDevice(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user = wp_get_current_user();
        $handle = (string) ($request->get_param('handle') ?? '');
        if ((new CredentialSessionService())->revokeDevice((int) $user->ID, $handle) !== 'revoked') {
            return aba_error('ABA_DEVICE_NOT_FOUND', 'This device is not signed in.', 404);
        }
        return rest_ensure_response(['revoked' => 1]);
    }

    /**
     * PA55 — "sign out everywhere else", keeping the device that asked.
     */
    public function revokeOtherDevices(WP_REST_Request $request): WP_REST_Response {
        $user = wp_get_current_user();
        $ended = (new CredentialSessionService())
            ->revokeOtherDevices((int) $user->ID, (string) self::currentAppPasswordUuid());
        return rest_ensure_response(['revoked' => $ended]);
    }

    public function refresh(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user = wp_get_current_user();
        if (!$user || $user->ID === 0) {
            return aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
        }
        wp_set_auth_cookie($user->ID, true);
        return rest_ensure_response(['success' => true]);
    }

    /**
     * Change the signed-in user's own password (§8: user id is taken from the
     * session, never the request). On success the forced-change flag is cleared
     * and every outstanding app token is revoked — the old handout password and
     * any token issued with it stop working, so the family re-authenticates
     * with the new secret (the client handles the 204 by sending logout/re-login).
     */
    public function changePassword(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user = wp_get_current_user();
        if (!$user || $user->ID === 0) {
            return aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
        }
        $params = $request->get_json_params() ?: [];
        $old = (string) ($params['oldPassword'] ?? '');
        $new = (string) ($params['newPassword'] ?? '');

        if ($old === '' || $new === '') {
            return aba_error('INVALID_REQUEST', 'Current and new password are required.', 400);
        }
        if (strlen($new) < 8) {
            return aba_error('PASSWORD_TOO_SHORT', 'New password must be at least 8 characters.', 400);
        }
        if (!wp_check_password($old, $user->user_pass, $user->ID)) {
            return aba_error('INVALID_CREDENTIALS', 'Current password is incorrect.', 403);
        }
        if (wp_check_password($new, $user->user_pass, $user->ID)) {
            return aba_error('PASSWORD_UNCHANGED', 'New password must differ from the current one.', 400);
        }

        wp_set_password($new, $user->ID);
        delete_user_meta($user->ID, 'aba_must_change_password');
        // PA50 — this is the learner choosing their OWN secret, so it becomes the
        // rotation anchor: the next expiry measures from now, not from the older
        // staff issue stamp (a genuine self-service change buys a fresh window).
        update_user_meta($user->ID, 'aba_password_self_changed_at', current_time('mysql', 1));
        // The app password we are authorizing this call with dies here too.
        // PA51 — and so must every session token: this build's wp_set_password()
        // does not touch sessions at all (core's helper only ever acts on the
        // CURRENT user), so relying on "core's password-change sync" left the
        // old token alive elsewhere. Revoke both channels explicitly.
        (new CredentialSessionService())->revokeAll($user->ID);
        // PA49 — record the self-service change on the credential ledger. The
        // actor is the signed-in learner; nothing secret is written (§8).
        (new CredentialAuditService())->recordSelfChange($user->ID, Authorization::studentIdForUser($user->ID));
        // 204 like logout — the contract says state-change confirmations carry no body.
        return new WP_REST_Response(null, 204);
    }

    /** Hand the session its wp_rest nonce so the client can authorize writes (PA14). */
    public function nonce(WP_REST_Request $request): WP_REST_Response {
        $user = wp_get_current_user();
        $loggedIn = $user && $user->ID !== 0;
        return rest_ensure_response([
            'nonce' => $loggedIn ? wp_create_nonce('wp_rest') : '',
            'authenticated' => $loggedIn,
        ]);
    }
}
