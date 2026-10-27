<?php
/**
 * Suite: Parent link-request queue (PA42, AGENTS.md §7/§8).
 *
 * Proves the "add a child" flow is request-then-approve, never self-service:
 * a submission only writes to the queue; the parent_student access row appears
 * solely after an administrator approval. Covers the service outcomes
 * (not_found / created / exists / already_linked / rejected-then-reopened),
 * idempotency of double submits, review immutability, and the REST layer:
 * 401 anonymous, 201 for the parent, 400 on malformed codes, per-parent queue
 * scoping, and the §8 approval gate — a roster-cap teacher is refused while a
 * platform user succeeds, and no one can approve their own request.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Auth\Authorization;
use ABA\Core\Repositories\ParentLinkRequestRepository;
use ABA\Core\Services\ParentLinkRequestService;

return function (AbaTestKit $kit): void {
    $kit->section('ParentLinkRequestTest — request/approve queue for parent links (PA42)');

    global $wpdb;
    $table = $wpdb->prefix . 'aba_parent_link_requests';

    // Migration v7 must have run (plugins_loaded on bootstrap).
    $kit->check('v7 table aba_parent_link_requests exists',
        $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== null);

    // Two throwaway learners (student_code is 'TEST-<token>'; read it back).
    $s1 = $kit->makeStudent('4_6', 1);
    $s2 = $kit->makeStudent('4_6', 1);
    $code1 = (string) $wpdb->get_var($wpdb->prepare('SELECT student_code FROM ' . $wpdb->prefix . 'aba_students WHERE id = %d', $s1));
    $code2 = (string) $wpdb->get_var($wpdb->prepare('SELECT student_code FROM ' . $wpdb->prefix . 'aba_students WHERE id = %d', $s2));

    $parent  = $kit->makeParent('Requesting Parent');
    $other   = $kit->makeParent('Other Parent');
    // Roster staff: aba_manage_students only — must NOT be able to approve.
    $teacher = $kit->makeStaff(1, true);
    // Platform reviewer: subscriber + aba_manage_all (capability, not role).
    $admin = $kit->makeStaff(1, false);
    (new \WP_User($admin))->add_cap('aba_manage_all');

    $service = new ParentLinkRequestService();
    $repo = new ParentLinkRequestRepository();

    // Existing suites' JSON-body pattern — this WP build has no set_params().
    $postJson = static function (string $method, string $route, array $body): WP_REST_Request {
        $req = new WP_REST_Request($method, $route);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };

    try {
        // ---- Service outcomes ----------------------------------------------
        $r = $service->submit($parent, 'NO-SUCH-CODE-999', 'mother');
        $kit->checkSame('unknown code => not_found', 'not_found', $r['status']);

        wp_set_current_user($admin);
        $r = $service->submit($parent, '  ' . strtolower($code1) . '  ', 'mother');
        $kit->checkSame('valid code => created', 'created', $r['status']);
        $kit->checkSame('request carries the upper-cased code', $code1, $r['request']['studentCode'] ?? null);
        $reqId = (int) ($r['request']['id'] ?? 0);
        $kit->check('created row is pending in the DB',
            'pending' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $reqId)));

        // Whitespace/case-insensitive dedupe: the same submission reuses the row.
        $r = $service->submit($parent, $code1, 'father');
        $kit->checkSame('duplicate submit => exists (no second row)', 'exists', $r['status']);
        $kit->checkSame('duplicate keeps the same id', $reqId, (int) ($r['request']['id'] ?? 0));
        $rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE parent_user_id = %d AND student_id = %d", $parent, $s1));
        $kit->checkSame('queue holds exactly one row for the pair', 1, $rows);

        // Already linked => nothing to request.
        $kit->linkParentStudent($other, $s2);
        $r = $service->submit($other, $code2, 'guardian');
        $kit->checkSame('active link => already_linked', 'already_linked', $r['status']);

        // ---- Approval is the only access grant ------------------------------
        $approved = $service->approve($reqId);
        $kit->checkSame('approve returns approved status', 'approved', $approved['status'] ?? null);
        $kit->check('approval minted the parent_student row', $repo->hasActiveLink($parent, $s1));
        $kit->check('isParentOf now true (server-side §8)', Authorization::isParentOf($parent, $s1));
        $kit->check('reviewed_by recorded', (int) $admin === (int) $wpdb->get_var(
            $wpdb->prepare("SELECT reviewed_by FROM {$table} WHERE id = %d", $reqId)));

        // Reviewed requests are immutable history.
        $kit->check('double approve returns null', $service->approve($reqId) === null);

        // ---- Reject then re-open --------------------------------------------
        wp_set_current_user($admin);
        $r = $service->submit($other, $code1, 'mother');
        $req2 = (int) ($r['request']['id'] ?? 0);
        $service->reject($req2);
        $kit->check('rejected request is not pending',
            'rejected' === $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $req2)));
        $kit->check('rejected grant never applied', !$repo->hasActiveLink($other, $s1));
        $r = $service->submit($other, $code1, 'mother');
        $kit->checkSame('re-submit after reject re-opens as created', 'created', $r['status']);
        $kit->checkSame('re-open reuses the same row', $req2, (int) ($r['request']['id'] ?? 0));

        // ---- REST: transport + authorization --------------------------------
        wp_set_current_user(0);
        $resp = rest_do_request($postJson('POST', '/aba/v1/parent/link-requests', ['studentCode' => $code2, 'relationship' => 'mother']));
        $kit->checkSame('anonymous POST => 401', 401, $resp->get_status());
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/parent/link-requests'));
        $kit->checkSame('anonymous GET => 401', 401, $resp->get_status());

        wp_set_current_user($parent);
        $resp = rest_do_request($postJson('POST', '/aba/v1/parent/link-requests', ['studentCode' => $code2, 'relationship' => 'mother']));
        $kit->checkSame('parent POST new pair => 201', 201, $resp->get_status());
        $kit->checkSame('created envelope status', 'created', $resp->get_data()['status'] ?? null);

        $resp = rest_do_request($postJson('POST', '/aba/v1/parent/link-requests', ['studentCode' => 'GARBAGE-!!@#$$$', 'relationship' => 'mother']));
        $kit->checkSame('malformed code rejected by args schema => 400', 400, $resp->get_status());

        $resp = rest_do_request($postJson('POST', '/aba/v1/parent/link-requests', ['studentCode' => 'ABA-9999-9999', 'relationship' => 'mother']));
        $kit->checkSame('unknown-but-valid code => 404', 404, $resp->get_status());

        // Per-parent queue scoping (§8): the other parent never sees the first's rows.
        wp_set_current_user($teacher);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/parent/link-requests'));
        $kit->checkSame('teacher (no parent cap) GET => 403', 403, $resp->get_status());
        wp_set_current_user($other);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/parent/link-requests'));
        $codes = array_column($resp->get_data(), 'studentCode');
        $kit->checkSame('second parent sees only their own queue', 1, count($codes));
        $kit->check('first parent rows are invisible', !in_array($code2, $codes, true));

        // Approval gate: roster cap alone is NOT enough; the requester is refused;
        // only a platform user passes.
        $pendingId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE parent_user_id = %d AND student_id = %d", $other, $s1));
        $route = '/aba/v1/parent/link-requests/' . $pendingId . '/approve';
        wp_set_current_user($teacher);
        $resp = rest_do_request(new WP_REST_Request('POST', $route));
        $kit->checkSame('roster teacher approve => 403', 403, $resp->get_status());
        wp_set_current_user($other);
        $resp = rest_do_request(new WP_REST_Request('POST', $route));
        $kit->checkSame('self-approval by the requester => 403', 403, $resp->get_status());
        wp_set_current_user($admin);
        $resp = rest_do_request(new WP_REST_Request('POST', $route));
        $kit->checkSame('platform admin approve => 200', 200, $resp->get_status());
        $kit->check('REST approval granted access', Authorization::isParentOf($other, $s1));

        // Queue rows never leak the learner's identity before approval (§8):
        // shape carries the code the parent typed, nothing more.
        wp_set_current_user($parent);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/parent/link-requests'));
        $row = $resp->get_data()[0] ?? [];
        $kit->check('row omits studentId', !array_key_exists('studentId', $row));
        $kit->check('row omits any learner name', !array_key_exists('fullName', $row) && !array_key_exists('studentName', $row));

        // ---- PA43: administrator review queue (cross-parent, REST-gated) -----
        // Listing every family's requests is the widest read in the flow, so the
        // §8 gate must be platform-user-only: anon/parent/roster-teacher refused.
        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/admin/parent-link-requests'));
        $kit->checkSame('anonymous admin-list GET => 401', 401, $resp->get_status());
        wp_set_current_user($parent);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/admin/parent-link-requests'));
        $kit->checkSame('parent (no platform cap) admin-list GET => 403', 403, $resp->get_status());
        wp_set_current_user($teacher);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/admin/parent-link-requests'));
        $kit->checkSame('roster teacher admin-list GET => 403', 403, $resp->get_status());

        // The reviewer's queue carries BOTH sides so a human can judge the claim.
        wp_set_current_user($admin);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/admin/parent-link-requests'));
        $kit->checkSame('platform admin admin-list GET => 200', 200, $resp->get_status());
        $pending = $resp->get_data();
        $pendCodes = array_column($pending, 'studentCode');
        // parent<->s2 was REST-created and never reviewed => still pending here.
        $kit->check('pending queue lists the live request', in_array($code2, $pendCodes, true));
        $kit->check('already-approved row has left the pending queue', !in_array($code1, $pendCodes, true));
        $target = null;
        foreach ($pending as $line) {
            if (($line['studentCode'] ?? '') === $code2) { $target = $line; break; }
        }
        $kit->check('admin row names the requesting parent', !empty($target['parentName']));
        $kit->check('admin row names the learner under review', array_key_exists('studentName', (array) $target));

        // status filter: 'all' also surfaces the reviewed history.
        $allReq = new WP_REST_Request('GET', '/aba/v1/admin/parent-link-requests');
        $allReq->set_param('status', 'all');
        $resp = rest_do_request($allReq);
        $kit->checkSame('status=all admin-list GET => 200', 200, $resp->get_status());
        $kit->check('status=all includes approved history', in_array('approved', array_column($resp->get_data(), 'status'), true));

        // A bad status fails the args schema rather than reaching the query.
        $badReq = new WP_REST_Request('GET', '/aba/v1/admin/parent-link-requests');
        $badReq->set_param('status', 'everything');
        $resp = rest_do_request($badReq);
        $kit->checkSame('invalid status => 400', 400, $resp->get_status());
    } finally {
        wp_set_current_user(0);
        $kit->cleanup($s1);
        $kit->cleanup($s2);
        $kit->cleanupUser($parent);
        $kit->cleanupUser($other);
        $kit->cleanupUser($teacher);
        $kit->cleanupUser($admin);
    }
};
