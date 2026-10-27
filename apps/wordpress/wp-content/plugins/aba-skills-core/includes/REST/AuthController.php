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

if (!defined('ABSPATH')) {
    exit;
}

class AuthController {
    /**
     * PA19 — brute-force protection (§8: student data is sensitive). Failed
     * sign-ins are counted per username in a short-lived window; once the cap is
     * hit the account is locked out even for a correct password until the window
     * expires. A success clears the counter. The transient is the only state, so
     * nothing is persisted against the user record.
     */
    public const MAX_ATTEMPTS = 5;
    public const LOCK_SECONDS = 900; // 15-minute sliding window
    public const FAIL_KEY_PREFIX = 'aba_login_fail_';

    /** Dedicated app-password name per demo login; recycled on every sign-in. */
    public const APP_NAME = 'ABA headless session';

    public static function failKey(string $username): string {
        return self::FAIL_KEY_PREFIX . strtolower($username);
    }

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
    }

    public function login(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $request->get_json_params() ?: [];
        $username = (string)($params['username'] ?? '');
        $password = (string)($params['password'] ?? '');

        if ($username === '' || $password === '') {
            return aba_error('INVALID_REQUEST', 'Username and password are required.', 400);
        }

        // Lockout gate is checked before authentication so a correct password
        // cannot bypass an active lock (PA19, §8).
        $key = self::failKey($username);
        $fails = (int) get_transient($key);
        if ($fails >= self::MAX_ATTEMPTS) {
            return aba_error('ACCOUNT_LOCKED', 'Too many failed sign-in attempts. Please try again later.', 429);
        }

        $user = wp_authenticate($username, $password);
        if (is_wp_error($user)) {
            $fails++;
            set_transient($key, $fails, self::LOCK_SECONDS);
            if ($fails >= self::MAX_ATTEMPTS) {
                return aba_error('ACCOUNT_LOCKED', 'Too many failed sign-in attempts. Please try again later.', 429);
            }
            // Uniform message — never reveal whether the account exists (§1875).
            return aba_error('INVALID_CREDENTIALS', 'Invalid username or password.', 401);
        }

        if (get_user_meta($user->ID, 'aba_account_status', true) === 'disabled') {
            return aba_error('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        // Successful sign-in resets the failure window.
        delete_transient($key);

        wp_set_current_user($user->ID);

        // PA37 — the headless front end authenticates with an Application
        // Password replayed via HTTP Basic on every later request (the scheme
        // WP 6.5+ retains for app passwords). Core only reveals an app password
        // once, at creation — so we recreate the named session app on each login
        // (delete-then-create keeps exactly one, never accumulates) and return
        // the fresh plaintext. Availability is gated by the plugin bootstrap
        // (local env type or the seeder flag).
        $token = self::issueAppPassword($user->ID);

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
        }

        return rest_ensure_response(['user' => aba_user_dto($user), 'token' => $token]);
    }

    /**
     * Create (or recreate) this login's dedicated app password and return its
     * plaintext, or null when app passwords are unavailable in this environment.
     * The client never sees the real password — only the 24-char app token.
     * Driven through WP_Application_Passwords directly: this build ships no
     * procedural wrapper, and the class file is required on demand.
     */
    private static function issueAppPassword(int $userId): ?string {
        if (!class_exists('WP_Application_Passwords')) {
            return null;
        }
        require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
        if (!apply_filters('wp_is_application_passwords_available', true)
            || !apply_filters('wp_is_application_passwords_available_for_user', true, $userId)) {
            return null;
        }
        // Recycle only *our* named app (an admin's manually issued apps survive);
        // reusing the name means the list never grows past one entry per login.
        self::revokeAppPasswords($userId);
        $result = \WP_Application_Passwords::create_new_application_password($userId, [
            'name' => self::APP_NAME,
            'uuid' => wp_generate_password(16, false),
        ]);
        if (is_wp_error($result)) {
            return null;
        }
        // Core returns [ rawPassword, item ] — hand back the raw 24-char token.
        return (string) $result[0];
    }

    /**
     * Delete this login's dedicated app password(s). Used both to recycle the
     * token at sign-in (issueAppPassword) and to kill it at sign-out, so a
     * revoked token can never be replayed even before the next login. Admins'
     * manually issued apps are left untouched (name filter). Silently no-ops
     * where the class is unavailable so the cookie fallback still signs out.
     */
    private static function revokeAppPasswords(int $userId): void {
        if (!class_exists('WP_Application_Passwords')) {
            return;
        }
        require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
        foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $app) {
            if (($app['name'] ?? '') === self::APP_NAME) {
                \WP_Application_Passwords::delete_application_password($userId, $app['uuid']);
            }
        }
    }

    public function logout(WP_REST_Request $request): WP_REST_Response {
        $user = wp_get_current_user();
        if ($user && $user->ID !== 0) {
            // PA39 — over Basic the app password, not a cookie, is the credential,
            // so sign-out must revoke it; clearing the (possibly absent) cookie is
            // kept for the legacy fallback session.
            self::revokeAppPasswords($user->ID);
            wp_clear_auth_cookie();
            wp_set_current_user(0);
        }
        // api-contract §12: 204 No Content.
        return new WP_REST_Response(null, 204);
    }

    public function refresh(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user = wp_get_current_user();
        if (!$user || $user->ID === 0) {
            return aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
        }
        wp_set_auth_cookie($user->ID, true);
        return rest_ensure_response(['success' => true]);
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
