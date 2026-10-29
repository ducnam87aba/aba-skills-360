<?php
/**
 * Suite: Student roster self-service (PA44, AGENTS.md §4/§8).
 *
 * Proves the create-a-learner flow keeps the server authoritative:
 *   - a class-assigned staff is PINNED to their own class — a forged schoolId/
 *     classId in the request is ignored, so no teacher can dump a learner into
 *     another school;
 *   - only a platform administrator may name a destination, and only a school
 *     that exists (with a class that belongs to it);
 *   - an unassigned staff holding the roster cap is refused (never school 0);
 *   - validation / duplicate-code / grade-band mapping behave;
 *   - the REST layer enforces requireRoster on both read (context) and write.
 *
 * Every learner the service creates anchors a fresh wp_user, so cleanup removes
 * both the student row and its user (zero residue across runs).
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\StudentService;

return function (AbaTestKit $kit): void {
    $kit->section('StudentRosterTest — server-scoped learner creation (PA44)');

    global $wpdb;
    $studentsTable = $wpdb->prefix . 'aba_students';
    $service = new StudentService();

    // Demo schools/classes guaranteed by SchoolSeeder/DemoUserSeeder.
    $ownSchool = 10; $ownClass = 402;   // school 10's class
    $foreignClass = 501;                 // belongs to school 20, NOT 10

    // Track every (studentId) we create so finally can remove the row + its user.
    $created = [];
    $track = static function (?array $student) use (&$created): void {
        if (!empty($student['id'])) { $created[] = (int) $student['id']; }
    };

    // Existing-suite JSON-body pattern — this WP build has no set_params().
    $postJson = static function (string $method, string $route, array $body): WP_REST_Request {
        $req = new WP_REST_Request($method, $route);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };

    try {
        // ---- Class-assigned staff: pinned to own class, forged scope ignored ----
        $teacher = $kit->makeStaff($ownSchool, true, $ownClass);
        wp_set_current_user($teacher);
        $r = $service->create($teacher, [
            'firstName' => 'An', 'lastName' => 'Lê', 'gradeLevel' => 4,
            'schoolId' => 999, 'classId' => $foreignClass, // deliberate overrides
        ]);
        $kit->checkSame('class staff create => created', 'created', $r['status']);
        $kit->checkSame('created learner forced into the caller\'s class (§8)', $ownClass, (int) ($r['student']['classId'] ?? 0));
        $kit->checkSame('forged schoolId ignored, stays in caller\'s school (§8)', $ownSchool, (int) ($r['student']['schoolId'] ?? 0));
        $kit->check('generated student code present', preg_match('/^ABA-\d{4}-\d{3,}$/', (string) ($r['student']['studentCode'] ?? '')) === 1);
        $kit->checkSame('grade 4 maps to the 4_6 band', '4_6', $r['student']['gradeGroup'] ?? null);
        $track($r['student'] ?? null);

        // ---- School-assigned staff (no class): creates at school scope, class null --
        $schoolStaff = $kit->makeStaff($ownSchool, true, 0);
        wp_set_current_user($schoolStaff);
        $r = $service->create($schoolStaff, ['firstName' => 'Bình', 'lastName' => 'Trần', 'gradeLevel' => 8]);
        $kit->checkSame('school staff create => created', 'created', $r['status']);
        $kit->checkSame('school staff lands in own school', $ownSchool, (int) ($r['student']['schoolId'] ?? 0));
        $kit->checkSame('school staff (class-less) creates with no class', 0, (int) ($r['student']['classId'] ?? 0));
        $kit->checkSame('grade 8 maps to the 7_9 band', '7_9', $r['student']['gradeGroup'] ?? null);
        $track($r['student'] ?? null);

        // ---- Platform admin: may name a valid destination, validated ---------
        $admin = $kit->makeStaff(0, false);
        (new \WP_User($admin))->add_cap('aba_manage_all');
        wp_set_current_user($admin);
        $r = $service->create($admin, ['firstName' => 'Cường', 'lastName' => 'Phạm', 'gradeLevel' => 4, 'schoolId' => $ownSchool, 'classId' => $ownClass]);
        $kit->checkSame('platform admin create into chosen class => created', 'created', $r['status']);
        $kit->checkSame('admin-chosen class honoured', $ownClass, (int) ($r['student']['classId'] ?? 0));
        $track($r['student'] ?? null);

        $r = $service->create($admin, ['firstName' => 'D', 'lastName' => 'E', 'gradeLevel' => 4, 'schoolId' => 999999]);
        $kit->checkSame('admin unknown school => invalid.schoolId', 'invalid', $r['status']);
        $kit->checkSame('   field is schoolId', 'schoolId', $r['field'] ?? null);
        $r = $service->create($admin, ['firstName' => 'D', 'lastName' => 'E', 'gradeLevel' => 4, 'schoolId' => $ownSchool, 'classId' => $foreignClass]);
        $kit->checkSame('admin class not in school => invalid.classId', 'invalid', $r['status']);
        $kit->checkSame('   field is classId', 'classId', $r['field'] ?? null);

        // ---- Unassigned staff: roster cap but no school => refused (§8) ------
        $orphan = $kit->makeStaff(0, true, 0);
        wp_set_current_user($orphan);
        $r = $service->create($orphan, ['firstName' => 'F', 'lastName' => 'G', 'gradeLevel' => 4]);
        $kit->checkSame('unassigned staff => forbidden', 'forbidden', $r['status']);

        // ---- Validation + duplicate code ------------------------------------
        wp_set_current_user($teacher);
        $r = $service->create($teacher, ['firstName' => '', 'lastName' => '', 'gradeLevel' => 4]);
        $kit->checkSame('empty names => invalid.name', 'invalid', $r['status']);
        $r = $service->create($teacher, ['firstName' => 'H', 'lastName' => 'I', 'gradeLevel' => 0]);
        $kit->checkSame('grade 0 => invalid.gradeLevel', 'invalid', $r['status']);
        $r = $service->create($teacher, ['firstName' => 'H', 'lastName' => 'I', 'gradeLevel' => 4, 'studentCode' => 'BAD CODE!!']);
        $kit->checkSame('malformed explicit code => invalid.studentCode', 'invalid', $r['status']);
        $r = $service->create($teacher, ['firstName' => 'H', 'lastName' => 'I', 'gradeLevel' => 4, 'studentCode' => 'ABA-2026-101']);
        $kit->checkSame('already-taken code => conflict', 'conflict', $r['status']);

        // ---- REST: entry-point authorization + happy path --------------------
        wp_set_current_user(0);
        $resp = rest_do_request($postJson('POST', '/aba/v1/school/students', ['firstName' => 'X', 'lastName' => 'Y', 'gradeLevel' => 4]));
        $kit->checkSame('anonymous POST /school/students => 401', 401, $resp->get_status());
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/context'));
        $kit->checkSame('anonymous GET /school/context => 401', 401, $resp->get_status());

        $parent = $kit->makeParent('No Roster Parent');
        wp_set_current_user($parent);
        $resp = rest_do_request($postJson('POST', '/aba/v1/school/students', ['firstName' => 'X', 'lastName' => 'Y', 'gradeLevel' => 4]));
        $kit->checkSame('parent (no roster cap) POST => 403', 403, $resp->get_status());

        wp_set_current_user($teacher);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/school/context'));
        $kit->checkSame('teacher GET /school/context => 200', 200, $resp->get_status());
        $kit->checkSame('   context scope is class', 'class', $resp->get_data()['scope'] ?? null);
        $kit->checkSame('   context classId matches assignment', $ownClass, (int) ($resp->get_data()['classId'] ?? 0));

        $resp = rest_do_request($postJson('POST', '/aba/v1/school/students', ['firstName' => 'Em', 'lastName' => 'Đỗ', 'gradeLevel' => 5]));
        $kit->checkSame('teacher POST create => 201', 201, $resp->get_status());
        $data = $resp->get_data();
        $kit->checkSame('   REST create lands in caller\'s school (§8)', $ownSchool, (int) ($data['schoolId'] ?? 0));
        $track(is_array($data) ? $data : null);

        // Missing a required arg is rejected by the args schema before the service.
        $resp = rest_do_request($postJson('POST', '/aba/v1/school/students', ['lastName' => 'Only', 'gradeLevel' => 4]));
        $kit->checkSame('missing firstName => 400', 400, $resp->get_status());
    } finally {
        wp_set_current_user(0);
        foreach ($created as $sid) {
            $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$studentsTable} WHERE id = %d", $sid));
            $kit->cleanup($sid);
            if ($uid > 0) { $kit->cleanupUser($uid); }
        }
        $kit->cleanupUser($teacher ?? 0);
        $kit->cleanupUser($schoolStaff ?? 0);
        $kit->cleanupUser($admin ?? 0);
        $kit->cleanupUser($orphan ?? 0);
        $kit->cleanupUser($parent ?? 0);
    }
};
