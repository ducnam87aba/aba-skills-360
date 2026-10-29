<?php
/**
 * Suite: which devices hold my child's account (PA56, AGENTS.md §8 + §12).
 *
 * PA52 told a parent *how many* logins were live, which is enough to know
 * something is wrong but not enough to act on it: "2 thiết bị" does not say
 * whether one of them is the phone that was left at grandma's. This suite
 * proves the named list and the per-device sign-out that closes that gap:
 *   - a linked parent, a class teacher of that child and the learner see the
 *     same rows, labelled the way the family can act on;
 *   - the projection is PA55's, so it carries a handle, a label and two
 *     timestamps — never a token, uuid or stored hash (§8), and nothing is
 *     flagged "current" because none of these devices is the caller's;
 *   - an unlinked parent, a teacher of another class, another school's staff
 *     and an anonymous caller are all refused and kill nothing;
 *   - ending one device leaves the child's others signed in, echoes the label
 *     it ended, is audited once with the actor, and a second click on the same
 *     (now dead) handle is a 404 that appends no second event;
 *   - a handle belonging to ANOTHER account cannot be used through this child;
 *   - a learner with no provisioned login lists nothing rather than erroring,
 *     and one inside the PA48 forced-change lock cannot read the list at all.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\CredentialSessionService;
use ABA\Core\Repositories\EventRepository;

return function (AbaTestKit $kit): void {
    $kit->section('ChildDevicesTest — a child\'s devices, named, for the adult responsible (PA56)');

    global $wpdb;
    $studentsTable = $wpdb->prefix . 'aba_students';
    $eventsTable = $wpdb->prefix . 'aba_events';

    if (!class_exists('WP_Application_Passwords')) {
        require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
    }

    $ownSchool = 10; $ownClass = 404; $otherClass = 405;

    $get = static fn(string $path): WP_REST_Response|WP_Error => rest_do_request(new WP_REST_Request('GET', $path));
    $del = static fn(string $path): WP_REST_Response|WP_Error => rest_do_request(new WP_REST_Request('DELETE', $path));

    /** Live rows of OUR tokens for a user, in core's order. */
    $ourApps = static function (int $userId): array {
        $out = [];
        foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $app) {
            if (CredentialSessionService::isOurApp((string) ($app['name'] ?? ''))) {
                $out[] = $app;
            }
        }
        return $out;
    };
    /** One named device, as a real sign-in would have left it. */
    $addDevice = static function (int $userId, string $label): array {
        [$plain, $item] = \WP_Application_Passwords::create_new_application_password(
            $userId, ['name' => CredentialSessionService::appNameFor($label)]
        );
        return ['plain' => (string) $plain, 'uuid' => (string) ($item['uuid'] ?? ''), 'label' => $label];
    };
    $uuidAlive = static function (int $userId, string $uuid): bool {
        foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $app) {
            if ((string) ($app['uuid'] ?? '') === $uuid) {
                return true;
            }
        }
        return false;
    };
    $countDeviceEvents = static function (int $sid) use ($wpdb, $eventsTable): int {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE student_id = %d AND event_name = %s",
            $sid, EventRepository::CREDENTIAL_DEVICE_REVOKED
        ));
    };
    $labels = static function (array $data): array {
        return array_map(static fn(array $row): string => (string) ($row['label'] ?? ''), (array) ($data['devices'] ?? []));
    };

    $created = [];
    $track = static function (int $studentId) use (&$created): void {
        if ($studentId > 0) { $created[] = $studentId; }
    };

    $teacher = 0; $wrongClassTeacher = 0; $parent = 0; $strangerParent = 0; $outsider = 0;
    $bare = 0; $userId = 0;

    try {
        // A learner with a provisioned login and three devices of their own.
        $teacher = $kit->makeStaff($ownSchool, true, $ownClass);
        $sid = $kit->makeStudent('4_6', $ownSchool, $ownClass);
        $track($sid);
        $userId = $kit->makeUser($sid, 'Aba-MatKhau!2026');

        $phone   = $addDevice($userId, 'Điện thoại');
        $tablet  = $addDevice($userId, 'Máy tính bảng');
        $desktop = $addDevice($userId, 'Máy tính');
        // What a client actually holds: the derived handle, never the uuid.
        $phoneHandle = CredentialSessionService::deviceHandle($phone['uuid']);

        // ---- (A) a linked parent sees the devices, by name ---------------------
        $parent = $kit->makeParent('PH Test');
        $kit->linkParentStudent($parent, $sid);
        wp_set_current_user($parent);

        $resp = $get("/aba/v1/students/{$sid}/devices");
        $kit->checkSame('linked parent reads the devices => 200', 200, $resp->get_status());
        $data = (array) $resp->get_data();
        $kit->checkSame('   the learner is identified', $sid, (int) ($data['studentId'] ?? -1));
        $kit->checkSame('   all three devices are listed', 3, (int) ($data['count'] ?? -1));
        $kit->checkSame('   count agrees with the rows', 3, count((array) ($data['devices'] ?? [])));
        $kit->checkSame('   the PA54 cap is reported for the UI', CredentialSessionService::MAX_DEVICES, (int) ($data['maxDevices'] ?? -1));

        $keys = array_keys($data);
        sort($keys);
        $kit->checkSame('   the body is the documented shape only', ['count', 'devices', 'maxDevices', 'studentId'], $keys);

        $seen = $labels($data);
        sort($seen);
        $kit->checkSame('   every device is named the way a family reads it', ['Máy tính', 'Máy tính bảng', 'Điện thoại'], $seen);

        $rows = (array) ($data['devices'] ?? []);
        $handles = array_column($rows, 'handle');
        $kit->check('   every row carries a handle', count(array_filter($handles, static fn($h): bool => is_string($h) && preg_match('/^[0-9a-f]{24}$/', $h) === 1)) === 3);
        $kit->checkSame('   handles are unique', 3, count(array_unique($handles)));
        $kit->check('   no row is marked as the caller\'s own device', !in_array(true, array_column($rows, 'current'), true));
        $kit->check('   a handle is derived, not the uuid', in_array($phoneHandle, $handles, true) && !in_array($phone['uuid'], $handles, true));
        $kit->check('   each handle resolves to one of this account\'s tokens', array_reduce(
            $rows,
            static fn(bool $ok, array $row): bool => $ok && in_array($row['handle'], array_map(
                static fn(array $app): string => CredentialSessionService::deviceHandle((string) ($app['uuid'] ?? '')),
                $ourApps($userId)
            ), true),
            true
        ));
        $kit->check('   the timestamps are the contract\'s ISO-8601', array_reduce(
            $rows,
            static fn(bool $ok, array $row): bool => $ok
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $row['createdAt']) === 1
                && ($row['lastUsedAt'] === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $row['lastUsedAt']) === 1),
            true
        ));

        // Nothing replayable may leave the server (§8).
        $json = (string) wp_json_encode($data);
        $kit->check('   the response carries no plaintext token', strpos($json, $phone['plain']) === false);
        $kit->check('   nor a token uuid', strpos($json, $phone['uuid']) === false && strpos($json, $tablet['uuid']) === false);
        $kit->check('   nor the device label as a raw token name', strpos($json, CredentialSessionService::appNameFor('Điện thoại')) === false);

        // Stable: two reads must agree, or a stale row could not be acted on.
        $again = (array) $get("/aba/v1/students/{$sid}/devices")->get_data();
        $kit->checkSame('   the handles are stable across reads', $handles, array_column((array) ($again['devices'] ?? []), 'handle'));

        // ---- (B) everyone outside the §8 scope is refused, harmlessly ----------
        $strangerParent = $kit->makeParent('PH Khac');
        wp_set_current_user($strangerParent);
        $kit->checkSame('an unlinked parent => 403', 403, $get("/aba/v1/students/{$sid}/devices")->get_status());
        $kit->checkSame('   and may not end a device => 403', 403, $del("/aba/v1/students/{$sid}/devices/{$phoneHandle}")->get_status());
        $kit->checkSame('   nothing died behind the refusal', 3, count($ourApps($userId)));

        $outsider = $kit->makeStaff($ownSchool + 1, true);
        wp_set_current_user($outsider);
        $kit->checkSame("another school's staff => 403", 403, $get("/aba/v1/students/{$sid}/devices")->get_status());

        wp_set_current_user(0);
        $kit->checkSame('anonymous => 401 from the auth layer', 401, $get("/aba/v1/students/{$sid}/devices")->get_status());
        $kit->checkSame('   anonymous may not end a device either', 401, $del("/aba/v1/students/{$sid}/devices/{$phoneHandle}")->get_status());

        // ---- (C) a teacher is scoped to their class, not the school ------------
        wp_set_current_user($teacher);
        $kit->checkSame('the class teacher reads their own student => 200', 200, $get("/aba/v1/students/{$sid}/devices")->get_status());
        $wrongClassTeacher = $kit->makeStaff($ownSchool, true, $otherClass);
        wp_set_current_user($wrongClassTeacher);
        $kit->checkSame('a teacher of another class => 403', 403, $get("/aba/v1/students/{$sid}/devices")->get_status());

        // ---- (D) the parent ends ONE device, the child's others survive --------
        wp_set_current_user($parent);
        $resp = $del("/aba/v1/students/{$sid}/devices/{$phoneHandle}");
        $kit->checkSame('ending the phone => 200', 200, $resp->get_status());
        $data = (array) $resp->get_data();
        $kit->checkSame('   one device ended', 1, (int) ($data['revoked'] ?? -1));
        $kit->checkSame('   the response names WHICH device it ended', 'Điện thoại', (string) ($data['label'] ?? ''));
        $kit->checkSame('   the remaining total comes back', 2, (int) ($data['count'] ?? -1));
        $kit->checkSame('   the phone token is DEAD', false, $uuidAlive($userId, $phone['uuid']));
        $kit->check('   the tablet is still signed in', $uuidAlive($userId, $tablet['uuid']));
        $kit->check('   the desktop is still signed in', $uuidAlive($userId, $desktop['uuid']));

        $after = (array) $get("/aba/v1/students/{$sid}/devices")->get_data();
        $kit->checkSame('   the list now shows two', 2, (int) ($after['count'] ?? -1));
        $kit->check('   and no longer offers the phone', !in_array('Điện thoại', $labels($after), true));

        // The act is audited once, with the actor and a non-secret payload (§12, §8).
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, payload_json FROM {$eventsTable} WHERE student_id = %d AND event_name = %s ORDER BY id DESC LIMIT 1",
            $sid, EventRepository::CREDENTIAL_DEVICE_REVOKED
        ));
        $kit->check('the sign-out was recorded', !empty($row));
        $payload = json_decode((string) ($row->payload_json ?? ''), true) ?: [];
        $kit->checkSame('   the actor is the parent who clicked', $parent, (int) ($row->user_id ?? 0));
        $kit->checkSame('   the payload names the device, not the token', 'Điện thoại', (string) ($payload['label'] ?? ''));
        $kit->check('   and stores no handle or token', strpos((string) wp_json_encode($payload), $phoneHandle) === false);

        $events = (array) $get("/aba/v1/students/{$sid}/credential-events")->get_data();
        $kit->check('the PA49 history shows the sign-out', in_array('Đăng xuất một thiết bị', array_column((array) ($events['events'] ?? []), 'actionLabel'), true));

        // ---- (E) a second click on the dead handle is honest and silent --------
        $resp = $del("/aba/v1/students/{$sid}/devices/{$phoneHandle}");
        $kit->checkSame('re-ending the same device => 404', 404, $resp->get_status());
        $kit->checkSame('   the code is the one the UI already maps', 'ABA_DEVICE_NOT_FOUND', (string) ($resp->get_data()['code'] ?? ''));
        $kit->checkSame('   and no second event was appended', 1, $countDeviceEvents($sid));
        $kit->checkSame('   the other devices were not touched', 2, count($ourApps($userId)));

        // ---- (F) another account's handle is unreachable from this child -------
        $otherSid = $kit->makeStudent('4_6', $ownSchool, $ownClass);
        $track($otherSid);
        $otherUserId = $kit->makeUser($otherSid, 'Aba-Khac!2026');
        $other = $addDevice($otherUserId, 'Điện thoại');
        $resp = $del("/aba/v1/students/{$sid}/devices/" . CredentialSessionService::deviceHandle($other['uuid']));
        $kit->checkSame('a handle from another account => 404', 404, $resp->get_status());
        $kit->checkSame('   the code does not confirm the device exists', 'ABA_DEVICE_NOT_FOUND', (string) ($resp->get_data()['code'] ?? ''));
        $kit->check('   and that account keeps its device', $uuidAlive($otherUserId, $other['uuid']));

        // ---- (G) the learner reads their own list ------------------------------
        wp_set_current_user($userId);
        $resp = $get("/aba/v1/students/{$sid}/devices");
        $kit->checkSame('the learner reads their own devices => 200', 200, $resp->get_status());
        $kit->checkSame('   the same two devices as the parent sees', 2, (int) ($resp->get_data()['count'] ?? -1));

        // ---- (H) a learner with no provisioned login ---------------------------
        wp_set_current_user($teacher);
        $bare = $kit->makeStudent('4_6', $ownSchool, $ownClass);
        $resp = $get("/aba/v1/students/{$bare}/devices");
        $kit->checkSame('a learner with no login => 200', 200, $resp->get_status());
        $kit->checkSame('   nothing signed in, stated as a fact', 0, (int) ($resp->get_data()['count'] ?? -1));
        $kit->checkSame('   an empty list, not an error', [], (array) ($resp->get_data()['devices'] ?? [null]));

        // ---- (I) the PA48 lock still applies to the new route ------------------
        update_user_meta($userId, 'aba_must_change_password', '1');
        wp_set_current_user($userId);
        $resp = $get("/aba/v1/students/{$sid}/devices");
        $kit->checkSame('a locked learner cannot inspect devices => 403', 403, $resp->get_status());
        $kit->checkSame('   the refusal is the PA51 lock code', 'CREDENTIAL_CHANGE_REQUIRED', (string) ($resp->get_data()['code'] ?? ''));
        $kit->checkSame('   no device was ended behind the lock', 2, count($ourApps($userId)));
        delete_user_meta($userId, 'aba_must_change_password');
    } finally {
        wp_set_current_user(0);
        foreach ($created as $sid2) {
            $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$studentsTable} WHERE id = %d", $sid2));
            $kit->cleanup($sid2); // also purges this student's aba_events rows
            if ($uid > 0) {
                delete_user_meta($uid, 'aba_must_change_password');
                $kit->cleanupUser($uid); // wipes app passwords + session tokens too
            }
        }
        if ($bare > 0) {
            $kit->cleanup($bare);
        }
        foreach ([$teacher, $wrongClassTeacher, $parent, $strangerParent, $outsider] as $uid) {
            if ($uid > 0) { $kit->cleanupUser($uid); }
        }
    }
};
