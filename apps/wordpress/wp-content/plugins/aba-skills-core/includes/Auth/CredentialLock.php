<?php
/**
 * Server-side enforcement of the forced-change credential state (PA51, §8).
 *
 * PA48 gave a staff-generated handout password a forced first-login change, and
 * PA50 re-armed the same flag when a credential aged past its rotation window.
 * Both only ever *advised* the client: the DTO carried `mustChangePassword` and
 * the Next.js guard routed to /change-password. Nothing stopped a caller that
 * still held a live token from skipping the screen and using the whole API — a
 * "forced" change that is only enforced by the client is not enforced at all
 * (AGENTS.md §8: never rely on frontend checks for authorization).
 *
 * This filter closes that gap in one place. It runs after authentication and
 * route matching but *before* the permission callback and the handler, so every
 * aba/v1 route is covered without touching a single controller — and a new
 * endpoint added later is locked by default rather than by remembering to opt
 * in. Only the routes needed to escape the state are reachable: identify
 * (/me), present the current secret and set a new one (/auth/change-password),
 * or leave (/auth/logout, /auth/login).
 *
 * The flag itself is the only state consulted. It is written solely by the
 * learner credential flow, so staff and parent accounts are never affected.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Auth;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

class CredentialLock {
    /** Every ABA route lives under this prefix. */
    private const NAMESPACE_PREFIX = '/aba/v1';

    /**
     * Routes a locked credential may still call. Deliberately an allowlist: a
     * forgotten endpoint stays closed instead of silently staying open.
     */
    private const ALLOWED_ROUTES = [
        '/aba/v1',
        '/aba/v1/me',
        '/aba/v1/auth/login',
        '/aba/v1/auth/logout',
        '/aba/v1/auth/refresh',
        '/aba/v1/auth/change-password',
        '/aba/v1/auth/nonce',
    ];

    public static function register(): void {
        add_filter('rest_request_before_callbacks', [self::class, 'guard'], 10, 3);
    }

    /**
     * Replace a locked request with a 403 before it reaches the endpoint.
     *
     * @param mixed                $response Response so far (null on the normal path).
     * @param array|null           $handler  Matched route handler.
     * @param WP_REST_Request|null $request  The request being dispatched.
     * @return mixed
     */
    public static function guard($response, $handler = null, ?WP_REST_Request $request = null) {
        if ($response !== null || !$request instanceof WP_REST_Request) {
            return $response;
        }
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return $response; // anonymous callers are the authentication layer's business
        }
        if (get_user_meta($userId, 'aba_must_change_password', true) !== '1') {
            return $response;
        }
        $route = rtrim((string) $request->get_route(), '/');
        if ($route !== self::NAMESPACE_PREFIX && strpos($route, self::NAMESPACE_PREFIX . '/') !== 0) {
            return $response; // not an aba/v1 route
        }
        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return $response;
        }
        // Same contract shape as every other error (§6): the client branches on
        // the code, and the details carry the reason without leaking anything.
        return aba_error(
            'CREDENTIAL_CHANGE_REQUIRED',
            'Set a new password before continuing.',
            403,
            ['mustChangePassword' => true]
        );
    }
}
