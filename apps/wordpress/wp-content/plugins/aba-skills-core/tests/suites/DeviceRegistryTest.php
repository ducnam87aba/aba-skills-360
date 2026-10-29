<?php
/**
 * Suite: the caller's own device list (PA55, AGENTS.md §8).
 *
 * PA54 gave each device its own token, but nobody could see them — the family
 * could only learn "6 devices are signed in" from the staff screen. PA55 adds
 * GET /me/devices, DELETE /me/devices/{handle} and DELETE /me/devices, all
 * scoped to the session's own account. This suite proves:
 *   - the list shows the caller's devices, the asking one first, with labels and
 *     timestamps, and leaks neither a token nor a uuid (§8);
 *   - the handle is stable, is a derived value (not the uuid) and ends exactly
 *     one device — 404, not a silent success, once that device is already gone;
 *   - "sign out everywhere else" keeps the device that asked;
 *   - an anonymous caller gets 401, and another account's handle is a 404 that
 *     leaves that account signed in (§8: no cross-account id walking);
 *   - a cookie session lists the same devices with no row marked current.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\CredentialSessionService;

return function (AbaTestKit $kit): void {
    $kit->section('DeviceRegistryTest — the caller\'s own devices (PA55)');

    require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';

    // Core only attempts app-password auth on an API request (REST_REQUEST),
    // which a CLI harness never defines. Without this every token assertion
    // below would pass or fail for the wrong reason.
    add_filter('application_password_is_api_request', '__return_true');

    $studentId = $kit->makeStudent('4_6');
    $password  = 'Aba-DanhBa!2026';
    $service   = new CredentialSessionService();

    /** Raw rows of OUR tokens, as core stores them. */
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
    $signIn = static function (string $login, string $agent, string $secret = '') use ($password): array {
        $previous = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = $agent;
        $req = new WP_REST_Request('POST', '/aba/v1/auth/login');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode(['username' => $login, 'password' => $secret !== '' ? $secret : $password]));
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
     * Drive one request the way a real HTTP client would: no cached current
     * user, Basic credentials presented, and nothing left behind afterwards.
     */
    $call = static function (?string $token, string $login, string $method = 'GET', string $route = '/aba/v1/me'): WP_REST_Response {
        global $current_user;
        $current_user = null;
        if ($token !== null) {
            $_SERVER['PHP_AUTH_USER'] = $login;
            $_SERVER['PHP_AUTH_PW']   = $token;
        }
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
            $kit->check('app passwords unavailable — the device list is not exercisable here', true);
            return;
        }

        $phone   = $signIn($login, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1');
        $tablet  = $signIn($login, 'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1');
        $desktop = $signIn($login, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0 Safari/537.36');
        $kit->checkSame('three devices signed in', 3, $countOurs($userId));

        // ---- (A) the list, read from the desktop -----------------------------
        $listed = $call((string) $desktop['token'], $login, 'GET', '/aba/v1/me/devices');
        $kit->checkSame('GET /me/devices => 200', 200, $listed->get_status());
        $body = (array) $listed->get_data();
        $rows = (array) ($body['devices'] ?? []);
        $kit->checkSame('it lists every live device', 3, (int) ($body['count'] ?? 0));
        $kit->checkSame('it publishes the device cap', CredentialSessionService::MAX_DEVICES, (int) ($body['maxDevices'] ?? 0));
        $kit->checkSame('   and one row per device', 3, count($rows));

        $currentRows = array_values(array_filter($rows, static fn(array $r): bool => ($r['current'] ?? false) === true));
        $kit->checkSame('exactly one row is the asking device', 1, count($currentRows));
        $kit->checkSame('   and it is the desktop that asked', 'Máy tính', (string) ($currentRows[0]['label'] ?? ''));
        $kit->check('   the asking device sorts first', ($rows[0]['current'] ?? false) === true);

        $labels = array_map(static fn(array $r): string => (string) ($r['label'] ?? ''), $rows);
        $kit->check('the phone is labelled', in_array('Điện thoại', $labels, true));
        $kit->check('the tablet is labelled', in_array('Máy tính bảng', $labels, true));
        $kit->check('the desktop is labelled', in_array('Máy tính', $labels, true));
        $kit->check(
            'every row carries a sign-in time',
            count(array_filter($rows, static fn(array $r): bool => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($r['createdAt'] ?? '')) === 1)) === 3
        );

        // §8 — the listing is safe to show: no token, no uuid, no hash.
        $raw = (string) wp_json_encode($body);
        $leaks = [];
        foreach ([$phone, $tablet, $desktop] as $session) {
            if (is_string($session['token']) && strpos($raw, $session['token']) !== false) {
                $leaks[] = 'token';
            }
        }
        foreach ($ourApps($userId) as $app) {
            $uuid = (string) ($app['uuid'] ?? '');
            if ($uuid !== '' && strpos($raw, $uuid) !== false) {
                $leaks[] = 'uuid';
            }
        }
        $kit->check('the listing leaks no token and no uuid (§8)', $leaks === []);

        $handles = array_map(static fn(array $r): string => (string) ($r['handle'] ?? ''), $rows);
        $uuidOf  = static function (string $handle) use ($ourApps, $userId): string {
            foreach ($ourApps($userId) as $app) {
                $uuid = (string) ($app['uuid'] ?? '');
                if ($uuid !== '' && CredentialSessionService::deviceHandle($uuid) === $handle) {
                    return $uuid;
                }
            }
            return '';
        };
        $kit->check('every handle resolves to one of the account\'s tokens', count(array_filter($handles, static fn(string $h): bool => $uuidOf($h) !== '')) === 3);
        $kit->check('a handle is not the uuid it stands for', count(array_intersect($handles, array_column($ourApps($userId), 'uuid'))) === 0);

        $again = (array) ($call((string) $desktop['token'], $login, 'GET', '/aba/v1/me/devices')->get_data()['devices'] ?? []);
        sort($handles);
        $handlesAgain = array_map(static fn(array $r): string => (string) ($r['handle'] ?? ''), $again);
        sort($handlesAgain);
        $kit->check('handles are stable between calls', $handles === $handlesAgain);

        // ---- (B) end exactly one device --------------------------------------
        $phoneHandle = '';
        foreach ($rows as $row) {
            if (($row['label'] ?? '') === 'Điện thoại') {
                $phoneHandle = (string) $row['handle'];
            }
        }
        $kit->check('the phone row has a handle to act on', $phoneHandle !== '');
        $killed = $call((string) $desktop['token'], $login, 'DELETE', '/aba/v1/me/devices/' . $phoneHandle);
        $kit->checkSame('DELETE /me/devices/{handle} => 200', 200, $killed->get_status());
        $kit->checkSame('   it reports one device ended', 1, (int) ($killed->get_data()['revoked'] ?? 0));
        $kit->checkSame('   the phone is signed out', 401, $call((string) $phone['token'], $login)->get_status());
        $kit->checkSame('   the tablet never noticed', 200, $call((string) $tablet['token'], $login)->get_status());
        $kit->checkSame('   two devices remain', 2, $countOurs($userId));

        $repeat = $call((string) $desktop['token'], $login, 'DELETE', '/aba/v1/me/devices/' . $phoneHandle);
        $kit->checkSame('ending it twice => 404, not a silent success', 404, $repeat->get_status());
        $kit->checkSame('   and the error names the device', 'ABA_DEVICE_NOT_FOUND', (string) (($repeat->get_data()['error']['code'] ?? null) ?? ($repeat->get_data()['code'] ?? '')));

        // ---- (C) sign out everywhere else ------------------------------------
        $others = $call((string) $desktop['token'], $login, 'DELETE', '/aba/v1/me/devices');
        $kit->checkSame('DELETE /me/devices => 200', 200, $others->get_status());
        $kit->checkSame('   it ended the tablet only', 1, (int) ($others->get_data()['revoked'] ?? 0));
        $kit->checkSame('   the tablet is signed out', 401, $call((string) $tablet['token'], $login)->get_status());
        $kit->checkSame('   the desktop keeps working', 200, $call((string) $desktop['token'], $login)->get_status());
        $kit->checkSame('   one device remains', 1, $countOurs($userId));

        // ---- (D) anonymous callers -------------------------------------------
        $anon = $call(null, $login, 'GET', '/aba/v1/me/devices');
        $kit->checkSame('an anonymous GET /me/devices => 401', 401, $anon->get_status());

        // ---- (E) another account's handle is not reachable from here ---------
        $other = $kit->makeStudent('4_6');
        $extra[] = $other;
        $otherUser = $kit->makeUser($other, 'Aba-Khac!2026');
        $extraUsers[] = $otherUser;
        $signIn((string) get_user_by('id', $otherUser)->user_login, 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Mobile Safari/537.36', 'Aba-Khac!2026');
        $otherDevices = $service->devices($otherUser);
        $foreignHandle = (string) ($otherDevices[0]['handle'] ?? '');
        $kit->check('the other account has a device of its own', $foreignHandle !== '');
        $foreign = $call((string) $desktop['token'], $login, 'DELETE', '/aba/v1/me/devices/' . $foreignHandle);
        $kit->checkSame('deleting another account\'s device => 404', 404, $foreign->get_status());
        $kit->checkSame('   that account stays signed in', 1, $countOurs($otherUser));

        // ---- (F) a cookie session sees the same list, marking nothing ---------
        $newPhone = $signIn($login, 'Mozilla/5.0 (Linux; Android 13; SM-A536) AppleWebKit/537.36 Mobile Safari/537.36');
        $kit->checkSame('a second device signed in again', 2, $countOurs($userId));

        wp_set_current_user($userId);
        $cookieList = rest_do_request(new WP_REST_Request('GET', '/aba/v1/me/devices'));
        $cookieRows = (array) ($cookieList->get_data()['devices'] ?? []);
        $kit->checkSame('a cookie session lists the same devices => 200', 200, $cookieList->get_status());
        $kit->checkSame('   both rows are there', 2, count($cookieRows));
        $kit->check(
            '   and none is marked as "this device" (no token was presented)',
            count(array_filter($cookieRows, static fn(array $r): bool => ($r['current'] ?? false) === true)) === 0
        );
        $cookieOut = rest_do_request(new WP_REST_Request('DELETE', '/aba/v1/me/devices'));
        $kit->checkSame('a cookie "sign out others" => 200', 200, $cookieOut->get_status());
        $kit->checkSame('   with no token presented it ends every device', 2, (int) ($cookieOut->get_data()['revoked'] ?? 0));
        $kit->checkSame('   no device token survives', 0, $countOurs($userId));
        $kit->checkSame('   the new phone is signed out', 401, $call((string) $newPhone['token'], $login)->get_status());
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
