<?php
/**
 * Suite: Credential lock + session revocation (PA51, AGENTS.md §8).
 *
 * PA48/PA50 made "must change the password" a fact the DTO carried; only the
 * Next.js guard acted on it, so a caller holding a live token could ignore the
 * screen and use the whole API. PA51 moves the decision to the server and makes
 * a rotation actually take effect. This suite proves:
 *   - a locked credential is refused with 403 CREDENTIAL_CHANGE_REQUIRED on
 *     every business route, not just one, and the refusal carries the reason;
 *   - the escape route stays open (/me, /auth/change-password), so the learner
 *     is never trapped, and the lock lifts the moment the new secret is set;
 *   - the lock is scoped: an anonymous caller still gets the auth layer's 401
 *     (never the lock code), and an unflagged staff account is untouched;
 *   - a staff issue/reset ends BOTH credential channels — the plugin's app
 *     password and the session tokens — while an administrator's manually
 *     issued app password survives, and the count is recorded on the ledger;
 *   - a self-service change ends session tokens too (wp_set_password does not).
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\StudentService;
use ABA\Core\Services\CredentialSessionService;
use ABA\Core\Repositories\EventRepository;

return function (AbaTestKit $kit): void {
    $kit->section('CredentialLockTest — server-side lock + revocation on rotation (PA51)');

    global $wpdb;
    $studentsTable = $wpdb->prefix . 'aba_students';
    $eventsTable = $wpdb->prefix . 'aba_events';
    $service = new StudentService();
    $sessionService = new CredentialSessionService();

    $ownSchool = 10; $ownClass = 403;

    $get = static function (string $path): WP_REST_Response|WP_Error {
        return rest_do_request(new WP_REST_Request('GET', $path));
    };
    $postChange = static function (array $body): WP_REST_Request {
        $req = new WP_REST_Request('POST', '/aba/v1/auth/change-password');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };

    /** How many app passwords does this user hold (optionally filtered by name)? */
    $countApps = static function (int $userId, ?string $name = null): int {
        if (!class_exists('WP_Application_Passwords')) {
            require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
        }
        $n = 0;
        foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $app) {
            if ($name === null || ($app['name'] ?? '') === $name) {
                $n++;
            }
        }
        return $n;
    };
    $countSessions = static function (int $userId): int {
        return count(\WP_Session_Tokens::get_instance($userId)->get_all());
    };
    /** Simulate a live cookie login somewhere (a device we cannot see). */
    $fakeSession = static function (int $userId): void {
        \WP_Session_Tokens::get_instance($userId)->create(time() + DAY_IN_SECONDS);
    };

    $created = [];
    $track = static function (?array $student) use (&$created): void {
        if (!empty($student['id'])) { $created[] = (int) $student['id']; }
    };

    try {
        $teacher = $kit->makeStaff($ownSchool, true, $ownClass);
        wp_set_current_user($teacher);

        $mine = $service->create($teacher, ['firstName' => 'Khoa', 'lastName' => 'Dam', 'gradeLevel' => 4]);
        $kit->checkSame('fixture learner created', 'created', $mine['status']);
        $track($mine['student'] ?? null);
        $sid = (int) $mine['student']['id'];
        $userId = (int) $mine['student']['userId'];

        // A generated handout is armed by PA48 => the credential starts LOCKED.
        $r = $service->provisionLogin($teacher, $sid);
        $kit->checkSame('generated issue provisioned', 'provisioned', $r['status']);
        $handout = (string) $r['password'];
        $kit->checkSame('   the learner starts locked', '1', get_user_meta($userId, 'aba_must_change_password', true));

        // ---- (A) the lock is enforced on EVERY business route -----------------
        wp_set_current_user($userId);

        $resp = $get('/aba/v1/learning/lessons');
        $kit->checkSame('locked learner, content catalog => 403', 403, $resp->get_status());
        $kit->checkSame('   code CREDENTIAL_CHANGE_REQUIRED', 'CREDENTIAL_CHANGE_REQUIRED', (string) ($resp->get_data()['code'] ?? ''));
        $details = (array) ($resp->get_data()['data']['details'] ?? []);
        $kit->checkSame('   the body says why the call was refused', true, !empty($details['mustChangePassword']));

        $resp = $get('/aba/v1/learning/path');
        $kit->checkSame('locked learner, learning path => 403', 403, $resp->get_status());

        $resp = $get('/aba/v1/students/me');
        $kit->checkSame('locked learner, own student record => 403', 403, $resp->get_status());

        // The escape route must stay open or the account is trapped.
        $resp = $get('/aba/v1/me');
        $kit->checkSame('locked learner, /me still readable => 200', 200, $resp->get_status());
        $kit->checkSame('   /me carries the forced-change flag', true, !empty($resp->get_data()['mustChangePassword']));

        // ---- (B) the lock is scoped: it is not a blanket 403 -------------------
        wp_set_current_user(0);
        $resp = $get('/aba/v1/learning/lessons');
        $kit->checkSame('anonymous => 401 from the auth layer', 401, $resp->get_status());
        $kit->check('   anonymous never sees the lock code', ($resp->get_data()['code'] ?? '') !== 'CREDENTIAL_CHANGE_REQUIRED');

        wp_set_current_user($teacher);
        $resp = $get('/aba/v1/learning/lessons');
        $kit->checkSame('unflagged staff => unaffected (200)', 200, $resp->get_status());

        // ---- (C) changing the password lifts the lock -------------------------
        wp_set_current_user($userId);
        $newPassword = 'MatKhauRiengCuaBe123';
        $resp = rest_do_request($postChange(['oldPassword' => $handout, 'newPassword' => $newPassword]));
        $kit->checkSame('locked learner may still change the password => 204', 204, $resp->get_status());
        $resp = $get('/aba/v1/learning/lessons');
        $kit->checkSame('after the change the same call => 200', 200, $resp->get_status());
        $resp = $get('/aba/v1/students/me');
        $kit->checkSame('   and the student record is reachable again', 200, $resp->get_status());

        // ---- (D) a staff rotation kills BOTH credential channels --------------
        // Recreate the state a real family would be in: one live app token of
        // ours, one administrator-issued app, one cookie session on a device.
        wp_set_current_user($teacher);
        if (!class_exists('WP_Application_Passwords')) {
            require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
        }
        \WP_Application_Passwords::create_new_application_password($userId, ['name' => CredentialSessionService::APP_NAME]);
        \WP_Application_Passwords::create_new_application_password($userId, ['name' => 'Admin Tool']);
        $fakeSession($userId);
        $kit->checkSame('fixture holds one plugin app token', 1, $countApps($userId, CredentialSessionService::APP_NAME));
        $kit->checkSame('   and one admin-issued app', 1, $countApps($userId, 'Admin Tool'));
        $kit->checkSame('   and one live session', 1, $countSessions($userId));

        $rotated = $service->provisionLogin($teacher, $sid, 'GiaoVienChon@123', true);
        $kit->checkSame('explicit rotate provisioned', 'provisioned', $rotated['status']);
        $kit->checkSame('   the old app token is DEAD', 0, $countApps($userId, CredentialSessionService::APP_NAME));
        $kit->checkSame('   the live session is DEAD', 0, $countSessions($userId));
        $kit->checkSame('   an admin-issued app survives', 1, $countApps($userId, 'Admin Tool'));
        $kit->check('   the response reports how many logins ended', ((int) ($rotated['sessionsRevoked'] ?? 0)) >= 2);
        $kit->checkSame('   the old secret no longer logs in', false, wp_check_password($newPassword, get_userdata($userId)->user_pass, $userId));

        $payload = json_decode((string) $wpdb->get_var($wpdb->prepare(
            "SELECT payload_json FROM {$eventsTable} WHERE student_id = %d AND event_name = %s ORDER BY id DESC LIMIT 1",
            $sid, EventRepository::CREDENTIAL_RESET
        )), true) ?: [];
        $kit->check('   the ledger records the revocation count', ((int) ($payload['sessionsRevoked'] ?? 0)) >= 2);
        $kit->check('   the ledger still carries no secret', strpos((string) wp_json_encode($payload), 'GiaoVienChon@123') === false);

        // ---- (E) a self-service change also ends session tokens ---------------
        // wp_set_password() does not touch sessions in this build, so this is not
        // free: the change handler must revoke explicitly or old devices live on.
        $fakeSession($userId);
        $kit->checkSame('fixture session restored', 1, $countSessions($userId));
        wp_set_current_user($userId);
        $resp = rest_do_request($postChange(['oldPassword' => 'GiaoVienChon@123', 'newPassword' => 'MatKhauMoiNhat123']));
        $kit->checkSame('self-service change => 204', 204, $resp->get_status());
        $kit->checkSame('   every other session is DEAD', 0, $countSessions($userId));
        $kit->checkSame('   and no app token of ours is left', 0, $countApps($userId, CredentialSessionService::APP_NAME));

        // ---- (F) the service refuses nonsense without touching anything -------
        $kit->checkSame('revokeAll(0) is a no-op', ['appPasswords' => 0, 'sessions' => 0], $sessionService->revokeAll(0));
    } finally {
        wp_set_current_user(0);
        foreach ($created as $sid2) {
            $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$studentsTable} WHERE id = %d", $sid2));
            $kit->cleanup($sid2); // also purges this student's aba_events rows
            if ($uid > 0) {
                delete_user_meta($uid, 'aba_credential_issued_at');
                delete_user_meta($uid, 'aba_must_change_password');
                delete_user_meta($uid, 'aba_password_self_changed_at');
                $kit->cleanupUser($uid); // wipes app passwords + session tokens too
            }
        }
        $kit->cleanupUser($teacher ?? 0);
    }
};
