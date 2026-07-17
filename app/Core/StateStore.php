<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class StateStore
{
    private const TABLE = 'app_state_documents';
    private const LOCK_ORDER = [
        'rate_limits' => 5,
        'password_resets' => 10,
        'password_reset_mail_outbox' => 15,
        'user_profiles' => 20,
        'access_control' => 30,
        'leave_requests' => 40,
        'leave_mail_outbox' => 50,
        'messages' => 60,
        'push_subscriptions' => 70,
        'shifts' => 80,
        'procurement' => 90,
        'audit_log' => 100,
        'templates' => 110,
        'template_test_mail_outbox' => 120,
        'release_notes' => 130,
    ];

    private array $contexts = [];
    private int $activeGuardCount = 0;
    private bool $schemaReady = false;
    private bool $sessionConfigured = false;

    public function __construct(
        private readonly ?Database $database,
        private readonly array $config,
    ) {
        if (!in_array($this->driver(), ['mariadb', 'file'], true)) {
            throw new RuntimeException('STATE_STORE_DRIVER must be either mariadb or file.');
        }

        if ($this->driver() === 'mariadb' && $this->database === null) {
            throw new RuntimeException('MariaDB state storage requires a database connection.');
        }
    }

    public function driver(): string
    {
        return strtolower(trim((string) ($this->config['driver'] ?? 'mariadb')));
    }

    public function read(string $documentKey, string $legacyPath, array $default = []): array
    {
        $documentKey = $this->normalizeKey($documentKey);

        if (isset($this->contexts[$documentKey])) {
            return $this->contexts[$documentKey]['payload'];
        }

        return $this->driver() === 'mariadb'
            ? $this->readMariaDb($documentKey, $legacyPath, $default)
            : $this->readLegacyFile($legacyPath, $default);
    }

    public function beginWrite(string $documentKey, string $legacyPath, array $default = []): StateWriteGuard
    {
        $documentKey = $this->normalizeKey($documentKey);

        if (isset($this->contexts[$documentKey])) {
            $this->contexts[$documentKey]['depth']++;
            $this->activeGuardCount++;

            return new StateWriteGuard($this, $documentKey);
        }

        if ($this->driver() === 'mariadb') {
            $this->beginMariaDbWrite($documentKey, $legacyPath, $default);
        } else {
            $this->beginFileWrite($documentKey, $legacyPath, $default);
        }

        $this->activeGuardCount++;

        return new StateWriteGuard($this, $documentKey);
    }

    /**
     * @param array<int, array{key: string, path: string, default?: array}> $documents
     */
    public function transaction(array $documents, callable $callback): mixed
    {
        if ($this->activeGuardCount > 0) {
            throw new RuntimeException('A state transaction cannot start inside an active write scope.');
        }

        $normalizedDocuments = [];

        foreach ($documents as $document) {
            $documentKey = $this->normalizeKey((string) ($document['key'] ?? ''));
            $legacyPath = trim((string) ($document['path'] ?? ''));

            if ($legacyPath === '') {
                throw new RuntimeException(sprintf('State document "%s" requires a legacy path.', $documentKey));
            }

            if (isset($normalizedDocuments[$documentKey]) && $normalizedDocuments[$documentKey]['path'] !== $legacyPath) {
                throw new RuntimeException(sprintf('State document "%s" was registered with conflicting paths.', $documentKey));
            }

            $normalizedDocuments[$documentKey] = [
                'key' => $documentKey,
                'path' => $legacyPath,
                'default' => is_array($document['default'] ?? null) ? $document['default'] : [],
            ];
        }

        uksort($normalizedDocuments, static function (string $left, string $right): int {
            $leftOrder = self::LOCK_ORDER[$left] ?? 1000;
            $rightOrder = self::LOCK_ORDER[$right] ?? 1000;

            return $leftOrder <=> $rightOrder ?: strcmp($left, $right);
        });
        $guards = [];
        $snapshots = [];

        try {
            foreach ($normalizedDocuments as $documentKey => $document) {
                $existed = $this->driver() === 'mariadb'
                    ? $this->metadata($documentKey) !== null
                    : is_file($document['path']);
                $guards[$documentKey] = $this->beginWrite(
                    $documentKey,
                    $document['path'],
                    $document['default']
                );
                $snapshots[$documentKey] = [
                    'path' => $document['path'],
                    'existed' => $existed,
                    'payload' => $this->read($documentKey, $document['path'], $document['default']),
                ];
            }

            $result = $callback();

            foreach (array_reverse($guards, true) as $guard) {
                $guard->release();
            }

            return $result;
        } catch (Throwable $exception) {
            $rollbackFailure = $this->rollbackTransaction($snapshots);

            if ($rollbackFailure !== null) {
                throw new RuntimeException(
                    'State transaction failed and rollback was incomplete: ' . $rollbackFailure->getMessage(),
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }

    public function write(string $documentKey, string $legacyPath, array $payload): void
    {
        $documentKey = $this->normalizeKey($documentKey);

        if (!isset($this->contexts[$documentKey])) {
            throw new RuntimeException(sprintf(
                'State document "%s" was written without an active write guard.',
                $documentKey
            ));
        }

        if ($this->driver() === 'mariadb') {
            $this->writeMariaDb($documentKey, $payload);
        } else {
            $this->writeFile($documentKey, $legacyPath, $payload);
        }
    }

    public function releaseWrite(string $documentKey): void
    {
        $documentKey = $this->normalizeKey($documentKey);

        if (!isset($this->contexts[$documentKey])) {
            return;
        }

        $this->contexts[$documentKey]['depth']--;
        $this->activeGuardCount = max(0, $this->activeGuardCount - 1);

        if ($this->contexts[$documentKey]['depth'] > 0) {
            return;
        }

        if ($this->driver() === 'file') {
            $lock = $this->contexts[$documentKey]['lock'] ?? null;

            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            unset($this->contexts[$documentKey]);

            return;
        }

        if ($this->activeGuardCount > 0) {
            return;
        }

        $connection = $this->connection();

        try {
            if ($connection->inTransaction()) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            $this->contexts = [];
            throw $exception;
        }

        $this->contexts = [];
    }

    public function metadata(string $documentKey): ?array
    {
        if ($this->driver() !== 'mariadb') {
            return null;
        }

        $this->ensureSchema();
        $statement = $this->connection()->prepare(
            'SELECT document_key, revision, checksum, created_at, updated_at FROM ' . self::TABLE . ' WHERE document_key = :document_key'
        );
        $statement->execute(['document_key' => $this->normalizeKey($documentKey)]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function __destruct()
    {
        if ($this->driver() !== 'mariadb' || $this->database === null) {
            return;
        }

        try {
            $connection = $this->database->connection();

            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // A failed connection has nothing left to roll back.
        }
    }

    private function beginMariaDbWrite(string $documentKey, string $legacyPath, array $default): void
    {
        $this->ensureSchema();
        $connection = $this->connection();

        if (!$connection->inTransaction()) {
            $connection->beginTransaction();
        }

        try {
            $this->insertDocumentIfMissing($documentKey, $legacyPath, $default);
            $statement = $connection->prepare(
                'SELECT payload, checksum, revision FROM ' . self::TABLE . ' WHERE document_key = :document_key FOR UPDATE'
            );
            $statement->execute(['document_key' => $documentKey]);
            $row = $statement->fetch();

            if (!is_array($row)) {
                throw new RuntimeException(sprintf('Unable to lock state document "%s".', $documentKey));
            }

            $this->contexts[$documentKey] = [
                'depth' => 1,
                'payload' => $this->decodeCheckedPayload(
                    (string) $row['payload'],
                    (string) $row['checksum'],
                    $documentKey
                ),
                'revision' => (int) $row['revision'],
                'lock' => null,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            $this->contexts = [];
            $this->activeGuardCount = 0;
            throw $exception;
        }
    }

    private function writeMariaDb(string $documentKey, array $payload): void
    {
        $encoded = $this->encodePayload($payload);
        $revision = (int) $this->contexts[$documentKey]['revision'];
        $statement = $this->connection()->prepare(
            'UPDATE ' . self::TABLE . ' SET payload = :payload, checksum = :checksum, revision = revision + 1, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE document_key = :document_key AND revision = :revision'
        );
        $statement->execute([
            'payload' => $encoded,
            'checksum' => hash('sha256', $encoded),
            'document_key' => $documentKey,
            'revision' => $revision,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(sprintf('Concurrent state update detected for "%s".', $documentKey));
        }

        $this->contexts[$documentKey]['payload'] = $payload;
        $this->contexts[$documentKey]['revision'] = $revision + 1;
    }

    private function readMariaDb(string $documentKey, string $legacyPath, array $default): array
    {
        $this->ensureSchema();
        $this->insertDocumentIfMissing($documentKey, $legacyPath, $default);
        $statement = $this->connection()->prepare(
            'SELECT payload, checksum FROM ' . self::TABLE . ' WHERE document_key = :document_key'
        );
        $statement->execute(['document_key' => $documentKey]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException(sprintf('State document "%s" could not be loaded.', $documentKey));
        }

        return $this->decodeCheckedPayload(
            (string) $row['payload'],
            (string) $row['checksum'],
            $documentKey
        );
    }

    private function insertDocumentIfMissing(string $documentKey, string $legacyPath, array $default): void
    {
        $lookup = $this->connection()->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE document_key = :document_key LIMIT 1'
        );
        $lookup->execute(['document_key' => $documentKey]);

        if ($lookup->fetchColumn() !== false) {
            return;
        }

        $payload = $this->readLegacyFile($legacyPath, $default);
        $encoded = $this->encodePayload($payload);
        $statement = $this->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (document_key, payload, checksum, revision, created_at, updated_at) '
            . 'VALUES (:document_key, :payload, :checksum, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE document_key = VALUES(document_key)'
        );
        $statement->execute([
            'document_key' => $documentKey,
            'payload' => $encoded,
            'checksum' => hash('sha256', $encoded),
        ]);
    }

    private function beginFileWrite(string $documentKey, string $legacyPath, array $default): void
    {
        $lockDirectory = trim((string) ($this->config['lock_directory'] ?? ''));

        if ($lockDirectory === '') {
            $lockDirectory = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/.state-locks';
        }

        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0770, true) && !is_dir($lockDirectory)) {
            throw new RuntimeException('Unable to create the state lock directory.');
        }

        $lockPath = $lockDirectory . '/' . hash('sha256', $documentKey) . '.lock';
        $lock = fopen($lockPath, 'c+');

        if ($lock === false) {
            throw new RuntimeException(sprintf('Unable to open state lock for "%s".', $documentKey));
        }

        $deadline = microtime(true) + max(1, (int) ($this->config['lock_timeout'] ?? 10));

        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);
                throw new RuntimeException(sprintf('Timed out while locking state document "%s".', $documentKey));
            }

            usleep(50_000);
        }

        $this->contexts[$documentKey] = [
            'depth' => 1,
            'payload' => $this->readLegacyFile($legacyPath, $default),
            'revision' => 0,
            'lock' => $lock,
        ];
    }

    private function writeFile(string $documentKey, string $legacyPath, array $payload): void
    {
        $directory = dirname($legacyPath);

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create state directory "%s".', $directory));
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        $temporaryPath = $directory . '/.' . basename($legacyPath) . '.' . $suffix . '.tmp';
        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write temporary state file "%s".', $temporaryPath));
        }

        @chmod($temporaryPath, 0660);

        if (!rename($temporaryPath, $legacyPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException(sprintf('Unable to atomically replace state file "%s".', $legacyPath));
        }

        $this->contexts[$documentKey]['payload'] = $payload;
    }

    private function readLegacyFile(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read legacy state file "%s".', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Invalid JSON in legacy state file "%s".', $path), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Legacy state file "%s" must contain a JSON object or array.', $path));
        }

        return $decoded;
    }

    private function rollbackTransaction(array $snapshots): ?Throwable
    {
        $rollbackFailure = null;

        if ($this->driver() === 'mariadb') {
            try {
                $connection = $this->connection();

                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
            } catch (Throwable $exception) {
                $rollbackFailure = $exception;
            }

            $this->contexts = [];
            $this->activeGuardCount = 0;

            return $rollbackFailure;
        }

        foreach ($snapshots as $documentKey => $snapshot) {
            try {
                if (!empty($snapshot['existed'])) {
                    $this->writeFile(
                        (string) $documentKey,
                        (string) ($snapshot['path'] ?? ''),
                        is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : []
                    );
                } elseif (is_file((string) ($snapshot['path'] ?? '')) && !unlink((string) $snapshot['path'])) {
                    throw new RuntimeException(sprintf(
                        'Unable to remove rolled-back state file "%s".',
                        (string) $snapshot['path']
                    ));
                }
            } catch (Throwable $exception) {
                $rollbackFailure ??= $exception;
            }
        }

        foreach ($this->contexts as $context) {
            $lock = $context['lock'] ?? null;

            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $this->contexts = [];
        $this->activeGuardCount = 0;

        return $rollbackFailure;
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $connection = $this->connection();

        if (!(bool) ($this->config['auto_migrate'] ?? true)) {
            $statement = $connection->query("SHOW TABLES LIKE '" . self::TABLE . "'");

            if ($statement === false || $statement->fetchColumn() === false) {
                throw new RuntimeException('State storage schema is missing. Run scripts/migrate-state-to-mariadb.php.');
            }

            $this->schemaReady = true;

            return;
        }

        $connection->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'document_key VARCHAR(120) NOT NULL PRIMARY KEY,'
            . 'payload LONGTEXT NOT NULL,'
            . 'checksum CHAR(64) NOT NULL,'
            . 'revision BIGINT UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->schemaReady = true;
    }

    private function connection(): PDO
    {
        if ($this->database === null) {
            throw new RuntimeException('Database connection is unavailable.');
        }

        $connection = $this->database->connection();

        if (!$this->sessionConfigured) {
            $timeout = max(1, min(120, (int) ($this->config['lock_timeout'] ?? 10)));
            $connection->exec('SET SESSION innodb_lock_wait_timeout = ' . $timeout);
            $this->sessionConfigured = true;
        }

        return $connection;
    }

    private function encodePayload(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function decodePayload(string $payload, string $documentKey): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Invalid JSON payload for state document "%s".', $documentKey), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('State document "%s" must contain a JSON object or array.', $documentKey));
        }

        return $decoded;
    }

    private function decodeCheckedPayload(string $payload, string $checksum, string $documentKey): array
    {
        if (!hash_equals($checksum, hash('sha256', $payload))) {
            throw new RuntimeException(sprintf('Checksum verification failed for state document "%s".', $documentKey));
        }

        return $this->decodePayload($payload, $documentKey);
    }

    private function normalizeKey(string $documentKey): string
    {
        $documentKey = strtolower(trim($documentKey));

        if ($documentKey === '' || preg_match('/^[a-z0-9][a-z0-9._-]{0,119}$/', $documentKey) !== 1) {
            throw new RuntimeException('Invalid state document key.');
        }

        return $documentKey;
    }
}
