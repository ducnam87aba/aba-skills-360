<?php
/**
 * Report persistence (wp_aba_reports). A generated report becomes an immutable
 * snapshot row so parents get a longitudinal history instead of only a value
 * recomputed "as of now" (AGENTS.md §11). Each generate appends a new row; past
 * reports are never mutated. The composed payload is stored verbatim in
 * data_json and re-issued with its durable id + created_at timestamp on read.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class ReportRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_reports';
    }

    /** Persist a report snapshot. Returns the new row id, or 0 on failure. */
    public function create(int $studentId, string $reportType, string $title, array $data): int {
        global $wpdb;
        $now = current_time('mysql', 1);
        $inserted = $wpdb->insert(
            $this->table,
            [
                'student_id'  => $studentId,
                'report_type' => $reportType,
                'title'       => $title,
                'data_json'   => wp_json_encode($data),
                'created_at'  => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
        return $inserted === false ? 0 : (int)$wpdb->insert_id;
    }

    /** @return array<int,array> stored reports, newest first (stable snapshot ids). */
    public function listForStudent(int $studentId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, student_id, report_type, title, data_json, created_at
             FROM {$this->table} WHERE student_id = %d
             ORDER BY created_at DESC, id DESC",
            $studentId
        ), ARRAY_A) ?: [];
        return array_map([$this, 'toReport'], $rows);
    }

    /** @return array<int,array> ids only, newest first (for change detection). */
    public function idsForStudent(int $studentId): array {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE student_id = %d ORDER BY created_at DESC, id DESC",
            $studentId
        )) ?: []);
    }

    /** @return array<int,array> how many snapshots the learner has, by type. */
    public function countForStudent(int $studentId): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE student_id = %d",
            $studentId
        ));
    }

    /** Rebuild the report payload, stamping the durable row identity onto it. */
    private function toReport(array $row): array {
        $payload = json_decode((string)$row['data_json'], true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['id'] = (int)$row['id'];
        $payload['studentId'] = (int)$row['student_id'];
        $payload['reportType'] = (string)$row['report_type'];
        $payload['title'] = (string)$row['title'];
        $payload['generatedAt'] = $this->toIso((string)$row['created_at']);
        return $payload;
    }

    /** GMT datetime from the DB => the contract's ISO-8601 (Z) instant. */
    private function toIso(string $mysqlDatetime): string {
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : $mysqlDatetime;
    }
}
