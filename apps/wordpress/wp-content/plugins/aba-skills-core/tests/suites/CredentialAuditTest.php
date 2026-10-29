<?php
/**
 * Suite: Credential audit trail (PA49, AGENTS.md §8 + §12).
 *
 * Every sensitive account action — a staff issuing a login, a staff rotating it,
 * a learner swapping a handout password for a private one — must land on the
 * append-only wp_aba_events ledger, never a mutable "last modified" column, so
 * history cannot be silently rewritten (§12). This suite proves:
 *   - each of the three actions appends exactly one correctly-named row, in
 *     order, and an earlier row is never mutated or removed by a later action;
 *   - no password, hash, or app token is ever written to payload_json (§8);
 *   - CredentialAuditService::listFor() reads newest-first with a human label
 *     for the actor, never a raw user id;
 *   - GET /students/{id}/credential-events enforces requireViewStudent: an
 *     unrelated account (403), a linked parent and the learner themselves (200,
 *     and 200 with "chính bạn" wording for their OWN actions), an unknown id
 *     (404), and an anonymous caller (401).
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\StudentService;
use ABA\Core\Services\CredentialAuditService;
use ABA\Core\Repositories\EventRepository;

return function (AbaTestKit $kit): void {
    $kit->section('CredentialAuditTest — append-only account audit trail (PA49)');

    global $wpdb;
    $eventsTable = $wpdb->prefix . 'aba_events';
    $service = new StudentService();
    $audit = new CredentialAuditService();

    $ownSchool = 10; $ownClass = 402;

    $postJson = static function (string $route, array $body): WP_REST_Request {
        $req = new WP_REST_Request('POST', $route);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };

    /** Raw payload_json rows for this student, oldest first (DB truth, not the API's view). */
    $rawRows = static function (int $sid) use ($wpdb, $eventsTable): array {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT event_name, payload_json FROM {$eventsTable} WHERE student_id = %d ORDER BY id ASC",
            $sid
        )) ?: [];
    };

    $created = [];
    $track = static function (?array $student) use (&$created): void {
        if (!empty($student['id'])) { $created[] = (int) $student['id']; }
    };

    try {
        $teacher = $kit->makeStaff($ownSchool, true, $ownClass);
        $teacherName = (string) get_userdata($teacher)->display_name;

        wp_set_current_user($teacher);
        $mine = $service->create($teacher, ['firstName' => 'My', 'lastName' => 'Ly', 'gradeLevel' => 4]);
        $kit->checkSame('fixture learner created', 'created', $mine['status']);
        $track($mine['student'] ?? null);
        $sid = (int) $mine['student']['id'];
        $userId = (int) $mine['student']['userId'];

        // ---- 1. A generated handout issue appends exactly one row -------------
        $r = $service->provisionLogin($teacher, $sid);
        $kit->checkSame('generated handout provisioned', 'provisioned', $r['status']);
        $username = (string) $r['username'];
        $handout = (string) $r['password'];

        $rows = $rawRows($sid);
        $kit->checkSame('issue appends exactly one event', 1, count($rows));
        $kit->checkSame('   named credential_issued', EventRepository::CREDENTIAL_ISSUED, $rows[0]->event_name ?? '');
        $issuePayload = (array) json_decode((string) $rows[0]->payload_json, true);
        $kit->checkSame('   payload records the generated method', 'generated', $issuePayload['method'] ?? '');
        $kit->checkSame('   payload flags a forced change', true, !empty($issuePayload['mustChange']));

        // ---- 2. The ledger must never carry a secret (§8) ----------------------
        $allPayload = implode(' ', array_map(static fn ($row) => (string) $row->payload_json, $rawRows($sid)));
        $kit->check('payload never contains the handout password', strpos($allPayload, $handout) === false);

        // ---- 3. A staff-chosen password issues a DIFFERENT method label --------
        $second = $service->create($teacher, ['firstName' => 'Tai', 'lastName' => 'Ng', 'gradeLevel' => 4]);
        $track($second['student'] ?? null);
        $sid2 = (int) $second['student']['id'];
        $staffTyped = 'GiaoVienDat@123';
        $r2 = $service->provisionLogin($teacher, $sid2, $staffTyped);
        $kit->checkSame('custom-password issue provisioned', 'provisioned', $r2['status']);
        $rows2 = $rawRows($sid2);
        $kit->checkSame('custom issue appends exactly one event', 1, count($rows2));
        $kit->checkSame('   named credential_issued', EventRepository::CREDENTIAL_ISSUED, $rows2[0]->event_name ?? '');
        $kit->checkSame('   payload records the staff method', 'staff', (string) (((array) json_decode((string) $rows2[0]->payload_json, true))['method'] ?? ''));
        $kit->check('   payload never contains the staff-typed password', strpos((string) $rows2[0]->payload_json, $staffTyped) === false);

        // ---- 4. An explicit rotate APPENDS a second row, never edits the first --
        $r3 = $service->provisionLogin($teacher, $sid, '', true);
        $kit->checkSame('rotate provisioned', 'provisioned', $r3['status']);
        $kit->checkSame('rotate reports rotated', true, !empty($r3['rotated']));
        $rotatedNew = (string) $r3['password'];

        $rows = $rawRows($sid);
        $kit->checkSame('rotate appends (never replaces) a row', 2, count($rows));
        $kit->checkSame('   first row is still the original issue', EventRepository::CREDENTIAL_ISSUED, $rows[0]->event_name ?? '');
        $kit->checkSame('   second row is the reset', EventRepository::CREDENTIAL_RESET, $rows[1]->event_name ?? '');
        $kit->check('   reset payload never contains the NEW rotated password', strpos((string) $rows[1]->payload_json, $rotatedNew) === false);

        // ---- 5. The learner's own forced change appends a third row ------------
        wp_set_current_user($userId);
        $newPassword = 'MatKhauRiengCuaCon123';
        $resp = rest_do_request($postJson('/aba/v1/auth/change-password', ['oldPassword' => $rotatedNew, 'newPassword' => $newPassword]));
        $kit->checkSame('self change-password => 204', 204, $resp->get_status());

        $rows = $rawRows($sid);
        $kit->checkSame('self change appends a third row', 3, count($rows));
        $kit->checkSame('   named credential_changed', EventRepository::CREDENTIAL_CHANGED, $rows[2]->event_name ?? '');
        $kit->checkSame('   actor is the learner themself', $userId, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$eventsTable} WHERE student_id = %d AND event_name = %s ORDER BY id DESC LIMIT 1",
            $sid, EventRepository::CREDENTIAL_CHANGED
        )));
        $allPayload = implode(' ', array_map(static fn ($row) => (string) $row->payload_json, $rawRows($sid)));
        $kit->check('   payload never contains the NEW self-chosen password', strpos($allPayload, $newPassword) === false);
        $kit->check('   payload never contains the old handout password', strpos($allPayload, $handout) === false);

        // ---- 6. Service projection: newest first, human actor labels ----------
        $list = $audit->listFor($sid, $userId);
        $kit->checkSame('listFor returns all three events', 3, count($list));
        $kit->checkSame('   newest first is the self change', EventRepository::CREDENTIAL_CHANGED, $list[0]['action'] ?? '');
        $kit->checkSame('   a self row shows "chính bạn" to the learner', 'Chính bạn', $list[0]['actorLabel'] ?? '');
        $kit->checkSame('   the middle row is the reset', EventRepository::CREDENTIAL_RESET, $list[1]['action'] ?? '');
        $kit->checkSame('   a staff row shows the display name, never a raw id', $teacherName, $list[1]['actorLabel'] ?? '');
        $kit->checkSame('   oldest is the original issue', EventRepository::CREDENTIAL_ISSUED, $list[2]['action'] ?? '');

        // ---- 7. REST GET /students/{id}/credential-events is permission-gated --
        wp_set_current_user(0);
        $getEvents = static function (int $id): WP_REST_Response|WP_Error {
            $req = new WP_REST_Request('GET', "/aba/v1/students/{$id}/credential-events");
            return rest_do_request($req);
        };
        $resp = $getEvents($sid);
        $kit->checkSame('anonymous => 401', 401, $resp->get_status());

        $outsiderStaff = $kit->makeStaff(20, true, 501); // a different school entirely
        wp_set_current_user($outsiderStaff);
        $resp = $getEvents($sid);
        $kit->checkSame('unrelated staff => 403', 403, $resp->get_status());

        wp_set_current_user($teacher);
        $resp = $getEvents($sid);
        $kit->checkSame('the scoped teacher => 200', 200, $resp->get_status());
        $body = (array) $resp->get_data();
        $kit->checkSame('   body carries the three events', 3, count($body['events'] ?? []));

        // A linked parent may read the same trail (§8 parent-child relationship).
        $parent = $kit->makeParent('Chị Audit');
        $kit->linkParentStudent($parent, $sid);
        wp_set_current_user($parent);
        $resp = $getEvents($sid);
        $kit->checkSame('linked parent => 200', 200, $resp->get_status());
        $body = (array) $resp->get_data();
        $parentRows = $body['events'] ?? [];
        $kit->checkSame('   parent sees the same three events', 3, count($parentRows));
        $kit->checkSame('   a learner row is labelled by staff name context, not "chính bạn"', $teacherName, $parentRows[1]['actorLabel'] ?? '');
        $serialized = (string) wp_json_encode($body);
        $kit->check('   the REST body never leaks a password', strpos($serialized, $handout) === false && strpos($serialized, $newPassword) === false && strpos($serialized, $rotatedNew) === false);

        // The learner reads their own trail too.
        wp_set_current_user($userId);
        $resp = $getEvents($sid);
        $kit->checkSame('the learner themself => 200', 200, $resp->get_status());

        // A nonexistent id is refused by a scoped caller WITHOUT revealing why
        // (same 403 as "not yours", never a 404 oracle for id enumeration, §8) —
        // but a platform admin, who may legitimately see every id, gets the real
        // 404 (requireViewStudent passes, then the student lookup fails).
        wp_set_current_user($teacher);
        $resp = $getEvents(999999999);
        $kit->checkSame('scoped teacher, unknown id => 403 (no existence oracle)', 403, $resp->get_status());

        $platform = $kit->makeStaff(0, false, 0);
        $platformUser = new \WP_User($platform);
        $platformUser->add_cap('aba_manage_all');
        wp_set_current_user($platform);
        $resp = $getEvents(999999999);
        $kit->checkSame('platform admin, unknown id => 404', 404, $resp->get_status());
    } finally {
        wp_set_current_user(0);
        foreach ($created as $sid) {
            $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}aba_students WHERE id = %d", $sid));
            $kit->cleanup($sid); // also purges this student's aba_events rows
            if ($uid > 0) {
                delete_user_meta($uid, 'aba_credential_issued_at');
                delete_user_meta($uid, 'aba_must_change_password');
                $kit->cleanupUser($uid);
            }
        }
        $kit->cleanupUser($teacher ?? 0);
        $kit->cleanupUser($outsiderStaff ?? 0);
        $kit->cleanupUser($parent ?? 0);
        $kit->cleanupUser($platform ?? 0);
    }
};
