<?php
/**
 * Suite: Streak-freeze power-ups (PA22).
 *
 * Proves the freeze economy stays true to the ledger philosophy (AGENTS.md §12):
 * a balance is always the signed SUM of grant/spend rows and every entry is
 * idempotent, so repeated reads and double-tapped spends neither mint nor burn
 * credits. Bridging a missed day repairs the run WITHOUT creating any XP, and no
 * credit is consumed when there is no repairable gap or no balance. The last
 * checks drive the shipped route through the real REST stack.
 *
 * Returns a closure so run_all.php can feed it the shared AbaTestKit.
 *
 * @package ABA_Skills_Core
 */

use ABA\Core\Repositories\PowerUpRepository;
use ABA\Core\Repositories\StreakRepository;
use ABA\Core\Repositories\XpTransactionRepository;
use ABA\Core\Services\GamificationService;

return function (AbaTestKit $kit): void {
    $kit->section('StreakFreezeTest — ledger-safe streak freezes (PA22, §12)');

    $powers  = new PowerUpRepository();
    $streak  = new StreakRepository();
    $xp      = new XpTransactionRepository();
    $service = new GamificationService();

    $kind = PowerUpRepository::FREEZE;
    $today     = gmdate('Y-m-d');
    $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
    $twoAgo    = gmdate('Y-m-d', strtotime('-2 day'));

    // Students: main ledger + read; an earner for the milestone path; a bridger;
    // a broke (no-credit) student; and one for the REST drive.
    $studentId = $kit->makeStudent('4_6');
    $earner    = $kit->makeStudent('4_6');
    $bridger   = $kit->makeStudent('4_6');
    $broke     = $kit->makeStudent('4_6');
    $restStud  = $kit->makeStudent('4_6');

    try {
        // ---- Ledger primitives: balance is a signed sum, entries idempotent ----
        $kit->checkSame('empty ledger balance is 0', 0, $powers->balance($studentId, $kind));
        $powers->grant($studentId, $kind, 1, 'milestone:1');
        $kit->checkSame('a grant raises the balance', 1, $powers->balance($studentId, $kind));
        $powers->grant($studentId, $kind, 1, 'milestone:1'); // same reason
        $kit->checkSame('re-granting the same reason is idempotent', 1, $powers->balance($studentId, $kind));
        $powers->grant($studentId, $kind, 1, 'milestone:2');
        $kit->checkSame('a distinct milestone mints a new credit', 2, $powers->balance($studentId, $kind));
        $powers->grant($studentId, $kind, -1, 'use:seed');
        $kit->checkSame('a spend lowers the balance', 1, $powers->balance($studentId, $kind));
        $kit->checkSame('the ledger keeps every entry', 3, count($powers->ledger($studentId, $kind)));

        // ---- buildMe reads the balance, purely (byte-deterministic) ------------
        $me = $service->buildMe($studentId);
        $kit->checkSame('me.streakFreezes mirrors the ledger', 1, $me['streakFreezes'] ?? null);
        $kit->check('buildMe stays deterministic', wp_json_encode($service->buildMe($studentId)) === wp_json_encode($service->buildMe($studentId)));
        $kit->checkSame('a pure read never mints or burns', 1, $powers->balance($studentId, $kind));

        // ---- Earn: a 7-day run banks a freeze through the badge path -----------
        for ($d = 0; $d < 7; $d++) {
            $streak->recordActivity($earner, gmdate('Y-m-d', strtotime("-{$d} day")));
        }
        $kit->checkSame('seven consecutive days => streak 7', 7, $streak->getCurrentStreak($earner));
        $service->buildBadges($earner);
        $kit->checkSame('the 7-day milestone banks one freeze', 1, $powers->balance($earner, $kind));
        $service->buildBadges($earner);
        $kit->checkSame('repeated milestone reads do not double-mint', 1, $powers->balance($earner, $kind));

        // ---- Bridge: repair a one-day hole without minting XP ------------------
        $streak->recordActivity($bridger, $today);
        $streak->recordActivity($bridger, $twoAgo); // yesterday missing
        $kit->checkSame('a one-day hole breaks the run to 1', 1, $streak->getCurrentStreak($bridger));
        $kit->checkSame('the repairable gap is the missed day', $yesterday, $streak->detectRepairableGap($bridger));

        $powers->grant($bridger, $kind, 1, 'seed:bridger');
        $xpBefore = $xp->getTotalXp($bridger);

        $res = $service->useStreakFreeze($bridger);
        $kit->checkSame('freeze applied', true, $res['applied'] ?? null);
        $kit->checkSame('status applied', 'applied', $res['status'] ?? null);
        $kit->checkSame('it reports the bridged date', $yesterday, $res['gapDate'] ?? null);
        $kit->checkSame('the run is repaired to 3', 3, $res['currentStreak'] ?? null);
        $kit->checkSame('the credit is spent', 0, $res['freezes'] ?? null);
        $kit->checkSame('no XP is minted by a freeze (§12)', $xpBefore, $xp->getTotalXp($bridger));

        // A second tap on the now-continuous run finds no hole and spends nothing.
        $again = $service->useStreakFreeze($bridger);
        $kit->checkSame('a repaired run reports no gap', 'no_gap', $again['status'] ?? null);
        $kit->checkSame('the double-tap burns no extra credit', 0, $again['freezes'] ?? null);

        // ---- Guard: a gap with no credit is not repaired, nothing consumed -----
        $streak->recordActivity($broke, $today);
        $streak->recordActivity($broke, $twoAgo);
        $poor = $service->useStreakFreeze($broke);
        $kit->checkSame('no credit => freeze not applied', false, $poor['applied'] ?? null);
        $kit->checkSame('status no_freeze', 'no_freeze', $poor['status'] ?? null);
        $kit->checkSame('the run stays broken', 1, $streak->getCurrentStreak($broke));

        // ---- REST stack: auth + the real spend route ---------------------------
        wp_set_current_user(0);
        $resp = rest_do_request(new WP_REST_Request('POST', '/aba/v1/gamification/streak/freeze'));
        $kit->checkSame('anonymous freeze spend => 401', 401, $resp->get_status());

        $streak->recordActivity($restStud, $today);
        $streak->recordActivity($restStud, $twoAgo);
        $powers->grant($restStud, $kind, 1, 'seed:rest');
        $restUser = $kit->makeUser($restStud);
        wp_set_current_user($restUser);
        $resp = rest_do_request(new WP_REST_Request('POST', '/aba/v1/gamification/streak/freeze'));
        $data = $resp->get_data();
        $kit->checkSame('student freeze spend => 200', 200, $resp->get_status());
        $kit->checkSame('route applied the freeze', true, $data['applied'] ?? null);
        $kit->checkSame('route reports the repaired run', 3, $data['currentStreak'] ?? null);
    } finally {
        wp_set_current_user(0);
        $kit->cleanup($studentId);
        $kit->cleanup($earner);
        $kit->cleanup($bridger);
        $kit->cleanup($broke);
        $kit->cleanup($restStud);
        $kit->cleanupUser($restUser ?? 0);
    }
};
