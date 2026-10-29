<?php
/**
 * Suite: live logins + "sign out every device" (PA52, AGENTS.md §8 + §12).
 *
 * A parent who suspects the child's password has been shared needs two things
 * and neither existed before: a way to SEE that something is still signed in,
 * and a way to end it without knowing or changing the password. This suite
 * proves both, and proves they cannot leak:
 *   - the inventory returns COUNTS ONLY — no token, uuid, hash or plaintext app
 *     password ever appears in the body (a token is as sensitive as the secret);
 *   - read and revoke share the PA49 gate: a linked parent, scoped staff and the
 *     learner may act; an unlinked parent, another school's staff and an
 *     anonymous caller all get 403/401 and nothing is killed;
 *   - a revoke ends BOTH channels (our app tokens + cookie sessions) while an
 *     administrator-issued app survives, records exactly one audited event with
 *     the actor and the non-secret counts, and shows up in the PA49 history;
 *   - running it twice is a successful no-op that does NOT append a second
 *     event (an empty click must not rewrite the family's history);
 *   - a learner still inside the PA48 forced-change lock cannot use either
 *     endpoint — handing them a revoke button before they set their own
 *     password would just be a detour in the flow the lock exists to enforce.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\StudentService;
use ABA\Core\Services\CredentialSessionService;
use ABA\Core\Repositories\EventRepository;

return function (AbaTestKit $kit): void {
    $kit->section('CredentialSessionsTest — live logins & parent-initiated revoke (PA52)');

    global $wpdb;
    $studentsTable = $wpdb->prefix . 'aba_students';
    $eventsTable = $wpdb->prefix . 'aba_events';
    $service = new StudentService();

    $ownSchool = 10; $ownClass = 404;

    $get = static function (string $path): WP_REST_Response|WP_Error {
        return rest_do_request(new WP_REST_Request('GET', $path));
    };
    $post = static function (string $path): WP_REST_Response|WP_Error {
        return rest_do_request(new WP_REST_Request('POST', $path));
    };

    /** How many app passwords does this user hold in total / by name? */
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
    $fakeSession = static function (int $userId): void {
        \WP_Session_Tokens::get_instance($userId)->create(time() + DAY_IN_SECONDS);
    };
    $countRevokedEvents = static function (int $sid) use ($wpdb, $eventsTable): int {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE student_id = %d AND event_name = %s",
            $sid, EventRepository::CREDENTIAL_SESSIONS_REVOKED
        ));
    };

    $created = [];
    $track = static function (?array $student) use (&$created): void {
        if (!empty($student['id'])) { $created[] = (int) $student['id']; }
    };

    $bare = 0;
    $teacher = 0;
    $parent = 0;
    $strangerParent = 0;
    $outsider = 0;

    try {
        $teacher = $kit->makeStaff($ownSchool, true, $ownClass);
        wp_set_current_user($teacher);

        $mine = $service->create($teacher, ['firstName' => 'Linh', 'lastName' => 'Phat', 'gradeLevel' => 4]);
        $kit->checkSame('fixture learner created', 'created', $mine['status']);
        $track($mine['student'] ?? null);
        $sid = (int) $mine['student']['id'];
        $userId = (int) $mine['student']['userId'];

        // A staff-TYPED handout is not armed by PA48, so the learner is usable
        // normally — the lock interaction is exercised separately in (E).
        $r = $service->provisionLogin($teacher, $sid, 'MatKhauBanDau@123');
        $kit->checkSame('staff-typed credential provisioned', 'provisioned', $r['status']);
        $kit->checkSame('   the learner is NOT locked', false, get_user_meta($userId, 'aba_must_change_password', true) === '1');

        // The state a real family would be in: our headless token, an app an
        // administrator issued by hand, and one cookie login somewhere else.
        if (!class_exists('WP_Application_Passwords')) {
            require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
        }
        [$appPlain, $appItem] = \WP_Application_Passwords::create_new_application_password(
            $userId, ['name' => CredentialSessionService::APP_NAME]
        );
        \WP_Application_Passwords::create_new_application_password($userId, ['name' => 'Admin Tool']);
        $fakeSession($userId);

        // ---- (A) a linked parent can SEE the logins, as counts only ------------
        $parent = $kit->makeParent('PH Test');
        $kit->linkParentStudent($parent, $sid);
        wp_set_current_user($parent);

        $resp = $get("/aba/v1/students/{$sid}/sessions");
        $kit->checkSame('linked parent reads the live logins => 200', 200, $resp->get_status());
        $data = (array) $resp->get_data();
        $kit->checkSame('   one app token + one cookie session', 2, (int) ($data['total'] ?? -1));
        $kit->checkSame('   app tokens counted', 1, (int) ($data['appPasswords'] ?? -1));
        $kit->checkSame('   cookie sessions counted', 1, (int) ($data['sessions'] ?? -1));

        // The whole point of the shape: nothing replayable leaves the server.
        $keys = array_keys($data);
        sort($keys);
        $kit->checkSame('   the body is counts + identity ONLY', ['appPasswords', 'sessions', 'studentId', 'total'], $keys);
        $json = (string) wp_json_encode($data);
        $kit->check('   the response carries no app password', strpos($json, (string) $appPlain) === false);
        $kit->check('   nor its stored hash', strpos($json, (string) ($appItem['password'] ?? '@@none@@')) === false);

        // ---- (B) everyone outside the §8 scope is refused, harmlessly ----------
        $strangerParent = $kit->makeParent('PH Khac');
        wp_set_current_user($strangerParent);
        $kit->checkSame('an unlinked parent => 403', 403, $get("/aba/v1/students/{$sid}/sessions")->get_status());
        $kit->checkSame('   and may not revoke => 403', 403, $post("/aba/v1/students/{$sid}/sessions/revoke")->get_status());
        $kit->checkSame('   nothing was killed by the refused call', 2, $countApps($userId, CredentialSessionService::APP_NAME) + $countSessions($userId));

        $outsider = $kit->makeStaff($ownSchool + 1, true);
        wp_set_current_user($outsider);
        $kit->checkSame("another school's staff => 403", 403, $get("/aba/v1/students/{$sid}/sessions")->get_status());

        wp_set_current_user(0);
        $kit->checkSame('anonymous => 401 from the auth layer', 401, $get("/aba/v1/students/{$sid}/sessions")->get_status());

        // ---- (C) the parent ends every device --------------------------------
        wp_set_current_user($parent);
        $resp = $post("/aba/v1/students/{$sid}/sessions/revoke");
        $kit->checkSame('linked parent revokes => 200', 200, $resp->get_status());
        $data = (array) $resp->get_data();
        $kit->checkSame('   it reports what it ended', 2, (int) ($data['revoked'] ?? -1));
        $kit->checkSame('   our app token is DEAD', 0, $countApps($userId, CredentialSessionService::APP_NAME));
        $kit->checkSame('   the cookie session is DEAD', 0, $countSessions($userId));
        $kit->checkSame('   an admin-issued app survives', 1, $countApps($userId, 'Admin Tool'));
        $kit->checkSame('   the account now shows nothing live', 0, (int) ($data['total'] ?? -1));

        // The act is audited, with the actor and non-secret counts only (§12, §8).
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, payload_json FROM {$eventsTable} WHERE student_id = %d AND event_name = %s ORDER BY id DESC LIMIT 1",
            $sid, EventRepository::CREDENTIAL_SESSIONS_REVOKED
        ));
        $kit->check('a revocation event was recorded', !empty($row));
        $payload = json_decode((string) ($row->payload_json ?? ''), true) ?: [];
        $kit->checkSame('   the actor is the parent who clicked', $parent, (int) ($row->user_id ?? 0));
        $kit->checkSame('   payload counts app tokens', 1, (int) ($payload['appPasswords'] ?? -1));
        $kit->checkSame('   payload counts cookie sessions', 1, (int) ($payload['sessions'] ?? -1));
        $kit->check('   payload stores no token or hash', strpos((string) wp_json_encode($payload), (string) $appPlain) === false);

        $resp = $get("/aba/v1/students/{$sid}/credential-events");
        $labels = array_column((array) ($resp->get_data()['events'] ?? []), 'actionLabel');
        $kit->check('the PA49 history shows the sign-out', in_array('Đăng xuất mọi thiết bị', $labels, true));

        // ---- (D) idempotent: an empty account is not an error or a new event ---
        $resp = $post("/aba/v1/students/{$sid}/sessions/revoke");
        $kit->checkSame('revoking an empty account => 200', 200, $resp->get_status());
        $kit->checkSame('   it ended nothing', 0, (int) ($resp->get_data()['revoked'] ?? -1));
        $kit->checkSame('   and appended no second event', 1, $countRevokedEvents($sid));

        // ---- (E) staff may do the same, but a LOCKED learner may not ----------
        \WP_Application_Passwords::create_new_application_password($userId, ['name' => CredentialSessionService::APP_NAME]);
        $fakeSession($userId);
        update_user_meta($userId, 'aba_must_change_password', '1');

        wp_set_current_user($userId);
        $resp = $get("/aba/v1/students/{$sid}/sessions");
        $kit->checkSame('a locked learner cannot inspect sessions => 403', 403, $resp->get_status());
        $kit->checkSame('   the refusal is the PA51 lock code', 'CREDENTIAL_CHANGE_REQUIRED', (string) ($resp->get_data()['code'] ?? ''));
        $kit->checkSame('   nor revoke from under the lock => 403', 403, $post("/aba/v1/students/{$sid}/sessions/revoke")->get_status());
        $kit->checkSame('   nothing died behind the lock', 2, $countApps($userId, CredentialSessionService::APP_NAME) + $countSessions($userId));

        delete_user_meta($userId, 'aba_must_change_password');
        wp_set_current_user($teacher);
        $resp = $post("/aba/v1/students/{$sid}/sessions/revoke");
        $kit->checkSame('scoped staff revokes on the family\'s behalf => 200', 200, $resp->get_status());
        $kit->checkSame('   both channels ended', 2, (int) ($resp->get_data()['revoked'] ?? -1));
        $kit->checkSame('   and it is audited a second time', 2, $countRevokedEvents($sid));

        // ---- (F) a learner with no provisioned login -------------------------
        $bare = $kit->makeStudent('4_6', $ownSchool, $ownClass);
        $resp = $get("/aba/v1/students/{$bare}/sessions");
        $kit->checkSame('a learner with no login => 200', 200, $resp->get_status());
        $kit->checkSame('   nothing live, stated as a fact', 0, (int) ($resp->get_data()['total'] ?? -1));
        $resp = $post("/aba/v1/students/{$bare}/sessions/revoke");
        $kit->checkSame('   revoking it is a no-op, not an error', 0, (int) ($resp->get_data()['revoked'] ?? -1));
        $kit->checkSame('   and writes nothing to the history', 0, $countRevokedEvents($bare));
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
        if ($bare > 0) {
            $kit->cleanup($bare);
        }
        foreach ([$teacher, $parent, $strangerParent, $outsider] as $uid) {
            if ($uid > 0) { $kit->cleanupUser($uid); }
        }
    }
};
