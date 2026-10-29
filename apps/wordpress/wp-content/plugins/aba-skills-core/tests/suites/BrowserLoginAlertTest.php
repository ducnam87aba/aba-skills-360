<?php
/**
 * Suite: a browser sign-in announces itself too (PA59, AGENTS.md §8 + §12).
 *
 * PA57 announces the app token the headless client mints. A COOKIE sign-in mints
 * no token, so before PA59 the one path a leaked handout password would be used
 * on — a browser — left no trace a family could see. This suite proves:
 *   - the headless path still writes exactly ONE device alert and NO session
 *     alert, so the two announcements never double up;
 *   - the cookie path (app passwords unavailable) writes exactly one session
 *     alert, with the device label as the only payload;
 *   - a wp_signon()/wp-login.php sign-in writes one too, from the same hook;
 *   - a failed sign-in writes nothing, and /auth/refresh is not a sign-in;
 *   - an account with no learner record (staff) writes nothing;
 *   - the parent's feed and the learner's own history both carry the sign-in,
 *     and only a linked parent can read the feed.
 *
 * NOTE ON THE GUARD: CredentialSessionService::recordBrowserLogin() writes at
 * most once per user per REQUEST. In production each request is a process; in
 * this CLI harness the process outlives a "request", so each simulated sign-in
 * below uses its own learner, and the guard is asserted directly instead.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\CredentialSessionService;
use ABA\Core\Repositories\EventRepository;

return function (AbaTestKit $kit): void {
    $kit->section('BrowserLoginAlertTest — a browser sign-in announces itself (PA59)');

    global $wpdb;
    $eventsTable = $wpdb->prefix . 'aba_events';

    $applePhone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
    $windowsPc  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';

    $withAgent = static function (string $agent, callable $fn) {
        $previous = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = $agent;
        try {
            return $fn();
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $previous;
            }
        }
    };
    $postLogin = static function (string $login, string $password): WP_REST_Request {
        $req = new WP_REST_Request('POST', '/aba/v1/auth/login');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode(['username' => $login, 'password' => $password]));
        return $req;
    };
    $countFor = static function (string $event, ?int $sid = null) use ($wpdb, $eventsTable): int {
        if ($sid === null) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$eventsTable} WHERE event_name = %s",
                $event
            ));
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE student_id = %d AND event_name = %s",
            $sid, $event
        ));
    };
    $opened = static fn(int $sid): int => $countFor(EventRepository::CREDENTIAL_SESSION_OPENED, $sid);
    $lastRow = static function (int $sid) use ($wpdb, $eventsTable): array {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, payload_json FROM {$eventsTable}
             WHERE student_id = %d AND event_name = %s
             ORDER BY id DESC LIMIT 1",
            $sid, EventRepository::CREDENTIAL_SESSION_OPENED
        ));
        return [
            'id' => (int) ($row->id ?? 0),
            'userId' => (int) ($row->user_id ?? 0),
            'payload' => json_decode((string) ($row->payload_json ?? ''), true) ?: [],
            'raw' => (string) ($row->payload_json ?? ''),
        ];
    };
    /** The feed items whose id says they are a browser session (not an app token). */
    $sessionItems = static function (int $sid): array {
        $resp = rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"));
        return array_values(array_filter(
            (array) ($resp->get_data()['items'] ?? []),
            static fn(array $i): bool => strpos((string) ($i['id'] ?? ''), 'session:') === 0
        ));
    };

    // App passwords are available by default; this filter turns the headless
    // branch off so /auth/login falls back to the cookie — the path under test.
    $noAppPasswords = static fn() => false;

    $sid = 0; $userId = 0; $sid2 = 0; $userId2 = 0; $parent = 0; $staff = 0;

    try {
        $sid = $kit->makeStudent('4_6');
        $userId = $kit->makeUser($sid, 'Aba-TrinhDuyet!2026');
        $login = (string) get_userdata($userId)->user_login;

        $sid2 = $kit->makeStudent('4_6');
        $userId2 = $kit->makeUser($sid2, 'Aba-TrinhDuyet!2027');
        $login2 = (string) get_userdata($userId2)->user_login;

        $parent = $kit->makeParent('PH Trinh Duyet');
        $kit->linkParentStudent($parent, $sid);
        $kit->linkParentStudent($parent, $sid2);

        wp_set_current_user($parent);
        $kit->checkSame('no browser sign-in is announced yet', 0, count($sessionItems($sid)));

        // ---- (A) the headless path is PA57's, and stays PA57's --------------
        $reset = static function (): void {
            wp_set_current_user(0);
            $_COOKIE = [];
        };
        $reset();
        $phone = $withAgent($applePhone, static fn() => rest_do_request($postLogin($login, 'Aba-TrinhDuyet!2026')));
        $kit->checkSame('a headless sign-in succeeds', 200, $phone->get_status());
        $kit->check('   and mints an app token', is_string($phone->get_data()['token'] ?? null));
        $kit->checkSame('   it is announced as a device (PA57)', 1, $countFor(EventRepository::CREDENTIAL_DEVICE_ADDED, $sid));
        $kit->checkSame('   and NOT as a browser session', 0, $opened($sid));

        // ---- (B) the cookie path announces the sign-in ----------------------
        // `@` on the calls that mint a cookie: core's setcookie() cannot send a
        // header once the harness has printed, and the resulting notice says
        // nothing about the product. Exceptions still propagate, and every check
        // below still asserts.
        add_filter('wp_is_application_passwords_available', $noAppPasswords);
        $reset();
        $cookie = $withAgent($applePhone, static fn() => @rest_do_request($postLogin($login, 'Aba-TrinhDuyet!2026')));
        $kit->checkSame('a sign-in without app passwords still succeeds', 200, $cookie->get_status());
        $kit->check('   it hands back no token', array_key_exists('token', (array) $cookie->get_data()) && $cookie->get_data()['token'] === null);
        $kit->checkSame('   exactly one browser sign-in is recorded', 1, $opened($sid));

        $row = $lastRow($sid);
        $kit->checkSame('   the actor is the learner who signed in', $userId, $row['userId']);
        $keys = array_keys($row['payload']);
        sort($keys); // payload key ORDER is an implementation detail (§8 cares about the set)
        $kit->checkSame('   the payload is the door and the device label and nothing else', ['door', 'label'], $keys);
        $kit->checkSame('   and the door says this was a browser sign-in', 'cookie', (string) ($row['payload']['door'] ?? ''));
        $kit->checkSame('   the label is the same word the device list uses', 'Điện thoại', (string) ($row['payload']['label'] ?? ''));
        $kit->check('   no token, uuid or address reaches the ledger', strpos($row['raw'], '192.0.2.') === false && strpos($row['raw'], 'iPhone') === false);

        wp_set_current_user($parent);
        $items = $sessionItems($sid);
        $kit->checkSame('   the parent feed carries it', 1, count($items));
        $kit->checkSame('   worded as a browser sign-in, named by device',
            'Đăng nhập bằng trình duyệt: Điện thoại', (string) ($items[0]['title'] ?? ''));
        $kit->check('   it reads as a sign-in (type device), so the panel link fits',
            ($items[0]['type'] ?? '') === 'device');
        $kit->check('   a sign-in earns no XP and carries no score', $items[0]['xpEarned'] === null && $items[0]['score'] === null);
        $kit->check('   it has a real timestamp', preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($items[0]['occurredAt'] ?? '')) === 1);

        // ---- (C) the per-request guard: one sign-in, one row ----------------
        $again = CredentialSessionService::recordBrowserLogin($userId, 'Điện thoại');
        $kit->checkSame('a second announcement in the same request is a no-op', 0, $again);
        $kit->checkSame('   and writes no second row', 1, $opened($sid));

        // ---- (D) a failed sign-in and a refresh are not sign-ins ------------
        $reset();
        $bad = $withAgent($applePhone, static fn() => rest_do_request($postLogin($login, 'sai-mat-khau-ba-lan')));
        $kit->checkSame('a wrong password is still a 401', 401, $bad->get_status());
        $kit->checkSame('   and announces nothing', 1, $opened($sid));

        wp_set_current_user($userId);
        $refreshed = @rest_do_request(new WP_REST_Request('POST', '/aba/v1/auth/refresh'));
        $kit->checkSame('refreshing the cookie succeeds', 200, $refreshed->get_status());
        $kit->checkSame('   but a refresh is not a new sign-in', 1, $opened($sid));

        // ---- (E) wp-login.php goes through the same hook --------------------
        $reset();
        // `@` silences only the "headers already sent" warnings core's setcookie()
        // raises in a CLI process; the call still returns a WP_User or WP_Error.
        $signed = $withAgent($windowsPc, static fn() => @wp_signon([
            'user_login'    => $login2,
            'user_password' => 'Aba-TrinhDuyet!2027',
            'remember'      => false,
        ]));
        $kit->check('a wp_signon() sign-in succeeds', $signed instanceof WP_User);
        $kit->checkSame('   it is announced from the wp_login hook', 1, $opened($sid2));
        $kit->checkSame('   with the device read off the client', 'Máy tính', (string) ($lastRow($sid2)['payload']['label'] ?? ''));

        $history = (new \ABA\Core\Services\CredentialAuditService())->listFor($sid2, $userId2);
        $labels = array_column($history, 'actionLabel');
        $kit->check('   the learner history names the sign-in', in_array('Đăng nhập bằng trình duyệt', $labels, true));

        wp_set_current_user($parent);
        $kit->checkSame('   the second child\'s feed carries it', 1, count($sessionItems($sid2)));

        // ---- (F) an account with no learner record writes nothing -----------
        $staff = $kit->makeStaff(1);
        wp_set_password('NhanVien!2026', $staff);
        $staffLogin = (string) get_userdata($staff)->user_login;
        $beforeAll = $countFor(EventRepository::CREDENTIAL_SESSION_OPENED);
        $reset();
        $asStaff = $withAgent($windowsPc, static fn() => @rest_do_request($postLogin($staffLogin, 'NhanVien!2026')));
        $kit->checkSame('a staff account may sign in', 200, $asStaff->get_status());
        $kit->checkSame('   but no family ledger is written', $beforeAll, $countFor(EventRepository::CREDENTIAL_SESSION_OPENED));

        // ---- (G) scope: only a linked parent reads any of it ----------------
        $stranger = $kit->makeParent('PH Khong Lien Ket');
        wp_set_current_user($stranger);
        $kit->checkSame('an unlinked parent cannot read the feed => 403', 403,
            rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"))->get_status());
        $kit->cleanupUser($stranger);
        wp_set_current_user(0);
        $kit->checkSame('and an anonymous caller is refused => 401', 401,
            rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"))->get_status());
    } finally {
        remove_filter('wp_is_application_passwords_available', $noAppPasswords);
        wp_set_current_user(0);
        $_COOKIE = [];
        foreach ([$sid, $sid2] as $cleanupSid) {
            if ($cleanupSid > 0) {
                $uid = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->prefix}aba_students WHERE id = %d", $cleanupSid
                ));
                $kit->cleanup($cleanupSid); // also purges this student's aba_events rows
                if ($uid > 0) {
                    delete_user_meta($uid, 'aba_must_change_password');
                }
            }
        }
        foreach ([$userId, $userId2, $parent, $staff] as $cleanupUid) {
            if ($cleanupUid > 0) {
                $kit->cleanupUser($cleanupUid);
            }
        }
    }
};
