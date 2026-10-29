<?php
/**
 * Suite: Real authentication over the /aba/v1/auth/* stack (PA9).
 *
 * Proves the login endpoint validates credentials through wp_authenticate(),
 * never echoes the secret (§8 / invariant: no password leak), rejects bad input
 * with the reshaped error body, and honours a disabled account — all driven via
 * rest_do_request() so routing -> controller -> WP auth run for real, not MSW.
 *
 * @package ABA_Skills_Core
 */

return function (AbaTestKit $kit): void {
    $kit->section('AuthTest — real /auth/login over rest_do_request (PA9)');

    $studentId = $kit->makeStudent('4_6');
    $password  = 'Aba-Str0ng!2026';

    /** Send a JSON body the way a browser fetch() would (login reads get_json_params()). */
    $withJson = static function (WP_REST_Request $req, array $body): WP_REST_Request {
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };

    try {
        $userId = $kit->makeUser($studentId, $password);
        $login  = get_user_by('id', $userId)->user_login;

        // ---- Correct credentials => 200 + user DTO, no secret -------------
        $req  = $withJson(new WP_REST_Request('POST', '/aba/v1/auth/login'), [
            'username' => $login,
            'password' => $password,
        ]);
        $resp = rest_do_request($req);
        $kit->checkSame('POST /auth/login valid => 200', 200, $resp->get_status());
        $data = $resp->get_data();
        $kit->checkSame('response carries the authenticated user id', $userId, $data['user']['id'] ?? null);
        $kit->checkSame('DTO exposes the linked studentId', $studentId, $data['user']['studentId'] ?? null);
        $kit->check('DTO has no password field', !isset($data['user']['user_pass']) && !isset($data['user']['password']));
        $kit->check('raw body does not leak the secret', strpos(wp_json_encode($data), $password) === false);
        // PA54 — the sign-in's own device token, used by the lifecycle block below.
        $sessionToken = is_string($data['token'] ?? null) ? $data['token'] : null;

        // ---- Wrong password => 401, uniform error shape -------------------
        $req  = $withJson(new WP_REST_Request('POST', '/aba/v1/auth/login'), [
            'username' => $login,
            'password' => 'wrong-password',
        ]);
        $resp = rest_do_request($req);
        $kit->checkSame('POST /auth/login bad password => 401', 401, $resp->get_status());
        $err = $resp->get_data();
        $kit->checkSame('error reshaped to INVALID_CREDENTIALS', 'INVALID_CREDENTIALS', $err['error']['code'] ?? ($err['code'] ?? null));

        // ---- Missing fields => 400 INVALID_REQUEST ------------------------
        $req  = $withJson(new WP_REST_Request('POST', '/aba/v1/auth/login'), [
            'username' => $login,
        ]);
        $resp = rest_do_request($req);
        $kit->checkSame('POST /auth/login missing password => 400', 400, $resp->get_status());

        // ---- Nonce bootstrap for the cookie session (PA14) ---------------
        wp_set_current_user($userId);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/auth/nonce'));
        $kit->checkSame('GET /auth/nonce (logged in) => 200', 200, $resp->get_status());
        $nd = $resp->get_data();
        $kit->checkSame('nonce endpoint reports authenticated', true, (bool)($nd['authenticated'] ?? false));
        $kit->check('wp_rest nonce issued (10 chars)', strlen((string)($nd['nonce'] ?? '')) === 10);
        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/auth/nonce'));
        $anon = $resp->get_data();
        $kit->checkSame('anonymous nonce is empty', '', $anon['nonce'] ?? null);
        $kit->checkSame('anonymous reports not-authenticated', false, (bool)($anon['authenticated'] ?? true));

        // ---- Disabled account => 403 ACCOUNT_DISABLED ---------------------
        update_user_meta($userId, 'aba_account_status', 'disabled');
        $req  = $withJson(new WP_REST_Request('POST', '/aba/v1/auth/login'), [
            'username' => $login,
            'password' => $password,
        ]);
        $resp = rest_do_request($req);
        $kit->checkSame('disabled account => 403', 403, $resp->get_status());
        delete_user_meta($userId, 'aba_account_status');

        // ---- PA39/PA54: headless logout and its scope (§8 lifecycle) ----------
        // The line-35 sign-in issued a dedicated device token. PA54 narrowed
        // sign-out: a cookie session ends only itself, while a sign-out carrying
        // the token ends exactly that token — never the account's other devices.
        // Assertable only where app passwords are issuable in this environment;
        // elsewhere sign-out must still succeed cleanly via the cookie fallback.
        if (class_exists('WP_Application_Passwords')
            && apply_filters('wp_is_application_passwords_available', true)
            && apply_filters('wp_is_application_passwords_available_for_user', true, $userId)) {
            require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
            // Core only attempts app-password auth on an API request (REST_REQUEST),
            // which a CLI harness never defines.
            add_filter('application_password_is_api_request', '__return_true');
            $sessionApps = static function () use ($userId): int {
                $n = 0;
                foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $app) {
                    if (\ABA\Core\Services\CredentialSessionService::isOurApp((string) ($app['name'] ?? ''))) {
                        $n++;
                    }
                }
                return $n;
            };
            $kit->checkSame('login left exactly one dedicated app password', 1, $sessionApps());

            // A cookie session must survive its own sign-out without collateral.
            wp_set_current_user($userId);
            $lout = rest_do_request(new WP_REST_Request('POST', '/aba/v1/auth/logout'));
            $kit->checkSame('POST /auth/logout (cookie session) => 204', 204, $lout->get_status());
            $kit->checkSame('cookie sign-out leaves the device token alone', 1, $sessionApps());
            wp_set_current_user(0);

            // Signing out over the token is what ends that device.
            if (is_string($sessionToken) && $sessionToken !== '') {
                global $current_user;
                $current_user = null;
                $_SERVER['PHP_AUTH_USER'] = $login;
                $_SERVER['PHP_AUTH_PW']   = $sessionToken;
                $byToken = rest_do_request(new WP_REST_Request('POST', '/aba/v1/auth/logout'));
                unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
                $kit->checkSame('POST /auth/logout (over the token) => 204', 204, $byToken->get_status());
                $kit->checkSame('it revoked the device token it was given', 0, $sessionApps());
            }
            remove_filter('application_password_is_api_request', '__return_true');
            wp_set_current_user(0);
        } else {
            wp_set_current_user($userId);
            $lout = rest_do_request(new WP_REST_Request('POST', '/aba/v1/auth/logout'));
            $kit->checkSame('logout => 204 when app passwords are unavailable', 204, $lout->get_status());
            wp_set_current_user(0);
        }

        // ---- PA19/PA53: brute-force lockout (§8) ---------------------------
        // Isolated throwaway principal so the lock never touches the checks above.
        $lockStudent  = $kit->makeStudent('4_6');
        $lockPassword = 'Aba-L0ck!2026';
        $lockUser     = $kit->makeUser($lockStudent, $lockPassword);
        $lockLogin    = get_user_by('id', $lockUser)->user_login;
        // PA53 — the counter moved out of the controller into LoginThrottleService
        // (keyed by credential + address), so the key is asked for, not built.
        $throttle     = new \ABA\Core\Services\LoginThrottleService();
        $lockKey      = $throttle->accountKey($lockLogin, $throttle->clientAddress());
        delete_transient($lockKey);

        $lastStatus = 0;
        for ($i = 1; $i <= \ABA\Core\Services\LoginThrottleService::ACCOUNT_MAX_FAILURES; $i++) {
            $r = $withJson(new WP_REST_Request('POST', '/aba/v1/auth/login'), ['username' => $lockLogin, 'password' => 'nope' . $i]);
            $lastStatus = rest_do_request($r)->get_status();
            if ($i < \ABA\Core\Services\LoginThrottleService::ACCOUNT_MAX_FAILURES) {
                $kit->checkSame("failed attempt $i below the cap => 401", 401, $lastStatus);
            }
        }
        $kit->checkSame('reaching the failure cap locks the account (429)', 429, $lastStatus);

        // While locked even the correct password is refused (§8, gate before auth).
        $rl = rest_do_request($withJson(new WP_REST_Request('POST', '/aba/v1/auth/login'), ['username' => $lockLogin, 'password' => $lockPassword]));
        $kit->checkSame('correct password blocked while locked (429)', 429, $rl->get_status());
        $lockErr = $rl->get_data();
        $kit->checkSame('lock error code is ACCOUNT_LOCKED', 'ACCOUNT_LOCKED', $lockErr['error']['code'] ?? ($lockErr['code'] ?? null));

        // Once the window clears, sign-in works again and resets the counter.
        delete_transient($lockKey);
        $rok = rest_do_request($withJson(new WP_REST_Request('POST', '/aba/v1/auth/login'), ['username' => $lockLogin, 'password' => $lockPassword]));
        $kit->checkSame('after the window a correct login succeeds', 200, $rok->get_status());
        $kit->checkSame('success leaves the failure counter cleared', 0, (int) get_transient($lockKey));
    } finally {
        wp_set_current_user(0);
        if (!empty($login)) {
            delete_transient((new \ABA\Core\Services\LoginThrottleService())->accountKey($login, (new \ABA\Core\Services\LoginThrottleService())->clientAddress()));
        }
        if (!empty($lockKey)) {
            delete_transient($lockKey);
        }
        $kit->cleanupUser($lockUser ?? 0);
        $kit->cleanup($lockStudent ?? 0);
        $kit->cleanupUser($userId ?? 0);
        $kit->cleanup($studentId);
    }
};
