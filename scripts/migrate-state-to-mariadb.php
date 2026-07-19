<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\StateStore;

define('APP_ROOT', dirname(__DIR__));

$autoload = APP_ROOT . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload file is missing.\n");
    exit(1);
}

require $autoload;

$envLines = is_file(APP_ROOT . '/.env')
    ? (file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
    : [];

foreach ($envLines as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = array_map('trim', explode('=', $line, 2));

    if ($key === '' || getenv($key) !== false) {
        continue;
    }

    putenv($key . '=' . trim($value, "\"'"));
}

$force = in_array('--force', $argv, true);
$databaseConfig = require APP_ROOT . '/config/database.php';
$stateConfig = require APP_ROOT . '/config/state.php';
$stateConfig['driver'] = 'mariadb';
$stateConfig['auto_migrate'] = true;
$store = new StateStore(new Database($databaseConfig), $stateConfig);
$documents = [
    'user_profiles' => 'user-profiles.json',
    'access_control' => 'access-control.json',
    'messages' => 'messages.json',
    'leave_requests' => 'leave-requests.json',
    'leave_mail_outbox' => 'leave-mail-outbox.json',
    'leave_signature_scheduler' => 'leave-book-signature-followup-scheduler.json',
    'password_resets' => 'password-resets.json',
    'password_reset_mail_outbox' => 'password-reset-mail-outbox.json',
    'rate_limits' => 'rate-limits.json',
    'push_subscriptions' => 'push-subscriptions.json',
    'shifts' => 'shifts.json',
    'procurement' => 'procurement.json',
    'audit_log' => 'audit-log.json',
    'templates' => 'templates.json',
    'template_test_mail_outbox' => 'template-test-mail-outbox.json',
    'release_notes' => 'release-notes.json',
    'vapid_keys' => 'vapid.json',
];

echo "Transactional state migration\n";
echo $force ? "Mode: force (existing rows may be replaced)\n\n" : "Mode: safe/idempotent\n\n";

try {
    foreach ($documents as $documentKey => $filename) {
        $path = APP_ROOT . '/storage/' . $filename;
        $legacyPayload = [];

        if (is_file($path)) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException(sprintf('Unable to read %s.', $path));
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new RuntimeException(sprintf('%s must contain a JSON object or array.', $path));
            }

            $legacyPayload = $decoded;
        }

        $existed = $store->metadata($documentKey) !== null;
        $guard = $store->beginWrite($documentKey, $path);

        if ($force && is_file($path)) {
            $store->write($documentKey, $path, $legacyPayload);
        }

        $databasePayload = $store->read($documentKey, $path);
        $guard->release();
        $metadata = $store->metadata($documentKey);
        $matchesLegacy = !is_file($path) || $databasePayload === $legacyPayload;
        $status = $force ? 'replaced' : ($existed ? 'kept' : 'imported');

        printf(
            "%-30s %-9s revision=%s legacy_match=%s\n",
            $documentKey,
            $status,
            (string) ($metadata['revision'] ?? '?'),
            $matchesLegacy ? 'yes' : 'no'
        );

        if (!$existed && !$matchesLegacy) {
            throw new RuntimeException(sprintf('Verification failed for %s.', $documentKey));
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "\nMigration failed: " . $exception->getMessage() . "\n");
    exit(1);
}

echo "\nMigration completed successfully.\n";
