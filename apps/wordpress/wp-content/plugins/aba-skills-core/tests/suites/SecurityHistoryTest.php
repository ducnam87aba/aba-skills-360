<?php
/**
 * Suite: the security history screen reads the whole ledger (PA61, AGENTS.md §8 + §12).
 *
 * PA49 projected only account actions; PA57, PA59 and PA60 kept appending to the
 * same append-only trail (a new device, a password sign-in, a script replaying
 * it, a lockout) but nothing ever showed the last three. This suite proves the
 * projection now covers every kind, in one newest-first list, without becoming
 * a second, differently-gated way to read student data:
 *
 *   - the four kinds are derived from the event name, never from the caller;
 *   - the list is newest-first across kinds, not per kind;
 *   - `?kind=` narrows it, an unknown value is a 400 (and never an empty list
 *     that would read as "this account is clean");
 *   - the non-secret detail (device label, door, failures, wait) is projected,
 *     and absent detail is null rather than '' or 0;
 *   - the history is the ledger, not the feed: more rows than the feed's 10-row
 *     slice come back;
 *   - no password, agent string or address ever reaches the response;
 *   - scope is unchanged: linked parent 200, unlinked parent 403, other school
 *     403, anonymous 401.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\EventRepository;
use ABA\Core\Services\CredentialAuditService;

return function (AbaTestKit $kit): void {
    $kit->section('SecurityHistoryTest — one history for every kind (PA61)');

    $audit = new CredentialAuditService();
    $password = 'Aba-History!2026';

    $sid = 0; $userId = 0;
    $sid2 = 0; $userId2 = 0;
    $parent = 0; $stranger = 0; $outsiderStaff = 0;

    /** GET the trail, optionally filtered, as the CURRENT user. */
    $get = static function (int $id, string $kind = ''): WP_REST_Response|WP_Error {
        $req = new WP_REST_Request('GET', "/aba/v1/students/{$id}/credential-events");
        if ($kind !== '') {
            $req->set_param('kind', $kind);
        }
        return rest_do_request($req);
    };

    try {
        // ---- (A) the kind is derived from the event, never from the caller ----
        $map = [
            EventRepository::CREDENTIAL_ISSUED          => CredentialAuditService::KIND_ACCOUNT,
            EventRepository::CREDENTIAL_RESET           => CredentialAuditService::KIND_ACCOUNT,
            EventRepository::CREDENTIAL_CHANGED         => CredentialAuditService::KIND_ACCOUNT,
            EventRepository::CREDENTIAL_EXPIRED         => CredentialAuditService::KIND_ACCOUNT,
            EventRepository::CREDENTIAL_DEVICE_ADDED    => CredentialAuditService::KIND_DEVICE,
            EventRepository::CREDENTIAL_DEVICE_REVOKED  => CredentialAuditService::KIND_DEVICE,
            EventRepository::CREDENTIAL_SESSION_OPENED  => CredentialAuditService::KIND_SESSION,
            EventRepository::CREDENTIAL_SESSIONS_REVOKED => CredentialAuditService::KIND_SESSION,
            EventRepository::LOGIN_THROTTLED            => CredentialAuditService::KIND_LOCKOUT,
        ];
        $mappedRight = true;
        foreach ($map as $action => $expected) {
            if (CredentialAuditService::kindFor((string) $action) !== $expected) {
                $mappedRight = false;
            }
        }
        $kit->check('every ledger action lands in its documented kind', $mappedRight);
        $kit->checkSame('   an action nobody classified falls back to an account action',
            CredentialAuditService::KIND_ACCOUNT, CredentialAuditService::kindFor('some_future_event'));

        // ---- (B) one account, all four kinds, written oldest-first -----------
        $sid = $kit->makeStudent('4_6');
        $userId = $kit->makeUser($sid, $password);
        $login = (string) get_userdata($userId)->user_login;

        $audit->recordIssue($userId, $sid, $login, true, 0);          // account
        $audit->recordDeviceAdded($userId, $sid, 'Điện thoại');        // device
        $audit->recordSessionOpened($userId, $sid, 'Máy tính', 'cookie'); // session (browser)
        $audit->recordSessionOpened($userId, $sid, 'Máy tính', 'xmlrpc'); // session (script)
        $audit->recordThrottled($userId, $sid, 'credential', 900, 5);  // lockout
        $audit->recordDeviceRevoked($userId, $sid, 'Máy tính bảng');   // device
        $audit->recordSessionsRevoked($userId, $sid, 2, 1);            // session

        $list = $audit->listFor($sid);
        $kit->checkSame('the history carries every kind, not just account actions', 7, count($list));
        $kit->checkSame('   newest first, across kinds', [
            CredentialAuditService::KIND_SESSION,
            CredentialAuditService::KIND_DEVICE,
            CredentialAuditService::KIND_LOCKOUT,
            CredentialAuditService::KIND_SESSION,
            CredentialAuditService::KIND_SESSION,
            CredentialAuditService::KIND_DEVICE,
            CredentialAuditService::KIND_ACCOUNT,
        ], array_column($list, 'kind'));

        // ---- (C) the non-secret detail each row carries ----------------------
        // Newest first: 0 = every session ended, 1 = the revoked device, 2 = the
        // lockout, 3 = the script sign-in, 4 = the browser sign-in, 5 = the new
        // device, 6 = the original issue. (The script was written LAST of the two
        // sign-ins, so it sorts first.)
        $kit->checkSame('   a browser sign-in says which door it came through', 'cookie', $list[4]['door'] ?? '');
        $kit->checkSame('   a script sign-in says it came through another door', 'xmlrpc', $list[3]['door'] ?? '');
        $kit->checkSame('   and the wording distinguishes the two on screen',
            'Đăng nhập bằng mật khẩu (ứng dụng khác)', $list[3]['actionLabel'] ?? '');
        $kit->checkSame('   a browser sign-in keeps the plain wording',
            'Đăng nhập bằng trình duyệt', $list[4]['actionLabel'] ?? '');
        $kit->checkSame('   a device row names the device, never a token', 'Điện thoại', $list[5]['label'] ?? '');
        $kit->checkSame('   a lockout row says how many guesses it cost', 5, $list[2]['failures'] ?? 0);
        $kit->checkSame('   and how long the wait is', 900, $list[2]['retryAfter'] ?? 0);

        // Absent detail must be null, not '' and not 0: the screen has to tell
        // "no device was named" from "a device named nothing". array_key_exists
        // because `??` also swallows a null VALUE and would pass either way.
        $issue = $list[6];
        $kit->check('   a row with no device detail carries nulls, not blanks',
            array_key_exists('label', $issue) && $issue['label'] === null
            && array_key_exists('door', $issue) && $issue['door'] === null
            && array_key_exists('failures', $issue) && $issue['failures'] === null
            && array_key_exists('retryAfter', $issue) && $issue['retryAfter'] === null);
        $kit->checkSame('   an account action is still labelled for the family',
            'Cấp tài khoản đăng nhập', $issue['actionLabel'] ?? '');

        // ---- (D) filtering is a narrowing of the SAME trail -------------------
        $devices = $audit->listFor($sid, null, CredentialAuditService::KIND_DEVICE);
        $kit->checkSame('the device filter returns only device rows', 2, count($devices));
        $kit->check('   and every one of them is a device row',
            array_unique(array_column($devices, 'kind')) === [CredentialAuditService::KIND_DEVICE]);
        $kit->checkSame('the sign-in filter returns the three session rows', 3,
            count($audit->listFor($sid, null, CredentialAuditService::KIND_SESSION)));
        $kit->checkSame('the alert filter returns the lockout', 1,
            count($audit->listFor($sid, null, CredentialAuditService::KIND_LOCKOUT)));
        $kit->checkSame('an unknown filter is ignored, never "no rows"', 7,
            count($audit->listFor($sid, null, 'not-a-kind')));

        // ---- (E) the history is the ledger, not the feed's 10-row slice ------
        $sid2 = $kit->makeStudent('4_6');
        $userId2 = $kit->makeUser($sid2, 'Aba-History!2027');
        for ($i = 0; $i < 12; $i++) {
            $audit->recordDeviceAdded($userId2, $sid2, 'Máy tính');
        }
        $kit->checkSame('more rows than the feed would slice come back', 12,
            count($audit->listFor($sid2, null, CredentialAuditService::KIND_DEVICE)));

        // ---- (F) scope: the new projection adds no new way in -----------------
        wp_set_current_user(0);
        $kit->checkSame('anonymous => 401', 401, $get($sid)->get_status());

        $parent = $kit->makeParent('PH Lich Su');
        $kit->linkParentStudent($parent, $sid);
        wp_set_current_user($parent);
        $resp = $get($sid);
        $kit->checkSame('a linked parent reads the whole history => 200', 200, $resp->get_status());
        $body = (array) $resp->get_data();
        $kit->checkSame('   with every kind in it', 7, count($body['events'] ?? []));

        $resp = $get($sid, CredentialAuditService::KIND_LOCKOUT);
        $kit->checkSame('   and may filter it server-side => 200', 200, $resp->get_status());
        $kit->checkSame('   down to the one lockout', 1, count((array) ($resp->get_data()['events'] ?? [])));

        $kit->checkSame('an unknown ?kind= is refused => 400', 400,
            $get($sid, 'device; DROP TABLE')->get_status());

        // §8 — nothing replayable and nothing that identifies the network.
        $serialized = (string) wp_json_encode($body);
        $kit->check('the response never carries the password', strpos($serialized, $password) === false);
        $kit->check('   nor an agent string or a client address',
            strpos(strtolower($serialized), 'mozilla') === false
            && strpos($serialized, '127.0.0.1') === false
            && strpos($serialized, '192.0.2.') === false);

        $stranger = $kit->makeParent('PH Khong Lien Ket Su');
        wp_set_current_user($stranger);
        $kit->checkSame('an unlinked parent cannot read it => 403', 403, $get($sid)->get_status());

        $outsiderStaff = $kit->makeStaff(20, true, 501); // a different school entirely
        wp_set_current_user($outsiderStaff);
        $kit->checkSame('staff from another school cannot read it => 403', 403, $get($sid)->get_status());

        wp_set_current_user($parent);
        $kit->checkSame('   nor can that parent reach the other learner => 403', 403, $get($sid2)->get_status());
    } finally {
        wp_set_current_user(0);
        foreach ([$sid, $sid2] as $cleanupSid) {
            if ($cleanupSid > 0) {
                $kit->cleanup($cleanupSid); // also purges this student's aba_events rows
            }
        }
        foreach ([$userId, $userId2, $parent, $stranger, $outsiderStaff] as $cleanupUid) {
            if ($cleanupUid > 0) {
                $kit->cleanupUser($cleanupUid);
            }
        }
    }
};
