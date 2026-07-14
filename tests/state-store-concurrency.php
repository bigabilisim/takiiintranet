<?php

declare(strict_types=1);

use App\Core\StateStore;

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';

$isWorker = ($argv[1] ?? '') === '--worker';
$statePath = (string) ($argv[2] ?? '');
$lockDirectory = (string) ($argv[3] ?? '');

if ($isWorker) {
    $workerId = (string) ($argv[4] ?? 'unknown');
    $iterations = max(1, (int) ($argv[5] ?? 1));
    $store = new StateStore(null, [
        'driver' => 'file',
        'lock_timeout' => 10,
        'lock_directory' => $lockDirectory,
    ]);

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $guard = $store->beginWrite('concurrency_test', $statePath, [
            'counter' => 0,
            'events' => [],
        ]);
        $state = $store->read('concurrency_test', $statePath);
        usleep(random_int(1_000, 15_000));
        $state['counter'] = (int) ($state['counter'] ?? 0) + 1;
        $state['events'][] = $workerId . ':' . $iteration;
        $store->write('concurrency_test', $statePath, $state);
        $guard->release();
    }

    exit(0);
}

$testRoot = sys_get_temp_dir() . '/takii-state-test-' . bin2hex(random_bytes(8));
$statePath = $testRoot . '/state.json';
$lockDirectory = $testRoot . '/locks';

if (!mkdir($testRoot, 0770, true) && !is_dir($testRoot)) {
    fwrite(STDERR, "Unable to create test directory.\n");
    exit(1);
}

$workers = 6;
$iterations = 20;
$processes = [];

for ($worker = 0; $worker < $workers; $worker++) {
    $command = implode(' ', array_map('escapeshellarg', [
        PHP_BINARY,
        __FILE__,
        '--worker',
        $statePath,
        $lockDirectory,
        (string) $worker,
        (string) $iterations,
    ]));
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to start worker.\n");
        exit(1);
    }

    $processes[] = [$process, $pipes];
}

$failed = false;

foreach ($processes as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $failed = true;
        fwrite(STDERR, (string) $output . (string) $errors);
    }
}

$decoded = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
$expected = $workers * $iterations;
$counter = is_array($decoded) ? (int) ($decoded['counter'] ?? -1) : -1;
$events = is_array($decoded['events'] ?? null) ? $decoded['events'] : [];
$uniqueEvents = array_values(array_unique(array_map('strval', $events)));

if ($failed || $counter !== $expected || count($events) !== $expected || count($uniqueEvents) !== $expected) {
    fwrite(STDERR, sprintf(
        "Concurrency test failed: expected=%d counter=%d events=%d unique=%d\n",
        $expected,
        $counter,
        count($events),
        count($uniqueEvents)
    ));
    exit(1);
}

@unlink($statePath);

foreach (glob($lockDirectory . '/*') ?: [] as $lockPath) {
    @unlink($lockPath);
}

@rmdir($lockDirectory);
@rmdir($testRoot);

echo sprintf("Concurrency test passed: %d atomic updates preserved.\n", $expected);
