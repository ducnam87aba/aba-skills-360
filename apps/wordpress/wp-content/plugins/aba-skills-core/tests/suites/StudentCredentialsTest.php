<?php
/**
 * Suite: Learner account provisioning + rotation guard (PA46 + PA47, §8).
 *
 * Proves the "activate student account" flow is safe:
 *   - only in-scope staff can provision a learner (same §8 gate as edit);
 *   - an archived learner cannot get a login until reactivated;
 *   - a learner row whose user holds platform privileges is REFUSED — the
 *     roster screen is never a path to take over an administrator account;
 *   - the issued password authenticates for real through /auth/login;
 *   - the plaintext password is handed back exactly once (no READ endpoint),
 *     while the login NAME is safely visible to staff and linked parents.
 *
 * PA47 adds the ROTATION GUARD:
 *   - re-issuing WITHOUT an explicit rotate returns status 'already' (username,
 *     never a password) instead of silently invalidating live credentials;
 *   - an explicit rotate=true DOES replace the old password and reports rotated;
 *   - the credentialIssuedAt marker is surfaced to staff + parent shapes;
 *   - the REST route answers 409 on a plain re-issue, 201 on a confirmed reset.
 *
 * PA48 adds the FORCED-CHANGE FLAG semantics on the issue side:
 *   - a GENERATED handout password stamps aba_must_change_password (the learner
 *     must replace it on first sign-in, enforced by FirstPasswordChangeTest);
 *   - a staff-CHOSEN password counts as already-personal => flag cleared.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\StudentService;
use ABA\Core\Repositories\StudentRepository;

return function (AbaTestKit $kit): void {
    $kit->section('StudentCredentialsTest — one-time provisioning + rotation guard (PA46/PA47)');

    global $wpdb;
    $studentsTable = $wpdb->prefix . 'aba_students';
    $service = new StudentService();
    $repo = new StudentRepository();

    $ownSchool = 10; $ownClass = 402;

    $created = [];
    $track = static function (?array $student) use (&$created): void {
        if (!empty($student['id'])) { $created[] = (int) $student['id']; }
    };

    $postJson = static function (string $method, string $route, array $body): WP_REST_Request {
        $req = new WP_REST_Request($method, $route);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };

    try {
        $teacher = $kit->makeStaff($ownSchool, true, $ownClass);
        // A second teacher in another school/class, whose learner is out of ours.
        $otherTeacher = $kit->makeStaff(20, true, 501);

        wp_set_current_user($teacher);
        $mine = $service->create($teacher, ['firstName' => 'Khoa', 'lastName' => 'Nguyen', 'gradeLevel' => 4]);
        $kit->checkSame('fixture learner created', 'created', $mine['status']);
        $track($mine['student'] ?? null);
        $sid = (int) $mine['student']['id'];
        $userId = (int) $mine['student']['userId'];

        // ---- Input validation happens BEFORE any issue on a fresh login ------
        $r = $service->provisionLogin($teacher, $sid, 'short');
        $kit->checkSame('password under 8 chars => invalid.password', 'invalid', $r['status']);
        $kit->checkSame('   field is password', 'password', $r['field'] ?? null);
        $kit->check('   a rejected issue does NOT stamp the marker', get_user_meta($userId, 'aba_credential_issued_at', true) === '' || get_user_meta($userId, 'aba_credential_issued_at', true) === false);

        // ---- First provision with a generated password -----------------------
        $r = $service->provisionLogin($teacher, $sid);
        $kit->checkSame('teacher provisions own learner => provisioned', 'provisioned', $r['status']);
        $kit->check('   username returned', (string) ($r['username'] ?? '') !== '');
        $kit->check('   generated password is usable length', strlen((string) ($r['password'] ?? '')) >= 8);
        $kit->checkSame('   first issue is NOT a rotation', false, !empty($r['rotated']));
        $user = get_userdata($userId);
        $firstPassword = (string) $r['password'];
        $kit->check('   password REALLY authenticates the wp_user', wp_check_password($firstPassword, $user->user_pass, $userId));
        $kit->check('   issue stamps the credential_issued_at marker', get_user_meta($userId, 'aba_credential_issued_at', true) !== '');
        // PA48 — a generated handout secret must be replaced on first login.
        $kit->checkSame('   generated issue reports mustChangePassword', true, !empty($r['mustChangePassword']));
        $kit->checkSame('   ...and stamps the forced-change flag', '1', get_user_meta($userId, 'aba_must_change_password', true));
        if (get_role('student') !== null) {
            $kit->check('   account moved to the student role', in_array('student', $user->roles, true));
        }

        // ---- The issued pair passes the REAL /auth/login endpoint -----------
        $resp = rest_do_request($postJson('POST', '/aba/v1/auth/login', [
            'username' => (string) $r['username'],
            'password' => $firstPassword,
        ]));
        $kit->checkSame('provisioned pair logs in via /auth/login => 200', 200, $resp->get_status());
        $loginDto = $resp->get_data()['user'] ?? [];
        $kit->checkSame('   DTO resolves the learner link', $sid, (int) ($loginDto['studentId'] ?? 0));
        $kit->checkSame('PA48 — login DTO carries the forced-change flag', true, !empty($loginDto['mustChangePassword']));

        // ---- PA47 rotation guard ---------------------------------------------
        $r = $service->provisionLogin($teacher, $sid);
        $kit->checkSame('re-issue without rotate => already', 'already', $r['status']);
        $kit->check('   already reports the username', (string) ($r['username'] ?? '') !== '');
        $kit->check('   already NEVER carries a password', !array_key_exists('password', $r));
        $kit->check('   the live password still authenticates (unchanged)', wp_check_password($firstPassword, get_userdata($userId)->user_pass, $userId));

        // ---- Explicit rotate replaces the password ---------------------------
        $r = $service->provisionLogin($teacher, $sid, 'XinChao@2026', true);
        $kit->checkSame('explicit rotate => provisioned', 'provisioned', $r['status']);
        $kit->checkSame('   reported as a rotation', true, !empty($r['rotated']));
        $kit->checkSame('   custom password echoed once', 'XinChao@2026', $r['password']);
        $user = get_userdata($userId);
        $kit->check('   new password replaced the old one', wp_check_password('XinChao@2026', $user->user_pass, $userId));
        $kit->check('   the old password no longer authenticates', !wp_check_password($firstPassword, $user->user_pass, $userId));
        // PA48 — a staff-TYPED password is the intended credential, no forced change.
        $kit->checkSame('custom rotate reports mustChangePassword=false', false, !empty($r['mustChangePassword']));
        $kit->check('   ...and clears any stale forced-change flag', get_user_meta($userId, 'aba_must_change_password', true) === '');

        // ---- PA47 legacy-account hazard (pre-marker learner) ----------------
        // A learner provisioned BEFORE the marker existed carries the 'student'
        // role but no aba_credential_issued_at. Deleting the marker simulates
        // that state; the role fallback must still refuse a silent rotation.
        if (get_role('student') !== null && in_array('student', get_userdata($userId)->roles, true)) {
            delete_user_meta($userId, 'aba_credential_issued_at');
            $r = $service->provisionLogin($teacher, $sid);
            $kit->checkSame('pre-PA47 learner (role student, no marker) => already', 'already', $r['status']);
            $kit->check('   ...guarded even with the marker missing', !array_key_exists('password', $r));
            $r = $service->provisionLogin($teacher, $sid, '', true);
            $kit->checkSame('   explicit rotate restores the marker', 'provisioned', $r['status']);
            $kit->checkSame('   ...generated rotate re-arms the forced-change flag', '1', get_user_meta($userId, 'aba_must_change_password', true));
        }

        // ---- credentialIssuedAt + username visible to staff roster + parent ---
        $parent = $kit->makeParent('Credential Watching Parent');
        $kit->linkParentStudent($parent, $sid);
        $kids = $repo->getChildrenForParent($parent);
        $kit->check('parent child shape carries the login NAME', ($kids[0]['username'] ?? null) === get_userdata($userId)->user_login);
        $kit->check('   child shape marks the credential issued', !empty($kids[0]['credentialIssuedAt']));
        $kit->check('   ...and never a password field', !array_key_exists('password', $kids[0] ?? []));
        $ctx = $service->context($teacher);
        $row = null;
        foreach ($ctx['students'] as $st) { if ((int) $st['id'] === $sid) { $row = $st; } }
        $kit->check('staff roster shows username too', ($row['username'] ?? null) === get_userdata($userId)->user_login);
        $kit->check('staff roster shows credentialIssuedAt', !empty($row['credentialIssuedAt']));

        // ---- Scope + state gates --------------------------------------------
        $theirs = $service->create($otherTeacher, ['firstName' => 'Thu', 'lastName' => 'Tran', 'gradeLevel' => 5]);
        $kit->checkSame('other-school fixture created', 'created', $theirs['status']);
        $track($theirs['student'] ?? null);
        $r = $service->provisionLogin($teacher, (int) $theirs['student']['id']);
        $kit->checkSame('provision outside own scope => forbidden', 'forbidden', $r['status']);

        $r = $service->provisionLogin($teacher, 999999999);
        $kit->checkSame('unknown learner => notFound', 'notFound', $r['status']);

        $service->setStatus($teacher, $sid, 'inactive');
        $r = $service->provisionLogin($teacher, $sid, '', true);
        $kit->checkSame('archived learner (even rotate) => invalid.status', 'invalid', $r['status']);
        $kit->checkSame('   field is status', 'status', $r['field'] ?? null);
        $service->setStatus($teacher, $sid, 'active');

        // ---- Privilege-escalation guard: row linked to an administrator -----
        $adminId = wp_insert_user([
            'user_login' => 'aba_test_admin_' . random_int(900000, 999999),
            'user_pass' => wp_generate_password(24, true, true),
            'user_email' => 'pa47admin' . random_int(900000, 999999) . '@example.test',
            'role' => 'administrator',
        ]);
        if (!is_wp_error($adminId)) {
            $adminId = (int) $adminId;
            $ghost = $kit->makeStudent('4_6', $ownSchool, $ownClass); // token user
            $kit->linkStudentUser($ghost, $adminId);
            $r = $service->provisionLogin($teacher, $ghost);
            $kit->checkSame('learner row pointing at an admin => forbidden', 'forbidden', $r['status']);
            $kit->cleanup($ghost);
            require_once ABSPATH . 'wp-admin/includes/user.php'; // CLI context lacks the admin includes
            wp_delete_user($adminId);
        }

        // ---- REST matrix -------------------------------------------------------
        // A brand-new learner so the FIRST issue path can be exercised over HTTP.
        $rest = $service->create($teacher, ['firstName' => 'Lan', 'lastName' => 'Do', 'gradeLevel' => 4]);
        $track($rest['student'] ?? null);
        $restSid = (int) $rest['student']['id'];

        wp_set_current_user(0);
        $resp = rest_do_request($postJson('POST', "/aba/v1/school/students/$restSid/credentials", []));
        $kit->checkSame('anonymous POST credentials => 401', 401, $resp->get_status());

        wp_set_current_user($parent);
        $resp = rest_do_request($postJson('POST', "/aba/v1/school/students/$restSid/credentials", []));
        $kit->checkSame('parent POST credentials => 403', 403, $resp->get_status());

        wp_set_current_user($teacher);
        $resp = rest_do_request($postJson('POST', "/aba/v1/school/students/$restSid/credentials", []));
        $kit->checkSame('teacher first issue => 201', 201, $resp->get_status());
        $data = (array) $resp->get_data();
        $kit->check('   201 body has username + one-time password', !empty($data['username']) && !empty($data['password']));
        $kit->checkSame('PA48 — 201 body announces the forced change', true, !empty($data['mustChangePassword']));

        // PA47 — plain re-issue over HTTP is refused with 409 (+ username, no password).
        $resp = rest_do_request($postJson('POST', "/aba/v1/school/students/$restSid/credentials", []));
        $kit->checkSame('plain re-issue => 409', 409, $resp->get_status());
        $err = (array) $resp->get_data();
        $kit->checkSame('   409 code is ABA_CREDENTIAL_EXISTS', 'ABA_CREDENTIAL_EXISTS', $err['code'] ?? null);
        $kit->check('   409 carries the username, never a password', !empty($err['data']['details']['username']) && empty($err['data']['details']['password']));

        // Confirmed reset (rotate=true) succeeds and reports rotated.
        $resp = rest_do_request($postJson('POST', "/aba/v1/school/students/$restSid/credentials", ['rotate' => true]));
        $kit->checkSame('rotate=true => 201', 201, $resp->get_status());
        $data = (array) $resp->get_data();
        $kit->check('   reset response flagged rotated', !empty($data['rotated']));
        $kit->checkSame('   reset with a generated password re-arms the flag', true, !empty($data['mustChangePassword']));

        $resp = rest_do_request($postJson('POST', '/aba/v1/school/students/999999999/credentials', []));
        $kit->checkSame('unknown id via REST => 404', 404, $resp->get_status());
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
        $kit->cleanupUser($otherTeacher ?? 0);
        $kit->cleanupUser($parent ?? 0);
    }
};
