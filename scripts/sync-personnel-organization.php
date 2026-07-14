<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\PersonnelOrganizationSync;
use App\Core\StateStore;

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';

foreach (file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = array_map('trim', explode('=', $line, 2));

    if ($key !== '' && getenv($key) === false) {
        putenv($key . '=' . trim($value, "\"'"));
    }
}

$apply = in_array('--apply', $argv, true);
$stateConfig = require APP_ROOT . '/config/state.php';
$databaseConfig = require APP_ROOT . '/config/database.php';
$stateStore = new StateStore($stateConfig['driver'] === 'mariadb' ? new Database($databaseConfig) : null, $stateConfig);
$plan = require APP_ROOT . '/resources/data/personnel-organization-2026-07-14.php';

try {
    $report = (new PersonnelOrganizationSync($stateStore))->synchronize($plan, $apply);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Organization sync failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

if (!$apply) {
    echo "Preview only. Re-run with --apply to write the changes.\n";
}
