<?php
/**
 * Suite: one login per device (PA54, AGENTS.md §8).
 *
 * PA37 minted a single app password named "ABA headless session" and deleted it
 * at every sign-in, so a second device silently signed the first one out — and
 * "sign out" could only ever mean "sign out everywhere". PA54 gives each device
 * its own labelled token and scopes sign-out to the token the request actually
 * presented. This suite proves:
 *   - two sign-ins from two devices leave TWO live tokens, each labelled with
 *     the device, and both authenticate;
 *   - signing out over one token ends exactly that token — the other device
 *     keeps working — while the response is still a plain 204;
 *   - the account stops at MAX_DEVICES, dropping the least recently used one;
 *   - an administrator's hand-issued app is never counted, pruned or revoked;
 *   - a token minted before PA54 (bare name) is still ours for count/revoke, so
 *     upgrading never orphans a live session;
 *   - another account's uuid cannot be revoked through this account;
 *   - a cookie sign-out (the legacy fallback) ends no device token at all.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\CredentialSessionService;

return function (AbaTestKit $kit): void {
    $kit->section('DeviceSessionsTest — one login per device (PA54)');

    require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';

    // Core only attempts app-password auth on an API request, and it decides
    // that from REST_REQUEST — a constant no CLI harness ever defines. Declaring
    // it here is what a real /wp-json call gets, and without it every token
    // assertion below would pass or fail for the wrong reason.
    add_filter('application_password_is_api_request', '__return_true');

    $studentId = $kit->makeStudent('4_6');
    $password  = 'Aba-Th1etBi!2026';
    $service   = new CredentialSessionService();

    /** Raw rows of OUR tokens, in core's own order (oldest sign-in first). */
    $ourApps = static function (int $userId): array {
        $out = [];
        foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $app) {
            if (CredentialSessionService::isOurApp((string) ($app['name'] ?? ''))) {
                $out[] = $app;
            }
        }
        return $out;
    };
    $countOurs = static fn(int $userId): int => count($ourApps($userId));

    /** Sign in as if the request came from this device; returns [status, token]. */
    $signIn = static function (string $login, string $agent) use ($password): array {
        $previous = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = $agent;
        $req = new WP_REST_Request('POST', '/aba/v1/auth/login');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode(['username' => $login, 'password' => $password]));
        $resp = rest_do_request($req);
        if ($previous === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $previous;
        }
        $token = $resp->get_data()['token'] ?? null;
        return ['status' => $resp->get_status(), 'token' => is_string($token) ? $token : null];
    };

    /**
     * Drive one request the way a real HTTP client would: a fresh request starts
     * with no cached current user, so Basic auth is resolved again instead of
     * reusing the identity the previous call left behind in this PHP process.
     */
    $call = static function (string $token, string $login, string $method = 'GET', string $route = '/aba/v1/me'): WP_REST_Response {
        global $current_user;
        $current_user = null;
        $_SERVER['PHP_AUTH_USER'] = $login;
        $_SERVER['PHP_AUTH_PW'] = $token;
        $resp = rest_do_request(new WP_REST_Request($method, $route));
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        return $resp;
    };

    $extra = [];
    $extraUsers = [];

    try {
        $userId = $kit->makeUser($studentId, $password);
        $login  = (string) get_user_by('id', $userId)->user_login;

        $available = apply_filters('wp_is_application_passwords_available', true)
            && apply_filters('wp_is_application_passwords_available_for_user', true, $userId);
        if (!$available) {
            // Nothing to prove where the environment forbids app passwords; the
            // cookie fallback is covered by AuthTest.
            $kit->check('app passwords unavailable — device sessions not exercisable here', true);
            return;
        }

        // ---- (A) two devices, two tokens, both alive --------------------------
        $phone = $signIn($login, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
        $tablet = $signIn($login, 'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
        $kit->checkSame('sign-in on a phone => 200', 200, $phone['status']);
        $kit->checkSame('sign-in on a tablet => 200', 200, $tablet['status']);
        $kit->check('the second device gets its OWN token', $phone['token'] !== null && $tablet['token'] !== null && $phone['token'] !== $tablet['token']);
        $kit->checkSame('both devices stay signed in', 2, $countOurs($userId));

        $names = array_map(static fn(array $app): string => (string) ($app['name'] ?? ''), $ourApps($userId));
        $kit->check('the phone token is labelled for a phone', in_array(CredentialSessionService::appNameFor('Điện thoại'), $names, true));
        $kit->check('the tablet token is labelled for a tablet', in_array(CredentialSessionService::appNameFor('Máy tính bảng'), $names, true));

        $kit->checkSame('the phone token authenticates', 200, $call((string) $phone['token'], $login)->get_status());
        $kit->checkSame('the tablet token authenticates too', 200, $call((string) $tablet['token'], $login)->get_status());

        // ---- (B) sign out on one device, not on the account -------------------
        $kit->checkSame('signing out on the phone => 204', 204, $call((string) $phone['token'], $login, 'POST', '/aba/v1/auth/logout')->get_status());
        $kit->checkSame('   only the phone token died', 1, $countOurs($userId));
        $kit->checkSame('   the phone is signed out', 401, $call((string) $phone['token'], $login)->get_status());
        $kit->checkSame('   the tablet never noticed', 200, $call((string) $tablet['token'], $login)->get_status());

        // ---- (C) the device cap holds, least recently used goes first ---------
        $service->revokeAppPasswords($userId);
        $tokens = [];
        for ($i = 1; $i <= CredentialSessionService::MAX_DEVICES + 1; $i++) {
            $made = $signIn($login, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.' . $i);
            $tokens[] = (string) $made['token'];
        }
        $kit->checkSame('the account keeps at most MAX_DEVICES logins', CredentialSessionService::MAX_DEVICES, $countOurs($userId));
        $kit->checkSame('   the oldest device was dropped', 401, $call($tokens[0], $login)->get_status());
        $kit->checkSame('   the newest device still works', 200, $call($tokens[CredentialSessionService::MAX_DEVICES], $login)->get_status());

        // ---- (D) an administrator's own app is not ours to manage -------------
        $manual = \WP_Application_Passwords::create_new_application_password($userId, ['name' => 'Admin issued app']);
        $manualUuid = is_array($manual) ? (string) ($manual[1]['uuid'] ?? '') : '';
        $manualAlive = static function () use ($userId, $manualUuid): bool {
            foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $app) {
                if ((string) ($app['uuid'] ?? '') === $manualUuid) {
                    return true;
                }
            }
            return false;
        };
        $kit->check('an admin app is not one of ours', !CredentialSessionService::isOurApp('Admin issued app'));
        $kit->check('it is not counted as a device login', count($ourApps($userId)) === $countOurs($userId) && !in_array('Admin issued app', array_column($ourApps($userId), 'name'), true));
        $signIn($login, 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Mobile Safari/537.36');
        $kit->check('a sign-in leaves the admin app alone', $manualAlive());
        $ended = $service->revokeAppPasswords($userId);
        $kit->check('a full revoke ends every device of ours', $ended > 0 && $countOurs($userId) === 0);
        $kit->check('   ...and still leaves the admin app alone', $manualAlive());

        // ---- (E) a token minted before PA54 is still ours ---------------------
        \WP_Application_Passwords::create_new_application_password($userId, ['name' => CredentialSessionService::APP_NAME]);
        $kit->checkSame('the legacy bare-name token counts as a live login', 1, $service->inventory($userId)['appPasswords']);
        $kit->checkSame('   and a revoke ends it', 1, $service->revokeAppPasswords($userId));

        // ---- (F) a cookie sign-out ends no device ----------------------------
        $spare = $signIn($login, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/121.0');
        $kit->checkSame('a fresh device is signed in', 1, $countOurs($userId));
        wp_set_current_user($userId);
        $cookieOut = rest_do_request(new WP_REST_Request('POST', '/aba/v1/auth/logout'));
        $kit->checkSame('a cookie sign-out => 204', 204, $cookieOut->get_status());
        $kit->checkSame('   it ends no device token', 1, $countOurs($userId));
        $kit->checkSame('   and this session is cleared', 0, get_current_user_id());
        $kit->checkSame('   the device token still works', 200, $call((string) $spare['token'], $login)->get_status());

        // ---- (G) another account's uuid cannot be revoked from here ----------
        $other = $kit->makeStudent('4_6');
        $extra[] = $other;
        $otherUser = $kit->makeUser($other, 'Aba-Khac!2026');
        $extraUsers[] = $otherUser;
        \WP_Application_Passwords::create_new_application_password($otherUser, ['name' => CredentialSessionService::APP_NAME]);
        $foreignUuid = (string) ($ourApps($otherUser)[0]['uuid'] ?? '');
        $kit->checkSame('revoking a uuid this account does not own is refused', false, $service->revokeAppPassword($userId, $foreignUuid));
        $kit->checkSame('   and that account keeps its login', 1, $countOurs($otherUser));
    } finally {
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        remove_filter('application_password_is_api_request', '__return_true');
        wp_set_current_user(0);
        foreach ($extra as $sid) {
            $kit->cleanup($sid);
        }
        foreach ($extraUsers as $uid) {
            $kit->cleanupUser($uid);
        }
        $kit->cleanupUser($userId ?? 0);
        $kit->cleanup($studentId);
    }
};
