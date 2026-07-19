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
$planPath = trim((string) (getenv('PERSONNEL_ORGANIZATION_PLAN') ?: ''));

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--plan=')) {
        $planPath = trim(substr($argument, strlen('--plan=')));
    }
}

if ($planPath === '') {
    fwrite(STDERR, "A confidential JSON plan is required. Set PERSONNEL_ORGANIZATION_PLAN or pass --plan=/absolute/path/plan.json.\n");
    exit(1);
}

$resolvedPlanPath = realpath($planPath);

if ($resolvedPlanPath === false || !is_file($resolvedPlanPath) || !is_readable($resolvedPlanPath)) {
    fwrite(STDERR, "The organization plan could not be read.\n");
    exit(1);
}

$repositoryRoot = realpath(APP_ROOT) ?: APP_ROOT;

if (str_starts_with($resolvedPlanPath, $repositoryRoot . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "The confidential organization plan must be stored outside the repository.\n");
    exit(1);
}

try {
    $plan = json_decode((string) file_get_contents($resolvedPlanPath), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'The organization plan is not valid JSON: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

if (!is_array($plan)) {
    fwrite(STDERR, "The organization plan must contain a JSON object.\n");
    exit(1);
}

$stateConfig = require APP_ROOT . '/config/state.php';
$databaseConfig = require APP_ROOT . '/config/database.php';
$stateStore = new StateStore($stateConfig['driver'] === 'mariadb' ? new Database($databaseConfig) : null, $stateConfig);

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
