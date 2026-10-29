<?php
/**
 * Suite: Student roster edit + archive (PA45, AGENTS.md §4/§8/§11).
 *
 * Proves the EDIT path keeps the same server-authoritative rules as create:
 *   - an actor may only touch learners inside their own scope (class staff →
 *     their class, school staff → their school, platform → anywhere);
 *   - a class-pinned teacher sending classId changes nothing (ignored §8),
 *     while school staff may only move learners between classes of their OWN
 *     school and a platform admin follows the class to its school;
 *   - archiving is soft: status flips to inactive, the learner disappears from
 *     findByCode (so no NEW parent link can claim them) but reactivates cleanly
 *     with all data intact (§11 history survives);
 *   - partial updates never blank untouched fields; validation + 404 + the
 *     REST auth matrix behave.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\StudentService;
use ABA\Core\Repositories\StudentRepository;

return function (AbaTestKit $kit): void {
    $kit->section('StudentRosterEditTest — scoped edit + soft archive (PA45)');

    global $wpdb;
    $studentsTable = $wpdb->prefix . 'aba_students';
    $service = new StudentService();
    $repo = new StudentRepository();

    $ownSchool = 10; $ownClass = 402;   // school 10's demo class
    $foreignClass = 501;                 // belongs to school 20, NOT 10

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
        $schoolStaff = $kit->makeStaff($ownSchool, true, 0);
        $admin = $kit->makeStaff(0, false);
        (new \WP_User($admin))->add_cap('aba_manage_all');
        $orphan = $kit->makeStaff(0, true, 0);
        $freshClass = $kit->makeClass($ownSchool, 'TEST-PA45', 'Lớp thử PA45');

        // A learner in ANOTHER school's class, for cross-scope edit attempts.
        $foreignStudentId = $kit->makeStudent('4_6', 20, $foreignClass);

        // ---- Teacher creates their own learners (PA44 path as fixture) ------
        wp_set_current_user($teacher);
        $mine = $service->create($teacher, ['firstName' => 'An', 'lastName' => 'Lê', 'gradeLevel' => 4]);
        $kit->checkSame('fixture learner created', 'created', $mine['status']);
        $track($mine['student'] ?? null);
        $sid = (int) $mine['student']['id'];

        // ---- Partial update: lastName only, firstName must survive ----------
        $r = $service->update($teacher, $sid, ['lastName' => 'Lê Mới']);
        $kit->checkSame('partial update => updated', 'updated', $r['status']);
        $kit->checkSame('   lastName changed', 'Lê Mới', $r['student']['lastName'] ?? null);
        $kit->checkSame('   untouched firstName kept', 'An', $r['student']['firstName'] ?? null);

        // ---- Grade edit recomputes the band (and history keys stay) ---------
        $r = $service->update($teacher, $sid, ['gradeLevel' => 8]);
        $kit->checkSame('grade edit => updated', 'updated', $r['status']);
        $kit->checkSame('   band recomputed to 7_9', '7_9', $r['student']['gradeGroup'] ?? null);
        $r = $service->update($teacher, $sid, ['gradeLevel' => 4]); // back for later checks

        // ---- Class-pinned teacher cannot re-classify (§8: silently ignored) --
        $r = $service->update($teacher, $sid, ['classId' => $freshClass]);
        $kit->checkSame('teacher-only-classId => nothing to update', 'invalid', $r['status']);
        $kit->checkSame('   field is body', 'body', $r['field'] ?? null);
        $r = $service->update($teacher, $sid, ['firstName' => 'Anh', 'classId' => $freshClass]);
        $kit->checkSame('teacher name+classId => updated', 'updated', $r['status']);
        $kit->checkSame('   class NEVER moved (§8)', $ownClass, (int) ($r['student']['classId'] ?? 0));

        // ---- Cross-scope edits are forbidden --------------------------------
        $r = $service->update($teacher, $foreignStudentId, ['firstName' => 'Hack']);
        $kit->checkSame('teacher edits foreign-class learner => forbidden', 'forbidden', $r['status']);
        $r = $service->setStatus($teacher, $foreignStudentId, 'inactive');
        $kit->checkSame('teacher archives foreign learner => forbidden', 'forbidden', $r['status']);
        $r = $service->update($orphan, $sid, ['firstName' => 'X']);
        $kit->checkSame('unassigned staff edit => forbidden', 'forbidden', $r['status']);

        // ---- School staff: edit + move within OWN school only ---------------
        wp_set_current_user($schoolStaff);
        $r = $service->update($schoolStaff, $sid, ['classId' => $freshClass]);
        $kit->checkSame('school staff moves learner in own school => updated', 'updated', $r['status']);
        $kit->checkSame('   class actually changed', $freshClass, (int) ($r['student']['classId'] ?? 0));
        $kit->checkSame('   school unchanged', $ownSchool, (int) ($r['student']['schoolId'] ?? 0));
        $r = $service->update($schoolStaff, $sid, ['classId' => $foreignClass]);
        $kit->checkSame('school staff move to foreign class => invalid.classId', 'invalid', $r['status']);
        $kit->checkSame('   field is classId', 'classId', $r['field'] ?? null);
        $r = $service->update($schoolStaff, $foreignStudentId, ['firstName' => 'No']);
        $kit->checkSame('school staff edits other-school learner => forbidden', 'forbidden', $r['status']);

        // ---- Platform admin: the class decides the school -------------------
        wp_set_current_user($admin);
        $r = $service->update($admin, $sid, ['classId' => $foreignClass]);
        $kit->checkSame('platform move cross-school => updated', 'updated', $r['status']);
        $kit->checkSame('   school follows the class', 20, (int) ($r['student']['schoolId'] ?? 0));
        $r = $service->update($admin, $sid, ['classId' => 999999]);
        $kit->checkSame('platform unknown class => invalid.classId', 'invalid', $r['status']);
        // Put it back for the remaining scope checks.
        $service->update($admin, $sid, ['classId' => $ownClass]);

        // ---- Validation + not-found -----------------------------------------
        $r = $service->update($teacher, $sid, ['firstName' => '  ']);
        $kit->checkSame('blank merged name => invalid.name', 'invalid', $r['status']);
        $r = $service->update($teacher, $sid, ['gradeLevel' => 13]);
        $kit->checkSame('grade 13 => invalid.gradeLevel', 'invalid', $r['status']);
        $r = $service->update($teacher, 999999999, ['firstName' => 'Ghost']);
        $kit->checkSame('unknown student => notFound', 'notFound', $r['status']);
        $r = $service->setStatus($teacher, $sid, 'archived');
        $kit->checkSame('status enum enforced => invalid', 'invalid', $r['status']);

        // ---- Soft archive: invisible to findByCode, reactivates intact ------
        $code = (string) $mine['student']['studentCode'];
        $kit->check('archiving BEFORE: code resolvable', $repo->findByCode($code) !== null);
        $r = $service->setStatus($teacher, $sid, 'inactive');
        $kit->checkSame('teacher archives own learner => updated', 'updated', $r['status']);
        $kit->checkSame('   status now inactive', 'inactive', $r['student']['status'] ?? null);
        $kit->check('AFTER archive: code NOT claimable by link requests', $repo->findByCode($code) === null);
        // §11: the row itself survives with its data.
        $still = $repo->findById($sid);
        $kit->check('archived row preserved (soft, history intact)', $still !== null && (int) $still['id'] === $sid);
        $r = $service->setStatus($teacher, $sid, 'active');
        $kit->checkSame('reactivate => active', 'active', $r['student']['status'] ?? null);
        $kit->check('reactivated code resolvable again', $repo->findByCode($code) !== null);

        // ---- Context now surfaces archived learners to staff ---------------
        $service->setStatus($teacher, $sid, 'inactive');
        $ctx = $service->context($teacher);
        $ids = array_map(static fn($st) => (int) $st['id'], $ctx['students']);
        $kit->check('staff roster includes archived learner (for reactivation)', in_array($sid, $ids, true));
        $ctxP = $service->context($admin, ['schoolId' => $ownSchool]);
        $idsP = array_map(static fn($st) => (int) $st['id'], $ctxP['students']);
        $kit->check('platform context?schoolId lists that roster', in_array($sid, $idsP, true));
        $service->setStatus($teacher, $sid, 'active');

        // ---- REST matrix -----------------------------------------------------
        wp_set_current_user(0);
        $resp = rest_do_request($postJson('PUT', "/aba/v1/school/students/$sid", ['firstName' => 'X']));
        $kit->checkSame('anonymous PUT => 401', 401, $resp->get_status());
        $resp = rest_do_request($postJson('POST', "/aba/v1/school/students/$sid/status", ['status' => 'inactive']));
        $kit->checkSame('anonymous POST status => 401', 401, $resp->get_status());

        $parent = $kit->makeParent('No Edit Parent');
        wp_set_current_user($parent);
        $resp = rest_do_request($postJson('PUT', "/aba/v1/school/students/$sid", ['firstName' => 'X']));
        $kit->checkSame('parent PUT => 403', 403, $resp->get_status());

        wp_set_current_user($teacher);
        $resp = rest_do_request($postJson('PUT', "/aba/v1/school/students/$sid", ['firstName' => 'Qua REST']));
        $kit->checkSame('teacher PUT own learner => 200', 200, $resp->get_status());
        $kit->checkSame('   name applied', 'Qua REST', $resp->get_data()['firstName'] ?? null);
        $resp = rest_do_request($postJson('PUT', '/aba/v1/school/students/999999999', ['firstName' => 'Ghost']));
        $kit->checkSame('PUT unknown id => 404', 404, $resp->get_status());
        $resp = rest_do_request($postJson('POST', "/aba/v1/school/students/$sid/status", ['status' => 'inactive']));
        $kit->checkSame('teacher POST status => 200', 200, $resp->get_status());
        $kit->checkSame('   status flipped', 'inactive', $resp->get_data()['status'] ?? null);
        $resp = rest_do_request($postJson('POST', "/aba/v1/school/students/$sid/status", ['status' => 'nonsense']));
        $kit->checkSame('bad status enum via REST => 400', 400, $resp->get_status());
    } finally {
        wp_set_current_user(0);
        foreach ($created as $sid) {
            $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$studentsTable} WHERE id = %d", $sid));
            $kit->cleanup($sid);
            if ($uid > 0) { $kit->cleanupUser($uid); }
        }
        $kit->cleanup($foreignStudentId ?? 0);
        if (!empty($freshClass)) {
            $wpdb->delete($wpdb->prefix . 'aba_classes', ['id' => $freshClass], ['%d']);
        }
        $kit->cleanupUser($teacher ?? 0);
        $kit->cleanupUser($schoolStaff ?? 0);
        $kit->cleanupUser($admin ?? 0);
        $kit->cleanupUser($orphan ?? 0);
        $kit->cleanupUser($parent ?? 0);
    }
};
