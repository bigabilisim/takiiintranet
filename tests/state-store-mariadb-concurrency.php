<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\StateStore;

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';

$databaseConfig = require APP_ROOT . '/config/database.php';
$stateConfig = [
    'driver' => 'mariadb',
    'auto_migrate' => true,
    'lock_timeout' => 20,
];
$isWorker = ($argv[1] ?? '') === '--worker';
$documentKey = (string) ($argv[2] ?? '');
$legacyPath = (string) ($argv[3] ?? '');

if ($isWorker) {
    $workerId = (string) ($argv[4] ?? 'unknown');
    $iterations = max(1, (int) ($argv[5] ?? 1));
    $store = new StateStore(new Database($databaseConfig), $stateConfig);

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $guard = $store->beginWrite($documentKey, $legacyPath, ['counter' => 0, 'events' => []]);
        $state = $store->read($documentKey, $legacyPath);
        usleep(random_int(1_000, 15_000));
        $state['counter'] = (int) ($state['counter'] ?? 0) + 1;
        $state['events'][] = $workerId . ':' . $iteration;
        $store->write($documentKey, $legacyPath, $state);
        $guard->release();
    }

    exit(0);
}

$documentKey = 'mariadb_concurrency_' . bin2hex(random_bytes(6));
$legacyPath = sys_get_temp_dir() . '/' . $documentKey . '.json';
$store = new StateStore(new Database($databaseConfig), $stateConfig);
$connection = (new Database($databaseConfig))->connection();
$workers = 6;
$iterations = 20;
$processes = [];

try {
    $guard = $store->beginWrite($documentKey, $legacyPath, ['counter' => 0, 'events' => []]);
    $store->write($documentKey, $legacyPath, ['counter' => 0, 'events' => []]);
    $guard->release();

    for ($worker = 0; $worker < $workers; $worker++) {
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            __FILE__,
            '--worker',
            $documentKey,
            $legacyPath,
            (string) $worker,
            (string) $iterations,
        ]));
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start MariaDB concurrency worker.');
        }

        $processes[] = [$process, $pipes];
    }

    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim((string) $output . (string) $errors) ?: 'MariaDB worker failed.');
        }
    }

    $state = $store->read($documentKey, $legacyPath);
    $metadata = $store->metadata($documentKey);
    $expected = $workers * $iterations;
    $counter = (int) ($state['counter'] ?? -1);
    $events = is_array($state['events'] ?? null) ? $state['events'] : [];
    $uniqueEvents = array_values(array_unique(array_map('strval', $events)));

    if ($counter !== $expected || count($events) !== $expected || count($uniqueEvents) !== $expected) {
        throw new RuntimeException(sprintf(
            'Expected %d updates; counter=%d events=%d unique=%d.',
            $expected,
            $counter,
            count($events),
            count($uniqueEvents)
        ));
    }

    $tamper = $connection->prepare(
        'UPDATE app_state_documents SET payload = :payload WHERE document_key = :document_key'
    );
    $tamper->execute([
        'payload' => '{"counter":-1}',
        'document_key' => $documentKey,
    ]);
    $checksumRejected = false;

    try {
        $store->read($documentKey, $legacyPath);
    } catch (RuntimeException $exception) {
        $checksumRejected = str_contains($exception->getMessage(), 'Checksum verification failed');
    }

    if (!$checksumRejected) {
        throw new RuntimeException('Tampered state payload was not rejected.');
    }

    echo sprintf(
        "MariaDB concurrency test passed: %d atomic updates, revision %d, checksum verified.\n",
        $expected,
        (int) ($metadata['revision'] ?? 0)
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "MariaDB concurrency test failed: " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    $statement = $connection->prepare('DELETE FROM app_state_documents WHERE document_key = :document_key');
    $statement->execute(['document_key' => $documentKey]);
    @unlink($legacyPath);
}
