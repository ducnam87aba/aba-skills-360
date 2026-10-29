<?php
/**
 * Suite: a family is told when somebody guesses the child's password
 * (PA58, AGENTS.md §8 + §12).
 *
 * PA53 already turns repeated failures into a lock and writes ONE non-secret
 * ledger row per lock engagement — but that row was only readable by staff, so
 * the loudest security signal the platform produces never reached the people who
 * can act on it. This suite proves the parent's activity feed now carries it:
 *   - ordinary typos announce nothing: the alert fires at the cap, not before;
 *   - one burst is ONE alert (attempts made while locked add no row);
 *   - the alert states how many tries and how long the wait is;
 *   - the row carries the bucket, the wait and the count — never the attempted
 *     password and never the client address (§8);
 *   - the source bucket (one address spraying many names) is worded as such,
 *     because it must not claim THIS child was the target;
 *   - only a linked parent can read the feed, and another child's feed stays
 *     empty;
 *   - the learner's own credential history keeps showing the same lock, so the
 *     two surfaces agree.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\LoginThrottleService;
use ABA\Core\Services\CredentialAuditService;
use ABA\Core\Repositories\EventRepository;

return function (AbaTestKit $kit): void {
    $kit->section('LoginAlertTest — a lock reaches the family (PA58)');

    global $wpdb;
    $eventsTable = $wpdb->prefix . 'aba_events';
    $service = new LoginThrottleService();

    // Each phase names its own address so buckets never bleed between phases
    // (or into the suites running before and after this one).
    $ip = '198.51.100.60';
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
    $direct = static function (string $username, string $password) {
        return wp_authenticate($username, $password);
    };
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
    /** The lock rows this child has, newest first, payload decoded + raw text. */
    $rows = static function (int $sid) use ($wpdb, $eventsTable): array {
        $found = $wpdb->get_results($wpdb->prepare(
            "SELECT id, payload_json FROM {$eventsTable}
             WHERE student_id = %d AND event_name = %s
             ORDER BY id DESC",
            $sid, EventRepository::LOGIN_THROTTLED
        )) ?: [];
        return array_map(static function ($row): array {
            return [
                'id' => (int) $row->id,
                'payload' => json_decode((string) $row->payload_json, true) ?: [],
                'raw' => (string) $row->payload_json,
            ];
        }, $found);
    };
    /** The `security` items of a child's feed as the signed-in parent sees them. */
    $securityItems = static function (int $sid): array {
        $resp = rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"));
        return array_values(array_filter(
            (array) ($resp->get_data()['items'] ?? []),
            static fn(array $i): bool => ($i['type'] ?? '') === 'security'
        ));
    };
    $titles = static fn(int $sid): array => array_map(
        static fn(array $i): string => (string) ($i['title'] ?? ''),
        $securityItems($sid)
    );

    $sid = 0; $userId = 0; $secondSid = 0; $parent = 0; $stranger = 0;

    try {
        $sid = $kit->makeStudent('4_6');
        $userId = $kit->makeUser($sid, 'Aba-CanhBao!2026');
        $login = (string) get_userdata($userId)->user_login;

        // A second child with no security history: nothing may leak across.
        $secondSid = $kit->makeStudent('4_6');

        $parent = $kit->makeParent('PH Canh Bao');
        $kit->linkParentStudent($parent, $sid);
        $stranger = $kit->makeParent('PH Khong Lien Ket');

        wp_set_current_user($parent);
        $kit->checkSame('no warning before anybody tries', 0, count($securityItems($sid)));

        // ---- (A) typos below the cap stay private ---------------------------
        $reset();
        $ip = '198.51.100.61';
        for ($i = 1; $i <= LoginThrottleService::ACCOUNT_MAX_FAILURES - 1; $i++) {
            rest_do_request($postLogin($login, 'go-nham-' . $i));
        }
        $kit->checkSame('four wrong passwords write no lock row', 0, $countThrottled($sid));
        wp_set_current_user($parent);
        $kit->checkSame('   and the family is not alarmed by a typo', 0, count($securityItems($sid)));

        // ---- (B) the fifth failure is one alert, stated in full -------------
        $reset();
        $resp = rest_do_request($postLogin($login, 'go-nham-cuoi'));
        $kit->checkSame('the fifth failure locks the credential', 429, $resp->get_status());
        $kit->checkSame('   exactly one row is written for the burst', 1, $countThrottled($sid));

        wp_set_current_user($parent);
        $items = $securityItems($sid);
        $kit->checkSame('   and the feed carries exactly one warning', 1, count($items));
        $kit->checkSame(
            '   the warning says how many tries and how long the wait is',
            'Nhiều lần đăng nhập sai vào tài khoản của con (5 lần sai, tạm khoá 15 phút)',
            (string) ($items[0]['title'] ?? '')
        );
        $kit->check('   it has a stable id for the list key', preg_match('/^security:\d+$/', (string) ($items[0]['id'] ?? '')) === 1);
        $kit->check('   a warning earns no XP and carries no score', $items[0]['xpEarned'] === null && $items[0]['score'] === null);
        $kit->check('   it has a real timestamp', preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($items[0]['occurredAt'] ?? '')) === 1);

        // ---- (C) a burst stays ONE alert while the lock holds ----------------
        $reset();
        for ($i = 1; $i <= 3; $i++) {
            rest_do_request($postLogin($login, 'thu-tiep-' . $i));
        }
        $kit->checkSame('attempts made while locked add no second row', 1, $countThrottled($sid));
        wp_set_current_user($parent);
        $kit->checkSame('   so the feed still shows one warning', 1, count($securityItems($sid)));

        // ---- (D) the row holds facts, never a secret -------------------------
        $row = $rows($sid)[0];
        // Key ORDER is an implementation detail; the SET of keys is the promise.
        $keys = array_keys($row['payload']);
        sort($keys);
        $kit->checkSame('the row records the bucket, the wait and the count', ['failures', 'retryAfter', 'scope'], $keys);
        $kit->checkSame('   the bucket that tripped is the credential', 'credential', (string) ($row['payload']['scope'] ?? ''));
        $kit->checkSame('   the count is the number of tries', LoginThrottleService::ACCOUNT_MAX_FAILURES, (int) ($row['payload']['failures'] ?? 0));
        $kit->checkSame('   the wait is in seconds', LoginThrottleService::BASE_LOCK_SECONDS, (int) ($row['payload']['retryAfter'] ?? 0));
        $kit->check('   none of the attempted passwords is stored', strpos($row['raw'], 'go-nham-1') === false);
        $kit->check('   nor the client address', strpos($row['raw'], '198.51.100.61') === false);
        $kit->check('   nor does the feed leak the address', strpos((string) wp_json_encode($securityItems($sid)), '198.51.100.61') === false);

        // ---- (E) spraying from one address is worded honestly ----------------
        // Nineteen unknown names, then THIS child's login as the twentieth: the
        // source bucket trips, and the row lands on the child it happened to hit.
        $reset();
        $ip = '198.51.100.62';
        for ($i = 1; $i <= LoginThrottleService::IP_MAX_FAILURES - 1; $i++) {
            $direct('bong' . $i, 'MotMatKhauDuyNhat');
        }
        $last = $direct($login, 'MotMatKhauDuyNhat');
        $kit->checkSame('the twentieth failure from one address trips the source bucket', LoginThrottleService::ERROR_CODE, $last instanceof WP_Error ? $last->get_error_code() : 'authenticated');
        $kit->checkSame('   a second row is written for that engagement', 2, $countThrottled($sid));
        $kit->checkSame('   it names the source bucket', 'source', (string) ($rows($sid)[0]['payload']['scope'] ?? ''));

        wp_set_current_user($parent);
        $sources = $titles($sid);
        $kit->checkSame(
            '   the feed does not claim this child was the target',
            'Phát hiện dò mật khẩu từ cùng một nơi (20 lần sai, tạm khoá 15 phút)',
            (string) ($sources[0] ?? '')
        );
        $kit->check('   the credential warning is still there', in_array('Nhiều lần đăng nhập sai vào tài khoản của con (5 lần sai, tạm khoá 15 phút)', $sources, true));

        // ---- (F) scope: only a linked parent sees any of it ------------------
        wp_set_current_user($stranger);
        $kit->checkSame('an unlinked parent cannot read the feed => 403', 403,
            rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"))->get_status());
        wp_set_current_user($parent);
        $kit->checkSame('another child\'s feed carries no warning', 0, count($securityItems($secondSid)));
        wp_set_current_user(0);
        $kit->checkSame('and an anonymous caller is refused => 401', 401,
            rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"))->get_status());

        // ---- (G) the two surfaces agree --------------------------------------
        wp_set_current_user($userId);
        $history = (new CredentialAuditService())->listFor($sid, $userId);
        $lockLabels = array_keys(array_column($history, 'actionLabel'), 'Tạm khoá do thử sai nhiều lần', true);
        $kit->checkSame('the learner\'s history shows the same two locks', 2, count($lockLabels));
        wp_set_current_user($parent);
        $kit->checkSame('   and the feed shows two warnings as well', 2, count($securityItems($sid)));
    } finally {
        wp_set_current_user(0);
        remove_filter('aba_login_throttle_ip', $ipFilter);
        $_COOKIE = [];
        $wipe();
        // A sprayed username resolves to no account, so its row has null ids and
        // cannot be reached through either student's cleanup.
        $wpdb->query("DELETE FROM {$eventsTable} WHERE event_name = 'login_throttled' AND student_id IS NULL");
        foreach ([$sid, $secondSid] as $cleanupSid) {
            if ($cleanupSid > 0) {
                $kit->cleanup($cleanupSid); // also purges this student's aba_events rows
            }
        }
        foreach ([$userId, $parent, $stranger] as $cleanupUid) {
            if ($cleanupUid > 0) {
                $kit->cleanupUser($cleanupUid);
            }
        }
    }
};
