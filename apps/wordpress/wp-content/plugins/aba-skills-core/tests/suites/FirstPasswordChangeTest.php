<?php
/**
 * Suite: Forced first-login password change (PA48, §8).
 *
 * The issue side (StudentCredentialsTest) stamps aba_must_change_password when
 * a learner gets a GENERATED handout password. This suite proves the swap side:
 *   - POST /auth/change-password refuses anonymous callers (401);
 *   - it verifies the CURRENT password again — the session alone is not enough;
 *   - body validation mirrors the contract (400 missing / short / unchanged);
 *   - success is a 204 that kills the old password, clears the flag, and revokes
 *     the outstanding app token so a hijacked pair cannot outlive the secret;
 *   - the new secret logs in with the flag gone; /me agrees (reload-safe);
 *   - a staff-CHOSEN password never arms the flag in the first place.
 *
 * The forced redirect itself is a UX guard (client guard.test.ts); the server
 * intentionally still answers every endpoint — no learner data is writable from
 * the change screen, and the credential dies here regardless of the client.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\StudentService;

return function (AbaTestKit $kit): void {
    $kit->section('FirstPasswordChangeTest — forced first-login credential swap (PA48)');

    global $wpdb;
    $studentsTable = $wpdb->prefix . 'aba_students';
    $service = new StudentService();

    $ownSchool = 10; $ownClass = 402;

    $created = [];
    $track = static function (?array $student) use (&$created): void {
        if (!empty($student['id'])) { $created[] = (int) $student['id']; }
    };

    $postJson = static function (array $body): WP_REST_Request {
        $req = new WP_REST_Request('POST', '/aba/v1/auth/change-password');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };

    try {
        $teacher = $kit->makeStaff($ownSchool, true, $ownClass);

        // ---- A learner with a generated handout password ----------------------
        wp_set_current_user($teacher);
        $mine = $service->create($teacher, ['firstName' => 'An', 'lastName' => 'Pham', 'gradeLevel' => 4]);
        $kit->checkSame('fixture learner created', 'created', $mine['status']);
        $track($mine['student'] ?? null);
        $sid = (int) $mine['student']['id'];
        $userId = (int) $mine['student']['userId'];

        $r = $service->provisionLogin($teacher, $sid);
        $kit->checkSame('generated handout provisioned', 'provisioned', $r['status']);
        $username = (string) $r['username'];
        $handout = (string) $r['password'];
        $kit->checkSame('flag armed for a generated password', '1', get_user_meta($userId, 'aba_must_change_password', true));

        // ---- Anonymous caller is refused --------------------------------------
        wp_set_current_user(0);
        $resp = rest_do_request($postJson(['oldPassword' => $handout, 'newPassword' => 'MatKhauRieng123']));
        $kit->checkSame('anonymous change-password => 401', 401, $resp->get_status());

        // ---- Log in with the handout pair: the flag rides the DTO -------------
        wp_set_current_user($userId);
        $resp = rest_do_request((static function (array $body): WP_REST_Request {
            $req = new WP_REST_Request('POST', '/aba/v1/auth/login');
            $req->set_header('Content-Type', 'application/json');
            $req->set_body(wp_json_encode($body));
            return $req;
        })(['username' => $username, 'password' => $handout]));
        $kit->checkSame('handout pair logs in => 200', 200, $resp->get_status());
        $dto = (array) ($resp->get_data()['user'] ?? []);
        $kit->checkSame('login DTO announces the forced change', true, !empty($dto['mustChangePassword']));
        $token = $resp->get_data()['token'] ?? null;

        // ---- Body validation ---------------------------------------------------
        wp_set_current_user($userId);
        $resp = rest_do_request($postJson(['oldPassword' => '', 'newPassword' => '']));
        $kit->checkSame('missing fields => 400', 400, $resp->get_status());
        $kit->checkSame('   code INVALID_REQUEST', 'INVALID_REQUEST', (string) ($resp->get_data()['code'] ?? ''));

        $resp = rest_do_request($postJson(['oldPassword' => $handout, 'newPassword' => 'short1']));
        $kit->checkSame('new password under 8 chars => 400', 400, $resp->get_status());
        $kit->checkSame('   code PASSWORD_TOO_SHORT', 'PASSWORD_TOO_SHORT', (string) ($resp->get_data()['code'] ?? ''));

        $resp = rest_do_request($postJson(['oldPassword' => 'khong-phai-no', 'newPassword' => 'MatKhauRieng123']));
        $kit->checkSame('wrong current password => 403', 403, $resp->get_status());
        $kit->checkSame('   code INVALID_CREDENTIALS', 'INVALID_CREDENTIALS', (string) ($resp->get_data()['code'] ?? ''));
        $kit->check('   the handout password still works after the refusal', wp_check_password($handout, get_userdata($userId)->user_pass, $userId));

        $resp = rest_do_request($postJson(['oldPassword' => $handout, 'newPassword' => $handout]));
        $kit->checkSame('new == current => 400', 400, $resp->get_status());
        $kit->checkSame('   code PASSWORD_UNCHANGED', 'PASSWORD_UNCHANGED', (string) ($resp->get_data()['code'] ?? ''));

        // ---- The real swap ------------------------------------------------------
        $newPassword = 'MatKhauRieng123';
        $resp = rest_do_request($postJson(['oldPassword' => $handout, 'newPassword' => $newPassword]));
        $kit->checkSame('correct old + strong new => 204', 204, $resp->get_status());
        $user = get_userdata($userId);
        $kit->check('   new password is the live credential', wp_check_password($newPassword, $user->user_pass, $userId));
        $kit->check('   the staff handout password is DEAD', !wp_check_password($handout, $user->user_pass, $userId));
        $kit->check('   forced-change flag cleared', get_user_meta($userId, 'aba_must_change_password', true) === '');
        if (is_string($token) && $token !== '') {
            // The token minted against the old secret must not outlive it. Core
            // authenticates Basic only against listed apps, so an empty dedicated
            // list proves the replay path is closed (this build has no public
            // verify() API to test the raw token directly).
            require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
            $kit->check('   outstanding app token revoked',
                \WP_Application_Passwords::get_user_application_passwords($userId) === []);
        }

        // ---- Re-login: 200 with the flag gone; /me agrees -----------------------
        wp_set_current_user(0);
        $resp = rest_do_request((static function (array $body): WP_REST_Request {
            $req = new WP_REST_Request('POST', '/aba/v1/auth/login');
            $req->set_header('Content-Type', 'application/json');
            $req->set_body(wp_json_encode($body));
            return $req;
        })(['username' => $username, 'password' => $newPassword]));
        $kit->checkSame('new pair logs in => 200', 200, $resp->get_status());
        $dto = (array) ($resp->get_data()['user'] ?? []);
        $kit->checkSame('   flag cleared in the login DTO', false, !empty($dto['mustChangePassword']));

        wp_set_current_user($userId);
        $meReq = new WP_REST_Request('GET', '/aba/v1/me');
        $resp = rest_do_request($meReq);
        $kit->checkSame('/me agrees after a reload => 200', 200, $resp->get_status());
        $kit->checkSame('   /me carries the cleared flag', false, !empty($resp->get_data()['mustChangePassword']));

        // ---- The handout can never be handed out again silently ----------------
        $r = $service->provisionLogin($teacher, $sid);
        $kit->checkSame('re-issue after the swap is still guarded', 'already', $r['status']);

        // ---- A staff-chosen password never arms the flag ------------------------
        $second = $service->create($teacher, ['firstName' => 'Bin', 'lastName' => 'Ho', 'gradeLevel' => 4]);
        $track($second['student'] ?? null);
        $sid2 = (int) $second['student']['id'];
        $userId2 = (int) $second['student']['userId'];
        $r = $service->provisionLogin($teacher, $sid2, 'GiaoVienDat@123');
        $kit->checkSame('custom-password issue provisioned', 'provisioned', $r['status']);
        $kit->checkSame('   no forced change for a staff-typed secret', false, !empty($r['mustChangePassword']));
        $kit->check('   flag meta absent', get_user_meta($userId2, 'aba_must_change_password', true) === '');
    } finally {
        wp_set_current_user(0);
        foreach ($created as $sid2) {
            $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$studentsTable} WHERE id = %d", $sid2));
            $kit->cleanup($sid2);
            if ($uid > 0) {
                delete_user_meta($uid, 'aba_credential_issued_at');
                delete_user_meta($uid, 'aba_must_change_password');
                $kit->cleanupUser($uid);
            }
        }
        $kit->cleanupUser($teacher ?? 0);
    }
};
