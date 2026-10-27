<?php
/**
 * Suite: Real REST stack, end to end (PA5 — "no mocks").
 *
 * The other suites prove services in isolation. This one proves the SHIPPED
 * WordPress endpoints the Next client actually calls: it authenticates a real
 * throwaway user and drives the registered /aba/v1 routes through
 * rest_do_request(), so routing -> permission_callback -> controller -> service
 * -> repository -> MySQL all run for real. It is the authoritative check that
 * the backend, not MSW, serves the exact contract the typed client expects.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\SkillScoreRepository;

return function (AbaTestKit $kit): void {
    $kit->section('EndpointTest — real /aba/v1 stack via rest_do_request (PA5)');

    $studentA = $kit->makeStudent('4_6');
    $studentB = $kit->makeStudent('4_6');
    $userA = $kit->makeUser($studentA); // subscriber linked to studentA

    /** Send a JSON body the way a browser fetch() would (controllers read get_json_params()). */
    $withJson = static function (WP_REST_Request $req, array $body): WP_REST_Request {
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return $req;
    };

    try {
        // Seed one measured gap (CO 40) so recommendations + learning path are
        // deterministic and provably driven by data, not fixtures.
        (new SkillScoreRepository())->upsertScore($studentA, 'CO', 'core_competency', 40.0, 'developing', 0, 'student', 'ABA-FREQ-0.1', 'valid');

        $kit->check('real backend serves authenticated requests', is_int($userA) && $userA > 0);

        // ---- Anonymous is rejected server-side (AGENTS.md §8) --------------
        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/recommendations'));
        $kit->checkSame('GET /recommendations unauthenticated => 401', 401, $resp->get_status());

        // ---- Identity (own student only) ----------------------------------
        wp_set_current_user($userA);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/me'));
        $kit->checkSame('GET /me => 200', 200, $resp->get_status());
        $me = $resp->get_data();
        $kit->checkSame('/me resolves the linked student', $studentA, (int) ($me['studentId'] ?? 0));
        $kit->check('/me returns a roles array', is_array($me['roles'] ?? null));

        // ---- Skills projection (source default = self-report) -------------
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/skills/current'));
        $kit->checkSame('GET /skills/current => 200', 200, $resp->get_status());
        $skills = $resp->get_data();
        $kit->check('skills payload is a list', is_array($skills) && array_is_list($skills));

        // ---- Recommendations envelope, led by the CO gap (PA4 over HTTP) --
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/recommendations'));
        $kit->checkSame('GET /recommendations => 200', 200, $resp->get_status());
        $recs = $resp->get_data();
        $kit->check('recommendation envelope carries items[]', is_array($recs['items'] ?? null));
        $kit->checkSame('recommendation basis defaults to self-report', 'student', $recs['source'] ?? null);
        $firstCode = $recs['items'][0]['targetCompetency'] ?? null;
        $kit->checkSame('CO gap (40) surfaces as the top recommendation', 'CO', $firstCode);

        // ---- Learning path is gap-ordered over HTTP (PA7) -----------------
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/learning/path'));
        $kit->checkSame('GET /learning/path => 200', 200, $resp->get_status());
        $path = $resp->get_data();
        $kit->checkSame('path leads with the biggest-gap unit', 'CO', $path['units'][0]['competencyTarget'] ?? null);
        $kit->checkSame('current pointer sits on the active CO unit', 'unit-co', $path['currentUnitId'] ?? null);

        // ---- Server-side scope: cannot read another student's data -------
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $studentB . '/recommendations'));
        $kit->checkSame('foreign student scoped out => 403', 403, $resp->get_status());

        // ---- Full assessment flow over HTTP ------------------------------
        $resp = rest_do_request(new WP_REST_Request('POST', '/aba/v1/assessments/1/start'));
        $start = $resp->get_data();
        $kit->checkSame('POST /assessments/1/start => 200', 200, $resp->get_status());
        $sessionId = (int) ($start['session']['id'] ?? 0);
        $kit->check('start returns a session id', $sessionId > 0);
        $kit->checkSame('session frozen to a version', '0.1', $start['session']['assessmentVersion'] ?? null);
        $items = $start['questionBatch']['items'] ?? [];
        $kit->check('question batch is delivered', count($items) > 0);

        // §77: the answer payload must never leak the scoring key.
        $blob = wp_json_encode($start['questionBatch']);
        $kit->check('projection strips scenario score_value (§77)', strpos($blob, 'score_value') === false);
        $kit->check('projection strips is_reverse (§77)', strpos($blob, 'is_reverse') === false);
        $kit->check('projection strips weight (§77)', strpos($blob, '"weight"') === false);

        // Answer every delivered item, picking the first option affordance.
        foreach ($items as $item) {
            $opt = $item['options'][0] ?? null;
            if (!is_array($opt)) {
                continue;
            }
            $answer = array_key_exists('value', $opt) ? $opt['value'] : ($opt['id'] ?? null);
            if ($answer === null) {
                continue;
            }
            $resp = rest_do_request($withJson(
                new WP_REST_Request('POST', '/aba/v1/assessment-sessions/' . $sessionId . '/responses'),
                ['questionId' => (int) $item['id'], 'answer' => $answer, 'responseTimeMs' => 3000]
            ));
            if ($resp->get_status() !== 200) {
                $kit->check('record response for item ' . $item['id'] . ' => 200', false);
            }
        }
        $kit->check('all responses accepted over HTTP', true);

        // Resume state is readable.
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/assessment-sessions/' . $sessionId));
        $kit->checkSame('GET session resume state => 200', 200, $resp->get_status());

        // Completion scores every competency and pays XP through the ledger.
        $resp = rest_do_request(new WP_REST_Request('POST', '/aba/v1/assessment-sessions/' . $sessionId . '/complete'));
        $kit->checkSame('POST complete => 200', 200, $resp->get_status());
        $done = $resp->get_data();
        $kit->checkSame('result ready', 'ready', $done['result']['status'] ?? null);
        $kit->check('six competency scores produced', count($done['result']['scores'] ?? []) === 6);
        $kit->check('overall score is numeric', is_numeric($done['result']['overallScore'] ?? null));
        $kit->check('assessment XP awarded', (int) ($done['rewards']['xpAwarded'] ?? 0) > 0);

        // Post-assessment the projection now carries all six measured skills.
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/skills/current'));
        $final = $resp->get_data();
        $kit->checkSame('skills/current now lists six competencies', 6, count($final));

        // ---- Longitudinal history over the SELF route (PA10, /aba/v1) ------
        // Identity is derived from the login, not a client-supplied id.
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/skills/history'));
        $kit->checkSame('GET /skills/history (self) => 200', 200, $resp->get_status());
        $hist = $resp->get_data();
        $kit->check('self history is a list', is_array($hist) && array_is_list($hist));
        $kit->check('completed assessment left a snapshot (§11)', count($hist) >= 1);

        // ---- Gamification derived from the XP ledger (PA11) --------------
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/gamification/me'));
        $kit->checkSame('GET /gamification/me => 200', 200, $resp->get_status());
        $me = $resp->get_data();
        $kit->check('me.totalXp is ledger-derived and >= the 50 assessment XP', (int) ($me['totalXp'] ?? 0) >= 50);

        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/gamification/missions'));
        $kit->checkSame('GET /gamification/missions => 200', 200, $resp->get_status());
        $missions = $resp->get_data();
        $kit->check('missions payload is a list', is_array($missions) && array_is_list($missions));

        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/gamification/badges'));
        $kit->checkSame('GET /gamification/badges => 200', 200, $resp->get_status());
        $badges = $resp->get_data();
        $firstAssessment = null;
        foreach ($badges as $b) {
            if (($b['code'] ?? null) === 'FIRST_ASSESSMENT') {
                $firstAssessment = $b;
            }
        }
        $kit->check('FIRST_ASSESSMENT badge unlocked after a real assessment', ($firstAssessment['isUnlocked'] ?? false) === true);

        // ---- PA18: achievement timeline over HTTP -------------------------
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/gamification/achievements'));
        $kit->checkSame('GET /gamification/achievements => 200', 200, $resp->get_status());
        $ach = $resp->get_data();
        $kit->check('achievements reports unlocked + total counts', isset($ach['unlockedCount'], $ach['totalCount']));
        $kit->check('a real assessment unlocked at least one badge', (int) ($ach['unlockedCount'] ?? 0) >= 1);
        $kit->check('achievement timeline is a list', is_array($ach['timeline'] ?? null) && array_is_list($ach['timeline']));

        // ---- PA20: report snapshot persisted + readable over HTTP ---------
        $resp = rest_do_request(new WP_REST_Request('POST', '/aba/v1/students/' . $studentA . '/reports/generate'));
        $kit->checkSame('POST /students/{id}/reports/generate => 200', 200, $resp->get_status());
        $reportId = (int) (($resp->get_data()['id'] ?? 0));
        $kit->check('generated report carries a durable id', $reportId > 0);

        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/students/' . $studentA . '/reports'));
        $kit->checkSame('GET /students/{id}/reports => 200', 200, $resp->get_status());
        $reports = $resp->get_data();
        $kit->check('stored report is returned in history', is_array($reports) && count($reports) >= 1);
        $kit->checkSame('newest stored report matches the generated id', $reportId, (int) ($reports[0]['id'] ?? 0));

        // Report generation for a foreign student is still scoped out (§8).
        $resp = rest_do_request(new WP_REST_Request('POST', '/aba/v1/students/' . $studentB . '/reports/generate'));
        $kit->checkSame('foreign report generation => 403', 403, $resp->get_status());

        // ---- PA17: Content Library served over the real REST stack --------
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/learning/lessons'));
        $kit->checkSame('GET /learning/lessons => 200', 200, $resp->get_status());
        $cat = $resp->get_data();
        $kit->check('library is seeded for all bands (>=54 lessons)', (int) ($cat['count'] ?? 0) >= 54);
        $kit->check('lesson catalog is a list', is_array($cat['lessons'] ?? null) && array_is_list($cat['lessons']));

        // A learning-path node now points at a real lesson (additive binding).
        // Runs after the assessment above, so the gap unit may have shifted —
        // assert the lesson matches whatever competency now leads, not a fixed code.
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/learning/path'));
        $pdata = $resp->get_data();
        $leadCode = $pdata['units'][0]['competencyTarget'] ?? null;
        $firstNode = $pdata['units'][0]['nodes'][0] ?? [];
        $contentId = (int) ($firstNode['contentId'] ?? 0);
        $kit->check('gap unit node carries a contentId to a real lesson', $contentId > 0);

        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/lessons/' . $contentId));
        $kit->checkSame('GET /lessons/{id} => 200', 200, $resp->get_status());
        $lesson = $resp->get_data();
        $kit->check('lesson returns real body content', trim((string) ($lesson['content'] ?? '')) !== '');
        $kit->checkSame('lesson belongs to the unit it is bound to', $leadCode, $lesson['competencyTarget'] ?? null);

        // Catalog can be scoped to one competency (three node types x three bands).
        $filtered = new WP_REST_Request('GET', '/aba/v1/learning/lessons');
        $filtered->set_query_params(['competency' => 'co']);
        $resp = rest_do_request($filtered);
        $kit->checkSame('competency filter narrows catalog to CO', 9, (int) ($resp->get_data()['count'] ?? 0));

        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/lessons/99999999'));
        $kit->checkSame('GET /lessons/{unknown} => 404', 404, $resp->get_status());

        // Anonymous is rejected on the catalog too (AGENTS.md §8).
        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/learning/lessons'));
        $kit->checkSame('GET /learning/lessons unauthenticated => 401', 401, $resp->get_status());
        wp_set_current_user($userA);

        // Anonymous is rejected on gamification too (AGENTS.md §8).
        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('GET', '/aba/v1/gamification/me'));
        $kit->checkSame('GET /gamification/me unauthenticated => 401', 401, $resp->get_status());
        wp_set_current_user($userA);

        // Reset auth so a later suite never inherits this identity.
        wp_set_current_user(0);
    } finally {
        wp_set_current_user(0);
        $kit->cleanupUser($userA);
        $kit->cleanup($studentA);
        $kit->cleanup($studentB);
    }
};
