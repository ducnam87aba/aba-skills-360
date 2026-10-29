<?php
/**
 * Suite: an XML-RPC sign-in announces itself too (PA60, AGENTS.md §8 + §12).
 *
 * PA59 covers the doors that end in a cookie: wp-login.php (via `wp_login`) and
 * the REST fallback. XML-RPC is the third door that accepts the ACCOUNT password
 * — the one a script uses — and it fires no `wp_login`: core's server calls
 * wp_authenticate() for every authenticated call and has no success hook of its
 * own. So before PA60 a password replayed over XML-RPC left no trace at all.
 *
 * This suite proves:
 *   - a password check that is NOT XML-RPC announces nothing (wp-login owns it);
 *   - an XML-RPC check writes exactly one row, and never a PA57 device alert;
 *   - the payload is the door plus a device label — no agent, no address;
 *   - the feed says "ứng dụng khác", never "trình duyệt";
 *   - the hour window stops a per-call client from burying the learning feed,
 *     and lapses on its own;
 *   - a wrong password announces nothing, and one sign-in is one row even when
 *     two doors fire in the same request;
 *   - an account with no learner record (staff) writes nothing;
 *   - only a linked parent can read any of it.
 *
 * NOTE ON THE SEAM: `aba_is_xmlrpc_request` exists because core's XMLRPC_REQUEST
 * is a process constant that a test can set but never un-set — poisoning every
 * later suite. Production reads core's own constant by default; the filter only
 * lets this suite answer both ways. PA53 uses the same shape for the address.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\EventRepository;
use ABA\Core\Services\CredentialAuditService;
use ABA\Core\Services\CredentialSessionService;

return function (AbaTestKit $kit): void {
    $kit->section('XmlrpcLoginAlertTest — an XML-RPC sign-in announces itself (PA60)');

    global $wpdb;
    $eventsTable = $wpdb->prefix . 'aba_events';

    $windowsPc = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';

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
    /** The feed items whose id says they are an opened session (not an app token). */
    $sessionItems = static function (int $sid): array {
        $resp = rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"));
        return array_values(array_filter(
            (array) ($resp->get_data()['items'] ?? []),
            static fn(array $i): bool => strpos((string) ($i['id'] ?? ''), 'session:') === 0
        ));
    };
    $reset = static function (): void {
        wp_set_current_user(0);
        $_COOKIE = [];
    };

    $on  = static fn() => true;
    $off = static fn() => false;

    $sid = 0; $userId = 0;
    $sid2 = 0; $userId2 = 0;
    $sid3 = 0; $userId3 = 0;
    $parent = 0; $staff = 0;

    try {
        $sid = $kit->makeStudent('4_6');
        $userId = $kit->makeUser($sid, 'Aba-Xmlrpc!2026');
        $login = (string) get_userdata($userId)->user_login;

        $sid2 = $kit->makeStudent('4_6');
        $userId2 = $kit->makeUser($sid2, 'Aba-Xmlrpc!2027');
        $login2 = (string) get_userdata($userId2)->user_login;

        $sid3 = $kit->makeStudent('4_6');
        $userId3 = $kit->makeUser($sid3, 'Aba-Xmlrpc!2028');
        $login3 = (string) get_userdata($userId3)->user_login;

        $parent = $kit->makeParent('PH Xmlrpc');
        $kit->linkParentStudent($parent, $sid);

        // ---- (A) a password check that is not XML-RPC stays quiet -----------
        add_filter('aba_is_xmlrpc_request', $off);
        $reset();
        $probe = $withAgent($windowsPc, static fn() => wp_authenticate($login, 'Aba-Xmlrpc!2026'));
        $kit->check('a password check outside XML-RPC still succeeds', $probe instanceof WP_User);
        $kit->checkSame('   and announces nothing — wp-login owns that door', 0, $opened($sid));

        // ---- (B) the XML-RPC door announces itself --------------------------
        remove_filter('aba_is_xmlrpc_request', $off);
        add_filter('aba_is_xmlrpc_request', $on);
        $reset();
        $signed = $withAgent($windowsPc, static fn() => wp_authenticate($login, 'Aba-Xmlrpc!2026'));
        $kit->check('an XML-RPC password check succeeds', $signed instanceof WP_User);
        $kit->check('   the observer leaves the result untouched', $signed instanceof WP_User && (int) $signed->ID === $userId);
        $kit->checkSame('   exactly one sign-in is recorded', 1, $opened($sid));
        $kit->checkSame('   and it is not a PA57 device alert', 0, $countFor(EventRepository::CREDENTIAL_DEVICE_ADDED, $sid));

        $row = $lastRow($sid);
        $kit->checkSame('   the actor is the learner whose password was used', $userId, $row['userId']);
        $keys = array_keys($row['payload']);
        sort($keys); // payload key ORDER is an implementation detail (§8 cares about the set)
        $kit->checkSame('   the payload is the door and the device label and nothing else', ['door', 'label'], $keys);
        $kit->checkSame('   the door says it was not a browser', 'xmlrpc', (string) ($row['payload']['door'] ?? ''));
        $kit->checkSame('   the label is the word the device list would use', 'Máy tính', (string) ($row['payload']['label'] ?? ''));
        $kit->check('   no agent string or address reaches the ledger', strpos($row['raw'], 'Chrome') === false && strpos($row['raw'], '192.0.2.') === false);
        $kit->check('   the hour window is armed so a per-call client cannot flood the feed',
            get_transient(CredentialSessionService::XMLRPC_KEY_PREFIX . $userId) !== false);

        wp_set_current_user($parent);
        $items = $sessionItems($sid);
        $kit->checkSame('   the parent feed carries it', 1, count($items));
        $kit->checkSame('   worded as another app, not as a browser',
            'Đăng nhập qua ứng dụng khác: Máy tính', (string) ($items[0]['title'] ?? ''));
        $kit->check('   it still reads as a sign-in (type device), so the panel link fits',
            ($items[0]['type'] ?? '') === 'device');
        // array_key_exists, not `??`: `??` also swallows a null VALUE, so the
        // assertion would pass even if the key were missing entirely.
        $kit->check('   it earns no XP and carries no score',
            array_key_exists('xpEarned', $items[0]) && $items[0]['xpEarned'] === null
            && array_key_exists('score', $items[0]) && $items[0]['score'] === null);
        $kit->check('   it has a real timestamp',
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($items[0]['occurredAt'] ?? '')) === 1);

        $labels = array_column((new CredentialAuditService())->listFor($sid, $userId), 'actionLabel');
        $kit->check('   the learner history names the door too',
            in_array('Đăng nhập bằng mật khẩu (ứng dụng khác)', $labels, true));

        // ---- (C) the hour window --------------------------------------------
        // Pre-arming the window stands in for "announced a minute ago": the
        // per-REQUEST guard would mask the effect inside one CLI process, and
        // reusing $userId could not distinguish the two guards.
        set_transient(CredentialSessionService::XMLRPC_KEY_PREFIX . $userId3, 1, 3600);
        $reset();
        $quiet = $withAgent($windowsPc, static fn() => wp_authenticate($login3, 'Aba-Xmlrpc!2028'));
        $kit->check('a client inside the window still authenticates', $quiet instanceof WP_User);
        $kit->checkSame('   but the family is not told again', 0, $opened($sid3));

        delete_transient(CredentialSessionService::XMLRPC_KEY_PREFIX . $userId3);
        $reset();
        $later = $withAgent($windowsPc, static fn() => wp_authenticate($login3, 'Aba-Xmlrpc!2028'));
        $kit->check('once the window lapses the sign-in still works', $later instanceof WP_User);
        $kit->checkSame('   and the next one is announced again', 1, $opened($sid3));

        // ---- (D) a failed sign-in is not a sign-in --------------------------
        $reset();
        $bad = $withAgent($windowsPc, static fn() => wp_authenticate($login2, 'sai-mat-khau-xmlrpc'));
        $kit->check('a wrong password is still an error', is_wp_error($bad));
        $kit->checkSame('   and announces nothing', 0, $opened($sid2));

        // ---- (E) one sign-in is one row, even across doors ------------------
        $reset();
        $first = $withAgent($windowsPc, static fn() => wp_authenticate($login2, 'Aba-Xmlrpc!2027'));
        $kit->checkSame('an XML-RPC sign-in is announced once', 1, $opened($sid2));
        do_action('wp_login', $login2, $first); // as if a second door fired in the same request
        $kit->checkSame('   and a wp_login for the same request adds nothing', 1, $opened($sid2));

        // ---- (F) an account with no learner record writes nothing -----------
        $staff = $kit->makeStaff(1);
        wp_set_password('NhanVienXmlrpc!2026', $staff);
        $staffLogin = (string) get_userdata($staff)->user_login;
        $beforeAll = $countFor(EventRepository::CREDENTIAL_SESSION_OPENED);
        $reset();
        $asStaff = $withAgent($windowsPc, static fn() => wp_authenticate($staffLogin, 'NhanVienXmlrpc!2026'));
        $kit->check('a staff account may authenticate over XML-RPC', $asStaff instanceof WP_User);
        $kit->checkSame('   but no family ledger is written', $beforeAll, $countFor(EventRepository::CREDENTIAL_SESSION_OPENED));

        // ---- (G) scope: only a linked parent reads any of it ----------------
        $stranger = $kit->makeParent('PH Khong Lien Ket Xmlrpc');
        wp_set_current_user($stranger);
        $kit->checkSame('an unlinked parent cannot read the feed => 403', 403,
            rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"))->get_status());
        $kit->cleanupUser($stranger);
        wp_set_current_user(0);
        $kit->checkSame('and an anonymous caller is refused => 401', 401,
            rest_do_request(new WP_REST_Request('GET', "/aba/v1/parent/children/{$sid}/activities"))->get_status());
    } finally {
        remove_filter('aba_is_xmlrpc_request', $on);
        remove_filter('aba_is_xmlrpc_request', $off);
        wp_set_current_user(0);
        $_COOKIE = [];
        foreach ([$userId, $userId2, $userId3, $staff] as $uid) {
            if ($uid > 0) {
                delete_transient(CredentialSessionService::XMLRPC_KEY_PREFIX . $uid);
            }
        }
        foreach ([$sid, $sid2, $sid3] as $cleanupSid) {
            if ($cleanupSid > 0) {
                $kit->cleanup($cleanupSid); // also purges this student's aba_events rows
            }
        }
        foreach ([$userId, $userId2, $userId3, $parent, $staff] as $cleanupUid) {
            if ($cleanupUid > 0) {
                $kit->cleanupUser($cleanupUid);
            }
        }
    }
};
