<?php
/**
 * Suite: sign-in throttling (PA53, AGENTS.md §8).
 *
 * PA19 counted failures inside AuthController::login(), so it guarded exactly
 * one door: an attacker could burn five guesses at /aba/v1/auth/login, get
 * locked there, and keep guessing at /wp-login.php with no penalty. PA53 moves
 * the counter onto the `authenticate` filter and keys it on the credential plus
 * the client address. This suite proves:
 *   - the fifth failure at the REST door answers 429 (not a 401 that leaks
 *     nothing but also blocks nothing), states its retryAfter, and refuses even
 *     the CORRECT password until the lock expires;
 *   - ONE ledger of attempts: failures at /auth/login lock the direct
 *     wp_authenticate() path (wp-login.php / XML-RPC) and vice versa;
 *   - the lock is scoped to the address, so a stranger cannot freeze a learner
 *     out of their own account — the exact denial-of-service PA19 allowed;
 *   - locks escalate (15 → 30 → 60 min, capped) while failures continue;
 *   - one address spraying many usernames trips the higher source bucket, and
 *     that lock blocks every account from that address but no other address;
 *   - a lock writes one non-secret ledger row (scope, wait, count) that shows
 *     up in the learner's credential history, and the audit trail never learns
 *     who was probed with what;
 *   - empty input is validation, not a guess, and never touches a counter.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\LoginThrottleService;
use ABA\Core\Services\CredentialAuditService;
use ABA\Core\Repositories\EventRepository;

return function (AbaTestKit $kit): void {
    $kit->section('LoginThrottleTest — one attempt ledger on every sign-in door (PA53)');

    global $wpdb;
    $eventsTable = $wpdb->prefix . 'aba_events';
    $service     = new LoginThrottleService();

    // Every phase chooses its own address so buckets never bleed into each
    // other (and into the suites that run before/after this one).
    $ip = '203.0.113.10';
    $ipFilter = static function () use (&$ip) {
        return $ip;
    };
    add_filter('aba_login_throttle_ip', $ipFilter);

    $wipe = static function () use ($wpdb): void {
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aba_thr_%'"
            . " OR option_name LIKE '_transient_timeout_aba_thr_%'"
        );
    };
    $wipe();

    $postLogin = static function (string $username, string $password): WP_REST_Request {
        $req = new WP_REST_Request('POST', '/aba/v1/auth/login');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode(['username' => $username, 'password' => $password]));
        return $req;
    };
    /** A password attempt made anywhere but the REST route (wp-login.php path). */
    $direct = static function (string $username, string $password) {
        return wp_authenticate($username, $password);
    };
    /** Drop any cookie auth so a request is judged on its credentials alone. */
    $reset = static function (): void {
        wp_set_current_user(0);
        $_COOKIE = [];
    };
    $countThrottled = static function (int $sid) use ($wpdb, $eventsTable): int {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE student_id = %d AND event_name = %s",
            $sid, EventRepository::LOGIN_THROTTLED
        ));
    };
    /**
     * Read the error code / details whichever envelope comes back: dispatch
     * hands over WordPress' raw shape, ErrorNormalizer the contract shape.
     */
    $codeOf = static function (WP_REST_Response $resp): string {
        $data = (array) $resp->get_data();
        return (string) ($data['code'] ?? ($data['error']['code'] ?? ''));
    };
    $detailsOf = static function (WP_REST_Response $resp): array {
        $data = (array) $resp->get_data();
        return (array) ($data['data']['details'] ?? ($data['error']['details'] ?? []));
    };

    $created = [];
    $createdUsers = [];

    try {
        // Three throwaway learners: one per behaviour, so a cleanup never has to
        // reason about a counter another phase left behind.
        $fixture = static function (string $password) use ($kit, &$created, &$createdUsers): array {
            $sid = $kit->makeStudent('4_6');
            $userId = $kit->makeUser($sid, $password);
            $created[] = $sid;
            $createdUsers[] = $userId;
            return ['sid' => $sid, 'userId' => $userId, 'login' => (string) get_userdata($userId)->user_login];
        };

        $a = $fixture('MatKhauDungA123');
        $b = $fixture('MatKhauDungB123');
        $c = $fixture('MatKhauDungC123');

        // ---- (A) the REST door answers 429 at the cap, and not one sooner -----
        $reset();
        $statuses = [];
        for ($i = 1; $i <= LoginThrottleService::ACCOUNT_MAX_FAILURES - 1; $i++) {
            $resp = rest_do_request($postLogin($a['login'], 'sai-mat-khau-' . $i));
            $statuses[] = $resp->get_status();
        }
        $kit->checkSame('the four attempts below the cap are refusals', [401, 401, 401, 401], $statuses);
        $resp = rest_do_request($postLogin($a['login'], 'sai-mat-khau-cuoi'));
        $kit->checkSame('the fifth failure answers 429 at once', 429, $resp->get_status());
        $kit->checkSame('   with the documented code', 'ACCOUNT_LOCKED', $codeOf($resp));
        $retryAfter = (int) ($detailsOf($resp)['retryAfter'] ?? 0);
        $kit->check('   and states how long the wait is', $retryAfter >= 800 && $retryAfter <= LoginThrottleService::BASE_LOCK_SECONDS);

        // A correct password is refused too — otherwise a lock only punishes typos.
        $resp = rest_do_request($postLogin($a['login'], 'MatKhauDungA123'));
        $kit->checkSame('the CORRECT password is refused while locked', 429, $resp->get_status());

        // Reporting cannot say whether the account exists (§8): an unknown name
        // gets the same refusal CODE as a real account with a wrong password.
        $resp = rest_do_request($postLogin('khong-ton-tai-' . wp_generate_password(6, false), 'x'));
        $kit->checkSame('an unknown account answers exactly like a wrong password', 401, $resp->get_status());
        $kit->checkSame('   with the same code', 'INVALID_CREDENTIALS', $codeOf($resp));

        // ---- (B) ONE ledger: the REST door and wp_authenticate() share it -----
        $reset();
        $last = null;
        for ($i = 1; $i <= LoginThrottleService::ACCOUNT_MAX_FAILURES; $i++) {
            $last = $direct($b['login'], 'doan-sai-' . $i);
        }
        $kit->checkSame('five failures through wp_authenticate() trip the same counter', LoginThrottleService::ERROR_CODE, $last instanceof WP_Error ? $last->get_error_code() : 'authenticated');
        $resp = rest_do_request($postLogin($b['login'], 'MatKhauDungB123'));
        $kit->checkSame('   and the REST door now refuses the correct password', 429, $resp->get_status());

        // The lock is on the ledger, carrying facts and no secret.
        $kit->checkSame('the lock is recorded once on the credential trail', 1, $countThrottled($b['sid']));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, payload_json FROM {$eventsTable} WHERE student_id = %d AND event_name = %s ORDER BY id DESC LIMIT 1",
            $b['sid'], EventRepository::LOGIN_THROTTLED
        ));
        $payload = json_decode((string) ($row->payload_json ?? ''), true) ?: [];
        $kit->checkSame('   the event points at the credential', $b['userId'], (int) ($row->user_id ?? 0));
        $kit->checkSame('   it names the bucket that tripped', 'credential', (string) ($payload['scope'] ?? ''));
        $kit->checkSame('   it records how many failures it took', LoginThrottleService::ACCOUNT_MAX_FAILURES, (int) ($payload['failures'] ?? 0));
        $kit->check('   it records the wait, not the password', strpos((string) wp_json_encode($payload), 'doan-sai-1') === false);

        // The family-facing history explains the gap in service.
        $library = (new CredentialAuditService())->listFor($b['sid'], $b['userId']);
        $labels = array_column($library, 'actionLabel');
        $kit->check('the learner\'s history shows the lock', in_array('Tạm khoá do thử sai nhiều lần', $labels, true));

        // ---- (C) escalation while the failures keep coming --------------------
        $reset();
        $ip = '203.0.113.11';
        $key = $service->accountKey($c['login'], $ip);
        /** Waiting is measured in whole seconds, so allow a second of drift. */
        $near = static function (int $expected, int $actual): bool {
            return abs($expected - $actual) <= 5;
        };
        for ($i = 0; $i < LoginThrottleService::ACCOUNT_MAX_FAILURES; $i++) {
            $service->registerFailure($c['login'], $ip);
        }
        $kit->check('the first lock is the base wait', $near(LoginThrottleService::BASE_LOCK_SECONDS, (int) $service->lockedStatus($c['login'], $ip)['retryAfter']));

        /**
         * Pretend the lock has only just lapsed and a new burst begins: the old
         * counting window is over, the level survives (it forgives only after a
         * quiet window). Attempts made WHILE locked are never counted — the
         * guard refuses them before this point — so each burst starts at zero.
         */
        $expire = static function (int $level) use ($key, $service, $ip): void {
            set_transient($key, [
                'fails'  => 0,
                'window' => time() - LoginThrottleService::WINDOW_SECONDS - 1,
                'until'  => time() - 1,
                'level'  => $level,
            ], 3600);
            // This address also carries a shared source bucket; drain it so the
            // per-credential escalation is what the assertions actually read.
            delete_transient($service->ipKey($ip));
        };
        $expire(1);
        for ($i = 0; $i < LoginThrottleService::ACCOUNT_MAX_FAILURES; $i++) {
            $service->registerFailure($c['login'], $ip);
        }
        $kit->check('a repeat offender waits twice as long', $near(LoginThrottleService::BASE_LOCK_SECONDS * 2, (int) $service->lockedStatus($c['login'], $ip)['retryAfter']));

        $expire(2);
        for ($i = 0; $i < LoginThrottleService::ACCOUNT_MAX_FAILURES; $i++) {
            $service->registerFailure($c['login'], $ip);
        }
        $kit->check('the third lock reaches the cap', $near(LoginThrottleService::MAX_LOCK_SECONDS, (int) $service->lockedStatus($c['login'], $ip)['retryAfter']));

        $expire(9);
        for ($i = 0; $i < LoginThrottleService::ACCOUNT_MAX_FAILURES; $i++) {
            $service->registerFailure($c['login'], $ip);
        }
        $kit->check('escalation never exceeds the cap', $near(LoginThrottleService::MAX_LOCK_SECONDS, (int) $service->lockedStatus($c['login'], $ip)['retryAfter']));
        delete_transient($key);
        delete_transient($service->ipKey($ip));

        // ---- (D) the lock follows the ADDRESS, so nobody can freeze a learner -
        $reset();
        $ip = '203.0.113.30';
        for ($i = 1; $i <= LoginThrottleService::ACCOUNT_MAX_FAILURES; $i++) {
            rest_do_request($postLogin($a['login'], 'ke-tan-cong-' . $i));
        }
        $kit->checkSame('a stranger locks their own address out', 429, rest_do_request($postLogin($a['login'], 'x'))->get_status());
        $ip = '203.0.113.31';
        $resp = rest_do_request($postLogin($a['login'], 'MatKhauDungA123'));
        $kit->checkSame('   the learner signs in normally from elsewhere', 200, $resp->get_status());
        $kit->checkSame('   and the counter was cleared by that success', false, get_transient($service->accountKey($a['login'], '203.0.113.31')));

        // ---- (E) one address spraying many usernames trips the source bucket --
        $reset();
        $ip = '198.51.100.7';
        $last = null;
        for ($i = 1; $i <= LoginThrottleService::IP_MAX_FAILURES; $i++) {
            $last = $direct('ghost' . $i, 'MotMatKhauThu');
        }
        $kit->checkSame('spraying a password across names trips the source bucket', LoginThrottleService::ERROR_CODE, $last instanceof WP_Error ? $last->get_error_code() : 'authenticated');
        $resp = rest_do_request($postLogin($b['login'], 'MatKhauDungB123'));
        $kit->checkSame('   every account is refused from that address', 429, $resp->get_status());
        $ip = '198.51.100.8';
        $resp = rest_do_request($postLogin($b['login'], 'MatKhauDungB123'));
        $kit->checkSame('   no other address is affected', 200, $resp->get_status());

        // ---- (F) empty input is validation, never a counted guess -------------
        $reset();
        $ip = '203.0.113.40';
        $resp = rest_do_request($postLogin('', ''));
        $kit->checkSame('an empty request is still a 400', 400, $resp->get_status());
        $kit->checkSame('   and leaves no bucket behind', false, get_transient($service->accountKey($a['login'], $ip)));
        $kit->check('an empty wp_authenticate() is not counted either', is_wp_error($direct('', '')));
        $kit->checkSame('   no credential bucket was created', false, get_transient($service->accountKey('', $ip)));

        // Without a client address there is no source bucket to fill (the proxy
        // case): guesses are still counted per credential, just not per host.
        $ip = '';
        $before = get_transient($service->ipKey(''));
        for ($i = 1; $i <= 3; $i++) {
            $direct('ghost-no-ip-' . $i, 'x');
        }
        $kit->checkSame('an unknown address creates no source bucket', $before, get_transient($service->ipKey('')));
        $ip = '203.0.113.40';

        // ---- (G) the refusal also carries the standard header -----------------
        $raw = new WP_REST_Response([
            'code'    => 'ACCOUNT_LOCKED',
            'message' => LoginThrottleService::MESSAGE,
            'data'    => ['status' => 429, 'code' => 'ACCOUNT_LOCKED', 'details' => ['retryAfter' => 900]],
        ], 429);
        $normalized = \ABA\Core\REST\ErrorNormalizer::filter($raw, null, new WP_REST_Request('POST', '/aba/v1/auth/login'));
        $headers = $normalized->get_headers();
        $kit->checkSame('the client is told to back off via Retry-After', '900', (string) ($headers['Retry-After'] ?? ''));
        $kit->checkSame('   and the body keeps the contract shape', 'ACCOUNT_LOCKED', (string) ($normalized->get_data()['error']['code'] ?? ''));
    } finally {
        wp_set_current_user(0);
        remove_filter('aba_login_throttle_ip', $ipFilter);
        $_COOKIE = [];
        $wipe();
        // A sprayed username resolves to no account, so its one ledger row has
        // null ids and cannot be reached through either student's cleanup.
        $wpdb->query("DELETE FROM {$eventsTable} WHERE event_name = 'login_throttled' AND student_id IS NULL");
        foreach ($created as $sid) {
            $kit->cleanup($sid); // also purges this student's aba_events rows
        }
        foreach ($createdUsers as $uid) {
            $kit->cleanupUser($uid);
        }
    }
};
