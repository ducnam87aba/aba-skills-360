<?php
/**
 * Suite: a new device announces itself (PA57, AGENTS.md §8 + §12).
 *
 * PA55/PA56 let a family SEE which devices hold a child's account, but only if
 * somebody remembers to open the screen — a device that appeared overnight
 * stayed invisible until then. This suite proves the account now tells them:
 *   - a successful sign-in writes exactly ONE `credential_device_added` event,
 *     with the learner as the actor and the device label as the only payload —
 *     no token, uuid or handle ever reaches the ledger (§8);
 *   - a second device writes a second event, so "more than one" is visible;
 *   - a FAILED sign-in writes nothing: the alert must mean access was granted;
 *   - the parent's activity feed carries it as a `device` item, and it is the
 *     one entry that is about the account rather than about learning;
 *   - the learner's own login history carries it too, so the child can see it;
 *   - a sign-out does NOT erase it — the ledger is append-only (§12);
 *   - an account with no learner record (a parent signing in) writes nothing,
 *     because there is no family ledger to write to.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\CredentialSessionService;
use ABA\Core\Repositories\EventRepository;

return function (AbaTestKit $kit): void {
    $kit->section('DeviceAlertTest — a new device announces itself (PA57)');

    global $wpdb;
    $studentsTable = $wpdb->prefix . 'aba_students';
    $eventsTable = $wpdb->prefix . 'aba_events';

    if (!class_exists('WP_Application_Passwords')) {
        require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
    }

    /** Drive a real sign-in: the UA names the device unless a header states it. */
    $signIn = static function (string $login, string $password, string $agent = '', ?string $device = null): array {
        $previous = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($agent !== '') {
            $_SERVER['HTTP_USER_AGENT'] = $agent;
        }
        $req = new WP_REST_Request('POST', '/aba/v1/auth/login');
        $req->set_header('Content-Type', 'application/json');
        if ($device !== null) {
            $req->set_header('X-ABA-Device', $device);
        }
        $req->set_body(wp_json_encode(['username' => $login, 'password' => $password]));
        $resp = rest_do_request($req);
        if ($previous === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $previous;
        }
        $data = (array) $resp->get_data();
        return ['status' => $resp->get_status(), 'token' => is_string($data['token'] ?? null) ? $data['token'] : null];
    };

    /** Every device alert for one learner, newest first, payload decoded. */
    $alerts = static function (int $studentId) use ($wpdb, $eventsTable): array {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, payload_json, created_at FROM {$eventsTable}
             WHERE student_id = %d AND event_name = %s
             ORDER BY id DESC",
            $studentId, EventRepository::CREDENTIAL_DEVICE_ADDED
        )) ?: [];
        return array_map(static function ($row): array {
            return [
                'id' => (int) $row->id,
                'userId' => (int) $row->user_id,
                'payload' => json_decode((string) $row->payload_json, true) ?: [],
                'raw' => (string) $row->payload_json,
            ];
        }, $rows);
    };
    $alertCount = static fn(int $studentId): int => count($alerts($studentId));

    /** Device alerts across the WHOLE table — proves a non-learner wrote nothing. */
    $allAlerts = static function () use ($wpdb, $eventsTable): int {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE event_name = %s",
            EventRepository::CREDENTIAL_DEVICE_ADDED
        ));
    };
    $feedTitles = static function (int $studentId, string $type): array {
        $resp = rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$studentId}/activities"));
        $items = (array) ($resp->get_data()['items'] ?? []);
        return array_values(array_map(
            static fn(array $i): string => (string) ($i['title'] ?? ''),
            array_filter($items, static fn(array $i): bool => ($i['type'] ?? '') === $type)
        ));
    };

    $sid = 0; $userId = 0; $parent = 0; $strangerParent = 0; $nonLearner = 0;

    try {
        $sid = $kit->makeStudent('4_6');
        $userId = $kit->makeUser($sid, 'Aba-ThongBao!2026');
        $login = (string) get_user_by('id', $userId)->user_login;

        $available = apply_filters('wp_is_application_passwords_available', true)
            && apply_filters('wp_is_application_passwords_available_for_user', true, $userId);
        if (!$available) {
            // Without a device token there is no device to announce; the cookie
            // fallback is covered by AuthTest.
            $kit->check('app passwords unavailable — device alerts not exercisable here', true);
            return;
        }

        // ---- (A) one sign-in, one alert, label only ---------------------------
        $kit->checkSame('nothing announced before anyone signs in', 0, $alertCount($sid));
        $phone = $signIn($login, 'Aba-ThongBao!2026', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1');
        $kit->checkSame('the sign-in succeeds', 200, $phone['status']);
        $kit->check('   a device token was handed out', $phone['token'] !== null);
        $kit->checkSame('   exactly one alert was written', 1, $alertCount($sid));

        $first = $alerts($sid)[0];
        $kit->checkSame('   it names the device', 'Điện thoại', (string) ($first['payload']['label'] ?? ''));
        $kit->checkSame('   the actor is the learner who signed in', $userId, $first['userId']);
        $kit->checkSame('   the payload carries the label and nothing else', ['label'], array_keys($first['payload']));
        $kit->check('   neither the token nor a uuid reaches the ledger', strpos($first['raw'], (string) $phone['token']) === false);

        // ---- (B) a second device is a second alert ----------------------------
        $tablet = $signIn($login, 'Aba-ThongBao!2026', 'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1');
        $kit->checkSame('a second device signs in', 200, $tablet['status']);
        $kit->checkSame('   and is announced separately', 2, $alertCount($sid));
        $kit->checkSame('   the newest alert is the tablet', 'Máy tính bảng', (string) ($alerts($sid)[0]['payload']['label'] ?? ''));

        // A client may name itself, which is what a native app will do.
        $named = $signIn($login, 'Aba-ThongBao!2026', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'Máy tính của Lan');
        $kit->checkSame('a client can name its own device', 200, $named['status']);
        $kit->checkSame('   the alert uses that name', 'Máy tính của Lan', (string) ($alerts($sid)[0]['payload']['label'] ?? ''));

        // ---- (C) a failed sign-in announces nothing --------------------------
        $before = $alertCount($sid);
        $bad = $signIn($login, 'Sai-Mat-Khau!2026', 'Mozilla/5.0 (Linux; Android 13; Pixel 7) Mobile Safari/537.36');
        $kit->checkSame('a wrong password => 401', 401, $bad['status']);
        $kit->check('   no token was handed out', $bad['token'] === null);
        $kit->checkSame('   and nothing was announced', $before, $alertCount($sid));

        // ---- (D) the parent's activity feed carries it -----------------------
        $parent = $kit->makeParent('PH Test');
        $kit->linkParentStudent($parent, $sid);
        wp_set_current_user($parent);
        $titles = $feedTitles($sid, 'device');
        $kit->checkSame('the parent feed lists every device alert', 3, count($titles));
        $kit->checkSame('   newest first, named the same way the device list names it',
            'Thiết bị mới đăng nhập: Máy tính của Lan', $titles[0] ?? '');
        $kit->check('   the phone alert is there too', in_array('Thiết bị mới đăng nhập: Điện thoại', $titles, true));

        $resp = rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"));
        $deviceItems = array_values(array_filter(
            (array) ($resp->get_data()['items'] ?? []),
            static fn(array $i): bool => ($i['type'] ?? '') === 'device'
        ));
        $kit->check('   each alert has a stable id for the list key', preg_match('/^device:\d+$/', (string) ($deviceItems[0]['id'] ?? '')) === 1);
        // Several sign-ins can share a second, so the feed is checked for a
        // non-increasing sequence rather than a strict one — a tie is correct.
        $times = array_map(static fn(array $i): string => (string) ($i['occurredAt'] ?? ''), $deviceItems);
        $descending = $times;
        rsort($descending);
        $kit->check('   and the feed stays newest-first across the whole list', $times === $descending);
        $kit->check('   an alert earns no XP and carries no score', $deviceItems[0]['xpEarned'] === null && $deviceItems[0]['score'] === null);
        $kit->check('   it has a real timestamp', preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($deviceItems[0]['occurredAt'] ?? '')) === 1);

        $strangerParent = $kit->makeParent('PH Khac');
        wp_set_current_user($strangerParent);
        $kit->checkSame('an unlinked parent sees no feed at all => 403', 403,
            rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"))->get_status());

        // ---- (E) the learner's own history carries it ------------------------
        wp_set_current_user($userId);
        $resp = rest_do_request(new WP_REST_Request('GET', "/aba/v1/students/{$sid}/credential-events"));
        $labels = array_column((array) ($resp->get_data()['events'] ?? []), 'actionLabel');
        $kit->check('the learner\'s login history shows the alert', in_array('Thiết bị mới đăng nhập', $labels, true));
        $kit->checkSame('   once per device that signed in', 3, count(array_keys($labels, 'Thiết bị mới đăng nhập', true)));

        // ---- (F) ending a device does not rewrite history --------------------
        $before = $alertCount($sid);
        (new CredentialSessionService())->revokeAppPasswords($userId);
        $kit->checkSame('signing devices out leaves the alerts in place', $before, $alertCount($sid));

        // ---- (G) an account with no learner record writes nothing ------------
        $nonLearner = $kit->makeParent('PH Khong Phai Hoc Sinh');
        wp_set_password('PhuHuynh!2026', $nonLearner);
        $beforeAll = $allAlerts();
        $theirLogin = (string) get_user_by('id', $nonLearner)->user_login;
        $theirSignIn = $signIn($theirLogin, 'PhuHuynh!2026', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $kit->checkSame('a parent may sign in', 200, $theirSignIn['status']);
        $kit->check('   and still gets a device token', $theirSignIn['token'] !== null);
        $kit->checkSame('   but no learner ledger is written', $beforeAll, $allAlerts());
    } finally {
        wp_set_current_user(0);
        if ($sid > 0) {
            $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$studentsTable} WHERE id = %d", $sid));
            $kit->cleanup($sid); // also purges this student's aba_events rows
            if ($uid > 0) {
                delete_user_meta($uid, 'aba_must_change_password');
                $kit->cleanupUser($uid);
            }
        }
        foreach ([$parent, $strangerParent, $nonLearner] as $uid2) {
            if ($uid2 > 0) { $kit->cleanupUser($uid2); }
        }
    }
};
