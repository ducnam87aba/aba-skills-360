<?php
/**
 * ABA Skills Core — test runner.
 *
 * Discovers every suites/*Test.php (each returns a closure taking AbaTestKit),
 * runs them against the live WordPress + MySQL stack, prints one aggregate
 * result, and exits non-zero on any failure so CI gates on it.
 *
 *   docker exec aba-wordpress php \
 *     /var/www/html/wp-content/plugins/aba-skills-core/tests/run_all.php
 *
 * Optional: ABA_TEST_FILTER=Engine (substring match on the suite file name).
 *
 * @package ABA_Skills_Core
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('ABA test harness is CLI-only.' . PHP_EOL);
}

require __DIR__ . '/bootstrap.php';

$kit    = new AbaTestKit();
$filter = getenv('ABA_TEST_FILTER') ?: '';

$suiteFiles = glob(__DIR__ . '/suites/*Test.php') ?: [];
sort($suiteFiles);

if (empty($suiteFiles)) {
    fwrite(STDERR, 'No suites found under tests/suites/.' . PHP_EOL);
    exit(1);
}

echo 'ABA Skills Core test harness — ' . count($suiteFiles) . ' suite file(s) discovered.' . PHP_EOL;

foreach ($suiteFiles as $file) {
    $name = basename($file, '.php');
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }

    try {
        $suite = require $file;
        if (!$suite instanceof Closure) {
            $kit->section($name);
            $kit->check("$name returns a closure", false);
            continue;
        }
        $suite($kit);
    } catch (Throwable $e) {
        // A crashing suite is recorded as a failure but must not abort the run,
        // so one broken area never hides the others' results.
        $kit->section($name . ' (crashed)');
        $kit->check($name . ' threw: ' . $e->getMessage(), false);
    }
}

$kit->summary();
exit($kit->fail === 0 ? 0 : 1);
