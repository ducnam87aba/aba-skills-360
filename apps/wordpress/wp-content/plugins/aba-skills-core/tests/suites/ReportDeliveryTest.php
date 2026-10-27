<?php
/**
 * Suite: Report delivery + read receipts (PA21).
 *
 * Proves delivery state is *derived* from the append-only event ledger rather than
 * stored as a mutable flag (AGENTS.md §12): generating a report logs it as
 * delivered-but-unread; acknowledging a read appends a receipt; re-opening counts
 * as more views while the first-read moment stays fixed (§11). The last checks
 * drive the shipped detail + read routes through the real REST stack.
 *
 * Returns a closure so run_all.php to feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Services\ReportService;

return function (AbaTestKit $kit): void {
    $kit->section('ReportDeliveryTest — event-derived read receipts (PA21, §12)');

    $studentId = $kit->makeStudent('4_6');
    $service   = new ReportService();
    $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    try {
        // ---- Generate = delivered, not yet read ----------------------------
        $gen = $service->generate($studentId);
        $reportId = (int)($gen['id'] ?? 0);
        $kit->check('generate returns a durable report id', $reportId > 0);

        $fresh = $service->deliveryStatus($studentId, $reportId);
        $kit->checkSame('a generated report is delivered', true, $fresh['delivered'] ?? null);
        $kit->checkSame('a fresh report is not yet read', false, $fresh['read'] ?? null);
        $kit->checkSame('a fresh report has zero views', 0, $fresh['views'] ?? null);

        // ---- First acknowledgement creates the receipt ----------------------
        $after1 = $service->markRead($studentId, $reportId, null);
        $kit->checkSame('marking read flips read', true, $after1['read'] ?? null);
        $kit->checkSame('first read => one view', 1, $after1['views'] ?? null);
        $kit->check('firstReadAt is an ISO instant', (bool)preg_match($iso, (string)($after1['firstReadAt'] ?? '')));
        $firstRead = $after1['firstReadAt'] ?? null;

        // ---- Re-opening counts engagement, never rewrites the first read ----
        $after2 = $service->markRead($studentId, $reportId, null);
        $kit->checkSame('a second open accumulates a view', 2, $after2['views'] ?? null);
        $kit->checkSame('firstReadAt stays the earliest', $firstRead, $after2['firstReadAt'] ?? null);

        // The detail view carries the derived status (read reflects the ledger).
        $detail = $service->find($reportId, $studentId);
        $kit->checkSame('find() stamps the delivery status', true, $detail['delivery']['read'] ?? null);

        // An unknown report has no receipts.
        $unknown = $service->deliveryStatus($studentId, 999999999);
        $kit->checkSame('an unknown report is not read', false, $unknown['read'] ?? null);
        $kit->checkSame('an unknown report has no views', 0, $unknown['views'] ?? null);

        // ---- REST stack: the detail + read routes --------------------------
        $userId = $kit->makeUser($studentId);   // subscriber linked => own student
        wp_set_current_user($userId);

        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $studentId . '/reports/' . $reportId));
        $kit->checkSame('GET report detail => 200', 200, $resp->get_status());
        $kit->check('report detail exposes delivery', isset($resp->get_data()['delivery']));

        $resp = rest_do_request(new WP_REST_Request('POST', '/aba/v1/students/' . $studentId . '/reports/' . $reportId . '/read'));
        $kit->checkSame('POST report read => 200', 200, $resp->get_status());
        $data = $resp->get_data();
        $kit->checkSame('route records the read', true, $data['delivery']['read'] ?? null);
        $kit->check('route reports accumulated views', (int)($data['delivery']['views'] ?? 0) >= 3);

        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $studentId . '/reports/999999999'));
        $kit->checkSame('unknown report detail => 404', 404, $resp->get_status());

        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('POST', '/aba/v1/students/' . $studentId . '/reports/' . $reportId . '/read'));
        $kit->checkSame('anonymous read acknowledgement => 401', 401, $resp->get_status());
    } finally {
        wp_set_current_user(0);
        $kit->cleanupUser($userId ?? 0);
        $kit->cleanup($studentId);
    }
};
