<?php
/**
 * Suite: Credential rotation window (PA50, AGENTS.md §8 + §12).
 *
 * A handout credential must not live forever. Once it has been in force longer
 * than StudentService::CREDENTIAL_MAX_AGE_DAYS since it was last SET, the next
 * sign-in is forced through the PA48 change screen and one credential_expired
 * event is written to the append-only ledger. This suite proves the policy is
 * deterministic and side-effect-safe:
 *   - a FRESH credential signs in with no forced change and no expiry event;
 *   - an AGED credential arms aba_must_change_password, is forced at login, and
 *     records exactly ONE expiry event (a re-login does not double-record);
 *   - rotating via change-password clears the flag AND stamps the self-change
 *     anchor, so the aged issue stamp no longer forces a change (fresh window);
 *   - a credential inside the window (MAX_AGE-2 days) is not expired;
 *   - a GENERATED handout already armed by PA48 is left alone by the expiry
 *     policy (no expiry event — the forced change predates any lapse);
 *   - a login with no issue marker (a staff/parent account) is never touched.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\StudentService;
use ABA\Core\Services\CredentialAuditService;
use ABA\Core\Repositories\EventRepository;

return function (AbaTestKit $kit): void {
    $kit->section('CredentialExpiryTest — rotation window / forced change on lapse (PA50)');

    global $wpdb;
    $studentsTable = $wpdb->prefix . 'aba_students';
    $eventsTable = $wpdb->prefix . 'aba_events';
    $service = new StudentService();

    $ownSchool = 10; $ownClass = 402;
    $window = StudentService::CREDENTIAL_MAX_AGE_DAYS;

    /** A GMT 'Y-m-d H:i:s' stamp $d days before now (the harness reads it as UTC). */
    $daysAgo = static fn (int $d): string => gmdate('Y-m-d H:i:s', time() - $d * DAY_IN_SECONDS);

    $countExpired = static function (int $sid) use ($wpdb, $eventsTable): int {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE student_id = %d AND event_name = %s",
            $sid, EventRepository::CREDENTIAL_EXPIRED
        ));
    };

    $postLogin = static function (array $body): WP_REST_Request {
        $req = new WP_REST_Request('POST', '/aba/v1/auth/login');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };
    $postChange = static function (array $body): WP_REST_Request {
        $req = new WP_REST_Request('POST', '/aba/v1/auth/change-password');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };

    $created = [];
    $track = static function (?array $student) use (&$created): void {
        if (!empty($student['id'])) { $created[] = (int) $student['id']; }
    };

    try {
        $teacher = $kit->makeStaff($ownSchool, true, $ownClass);
        wp_set_current_user($teacher);

        // ---- (A) a staff-TYPED handout that ages past the window --------------
        $mine = $service->create($teacher, ['firstName' => 'Gia', 'lastName' => 'Huong', 'gradeLevel' => 4]);
        $kit->checkSame('fixture learner created', 'created', $mine['status']);
        $track($mine['student'] ?? null);
        $sid = (int) $mine['student']['id'];
        $userId = (int) $mine['student']['userId'];

        $r = $service->provisionLogin($teacher, $sid, 'TayGiaoVien@123'); // staff-typed => NOT armed
        $kit->checkSame('staff-typed issue provisioned', 'provisioned', $r['status']);
        $username = (string) $r['username'];
        $handout = (string) $r['password'];
        $kit->check('   fresh staff handout not armed', get_user_meta($userId, 'aba_must_change_password', true) !== '1');

        // Fresh login => not forced, no expiry event.
        wp_set_current_user(0);
        $resp = rest_do_request($postLogin(['username' => $username, 'password' => $handout]));
        $kit->checkSame('fresh handout login => 200', 200, $resp->get_status());
        $dto = (array) ($resp->get_data()['user'] ?? []);
        $kit->checkSame('   fresh credential NOT forced', false, !empty($dto['mustChangePassword']));
        $kit->checkSame('   no expiry event yet', 0, $countExpired($sid));

        // Age the issue marker past the window => the next login detects the lapse.
        update_user_meta($userId, 'aba_credential_issued_at', $daysAgo($window + 1));
        $resp = rest_do_request($postLogin(['username' => $username, 'password' => $handout]));
        $kit->checkSame('aged handout login => 200', 200, $resp->get_status());
        $dto = (array) ($resp->get_data()['user'] ?? []);
        $kit->checkSame('   aged handout IS forced', true, !empty($dto['mustChangePassword']));
        $kit->checkSame('   flag armed on the user', '1', get_user_meta($userId, 'aba_must_change_password', true));
        $kit->checkSame('   exactly one expiry event', 1, $countExpired($sid));

        // A re-login while already armed must not double-record the lapse.
        $resp = rest_do_request($postLogin(['username' => $username, 'password' => $handout]));
        $dto = (array) ($resp->get_data()['user'] ?? []);
        $kit->checkSame('   still forced on re-login', true, !empty($dto['mustChangePassword']));
        $kit->checkSame('   expiry NOT recorded twice', 1, $countExpired($sid));

        // The learner rotates through the PA48 flow: clears the flag and stamps a
        // self-change anchor that RESETS the window from now.
        wp_set_current_user($userId);
        $newPassword = 'MatKhauRiengMoi123';
        $resp = rest_do_request($postChange(['oldPassword' => $handout, 'newPassword' => $newPassword]));
        $kit->checkSame('rotate after lapse => 204', 204, $resp->get_status());
        $kit->check('   forced flag cleared', get_user_meta($userId, 'aba_must_change_password', true) !== '1');
        $kit->check('   self-change anchor stamped', get_user_meta($userId, 'aba_password_self_changed_at', true) !== '');

        // Re-login: the issue stamp is still >window days old, but max(issued,
        // self-change)=now => a genuine change bought a fresh window (not expired).
        wp_set_current_user(0);
        $resp = rest_do_request($postLogin(['username' => $username, 'password' => $newPassword]));
        $dto = (array) ($resp->get_data()['user'] ?? []);
        $kit->checkSame('   self-changed credential NOT re-expired', false, !empty($dto['mustChangePassword']));
        $kit->checkSame('   still exactly one expiry event', 1, $countExpired($sid));

        // Inside the window (window-2 days, no self-change) => never expired.
        delete_user_meta($userId, 'aba_password_self_changed_at');
        update_user_meta($userId, 'aba_credential_issued_at', $daysAgo($window - 2));
        $resp = rest_do_request($postLogin(['username' => $username, 'password' => $newPassword]));
        $dto = (array) ($resp->get_data()['user'] ?? []);
        $kit->checkSame('   inside window => not expired', false, !empty($dto['mustChangePassword']));
        $kit->checkSame('   inside window => no new expiry event', 1, $countExpired($sid));

        // ---- (B) a GENERATED handout already armed by PA48 is left alone ------
        $second = $service->create($teacher, ['firstName' => 'Be', 'lastName' => 'Xuan', 'gradeLevel' => 4]);
        $track($second['student'] ?? null);
        $sid2 = (int) $second['student']['id'];
        $userId2 = (int) $second['student']['userId'];
        $r2 = $service->provisionLogin($teacher, $sid2); // generated => armed by PA48
        $kit->checkSame('generated issue arms PA48 flag', '1', get_user_meta($userId2, 'aba_must_change_password', true));
        wp_set_current_user(0);
        $resp = rest_do_request($postLogin(['username' => $r2['username'], 'password' => $r2['password']]));
        $dto = (array) ($resp->get_data()['user'] ?? []);
        $kit->checkSame('   PA48-armed login still forced', true, !empty($dto['mustChangePassword']));
        $kit->checkSame('   expiry NOT recorded for a PA48-armed login', 0, $countExpired($sid2));

        // ---- (C) a caller with no issue marker is never touched by the policy --
        $kit->checkSame('staff (no marker) not armed', false, $service->enforceCredentialExpiry($teacher));
        $kit->checkSame('user id 0 not armed', false, $service->enforceCredentialExpiry(0));
    } finally {
        wp_set_current_user(0);
        foreach ($created as $sid) {
            $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$studentsTable} WHERE id = %d", $sid));
            $kit->cleanup($sid); // also purges this student's aba_events rows
            if ($uid > 0) {
                delete_user_meta($uid, 'aba_credential_issued_at');
                delete_user_meta($uid, 'aba_must_change_password');
                delete_user_meta($uid, 'aba_password_self_changed_at');
                $kit->cleanupUser($uid);
            }
        }
        $kit->cleanupUser($teacher ?? 0);
    }
};
