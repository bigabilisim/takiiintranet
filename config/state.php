<?php

declare(strict_types=1);

$autoMigrate = filter_var(getenv('STATE_STORE_AUTO_MIGRATE') ?: 'false', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

return [
    'driver' => getenv('STATE_STORE_DRIVER') ?: 'mariadb',
    'auto_migrate' => $autoMigrate ?? true,
    'lock_timeout' => max(1, (int) (getenv('STATE_STORE_LOCK_TIMEOUT') ?: 10)),
    'encryption_key' => (string) (getenv('APP_DATA_ENCRYPTION_KEY') ?: ''),
    'previous_encryption_keys' => (string) (getenv('APP_DATA_ENCRYPTION_PREVIOUS_KEYS') ?: ''),
];
