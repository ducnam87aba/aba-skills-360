<?php
/**
 * Suite: Parent Report persistence — immutable snapshots + longitudinal history
 * (PA20).
 *
 * Proves a generated report becomes a durable row in wp_aba_reports (its own id
 * + created_at, not the student id), that repeated generations APPEND history
 * instead of overwriting (AGENTS.md §11), that the parent read path is backward
 * compatible (a learner with no snapshots still gets a live composition), and
 * that the composed payload survives the JSON round trip.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\ReportRepository;
use ABA\Core\Services\ReportService;

return function (AbaTestKit $kit): void {
    $kit->section('ReportTest — persisted report snapshots + longitudinal history (PA20)');

    $studentId = $kit->makeStudent('4_6');
    $repo = new ReportRepository();
    $service = new ReportService();

    try {
        // Compose works with no measurement yet (insufficient evidence => null
        // overall, never a fabricated zero — §45).
        $composed = $service->compose($studentId);
        $kit->check('compose returns a report payload', is_array($composed) && isset($composed['title'], $composed['summary'], $composed['recommendations']));

        // No snapshot stored yet => listOrCompose falls back to a single live
        // composition keyed by the student id (backward compatible behaviour).
        $first = $service->listOrCompose($studentId);
        $kit->checkSame('a learner with no snapshots gets one live composition', 1, count($first));
        $kit->checkSame('transient report id mirrors the student (no durable row)', $studentId, $first[0]['id'] ?? null);

        // Generate persists an immutable snapshot and returns its durable id.
        $gen = $service->generate($studentId);
        $kit->check('generate returns a durable row id', is_int($gen['id'] ?? null) && $gen['id'] > 0);
        $genId = (int)($gen['id'] ?? 0);
        $kit->checkSame('generate wrote the snapshot to the store', 1, $repo->countForStudent($studentId));

        // Once persisted, history is served from storage with the row's own id.
        $after = $service->listOrCompose($studentId);
        $kit->checkSame('history now served from storage', 1, count($after));
        $kit->checkSame('stored report carries its own row id', $genId, $after[0]['id'] ?? null);
        $kit->checkSame('stored report keeps the snapshot title', $gen['title'], $after[0]['title'] ?? null);
        $kit->check('stored report has an ISO-8601 generatedAt', (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($after[0]['generatedAt'] ?? '')));

        // Longitudinal history (§11): a second generation appends, never overwrites.
        $gen2 = $service->generate($studentId);
        $kit->check('a second snapshot gets a new id', (int)($gen2['id'] ?? 0) !== $genId);
        $kit->checkSame('history grows to two entries', 2, count($service->listOrCompose($studentId)));
        $kit->checkSame('both snapshots are persisted', 2, $repo->countForStudent($studentId));

        // Newest-first so the parent always sees the latest report on top.
        $list = $service->listOrCompose($studentId);
        $kit->check('reports are listed newest-first', (int)$list[0]['id'] > (int)$list[1]['id']);

        // find() resolves one snapshot scoped to the learner; a foreign id misses.
        $one = $service->find($genId, $studentId);
        $kit->checkSame('find returns the requested snapshot', $genId, $one['id'] ?? null);
        $kit->check('find of an unknown id returns null', $service->find(999999999, $studentId) === null);
        $kit->check('recommendations survive the JSON round trip', is_array($one['recommendations'] ?? null));
    } finally {
        $kit->cleanup($studentId);
    }
};
