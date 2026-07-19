<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$architecture = require APP_ROOT . '/config/architecture.php';
$gate = $architecture['release_gate'] ?? [];
$recordVerification = in_array('--record', $argv, true);
$startedAt = microtime(true);
$completedChecks = [];

/**
 * @return array{exit_code: int, output: string}
 */
function runReleaseCommand(array $command, array $environment = []): array
{
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processEnvironment = getenv();

    if (!is_array($processEnvironment)) {
        $processEnvironment = [];
    }

    foreach ($environment as $key => $value) {
        $processEnvironment[(string) $key] = (string) $value;
    }

    $process = proc_open($command, $descriptors, $pipes, APP_ROOT, $processEnvironment);

    if (!is_resource($process)) {
        return ['exit_code' => 1, 'output' => 'Process could not be started.'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'output' => trim((string) $stdout . ($stderr !== '' ? "\n" . (string) $stderr : '')),
    ];
}

function releaseExecutable(string $environmentKey, string $fallback): ?string
{
    $configured = trim((string) (getenv($environmentKey) ?: ''));

    if ($configured !== '') {
        return is_file($configured) && is_executable($configured) ? $configured : null;
    }

    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fallback;

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function releaseFail(string $message, string $details = ''): never
{
    fwrite(STDERR, "[FAIL] " . $message . "\n");

    if ($details !== '') {
        fwrite(STDERR, $details . "\n");
    }

    exit(1);
}

function releasePass(string $label, float $start): void
{
    printf("[PASS] %s (%.2fs)\n", $label, microtime(true) - $start);
}

function releasePhpFiles(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        $absoluteRoot = APP_ROOT . '/' . ltrim((string) $root, '/');

        if (!is_dir($absoluteRoot)) {
            releaseFail('PHP lint root is missing: ' . (string) $root . '.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(APP_ROOT) + 1));

            if (str_starts_with($relative, 'public/vendor/')) {
                continue;
            }

            $files[] = $relative;
        }
    }

    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

echo "MyTakii release gate\n";
echo "Architecture schema: " . (string) ($architecture['schema_version'] ?? '?') . "\n\n";

$minimumPhp = (string) ($architecture['runtime']['php_minimum'] ?? '8.3.0');

if (version_compare(PHP_VERSION, $minimumPhp, '<')) {
    releaseFail(sprintf('PHP %s or newer is required; current version is %s.', $minimumPhp, PHP_VERSION));
}

if (!is_file(APP_ROOT . '/vendor/autoload.php')) {
    releaseFail('vendor/autoload.php is missing. Run composer install first.');
}

$checkStart = microtime(true);
$phpFiles = releasePhpFiles((array) ($gate['php_roots'] ?? []));

foreach ($phpFiles as $file) {
    $result = runReleaseCommand([PHP_BINARY, '-l', APP_ROOT . '/' . $file]);

    if ($result['exit_code'] !== 0) {
        releaseFail('PHP syntax check failed for ' . $file . '.', $result['output']);
    }
}

$completedChecks[] = 'php_lint';
releasePass('PHP syntax: ' . count($phpFiles) . ' files', $checkStart);

$node = releaseExecutable('NODE_BINARY', 'node');

if ($node === null) {
    releaseFail('Node.js is required for JavaScript syntax checks. Set NODE_BINARY to an executable path.');
}

$checkStart = microtime(true);
$javascriptFiles = (array) ($gate['javascript_files'] ?? []);

foreach ($javascriptFiles as $file) {
    $absolutePath = APP_ROOT . '/' . ltrim((string) $file, '/');

    if (!is_file($absolutePath)) {
        releaseFail('JavaScript entry point is missing: ' . (string) $file . '.');
    }

    $result = runReleaseCommand([$node, '--check', $absolutePath]);

    if ($result['exit_code'] !== 0) {
        releaseFail('JavaScript syntax check failed for ' . (string) $file . '.', $result['output']);
    }
}

$completedChecks[] = 'javascript_syntax';
releasePass('JavaScript syntax: ' . count($javascriptFiles) . ' files', $checkStart);

$requiredTests = (array) ($gate['required_tests'] ?? []);

if ($requiredTests === []) {
    releaseFail('No required regression tests are registered in config/architecture.php.');
}

foreach ($requiredTests as $testFile) {
    $testFile = (string) $testFile;
    $absolutePath = APP_ROOT . '/' . ltrim($testFile, '/');

    if (!is_file($absolutePath)) {
        releaseFail('Required regression test is missing: ' . $testFile . '.');
    }

    $testStart = microtime(true);
    $result = runReleaseCommand([PHP_BINARY, $absolutePath]);

    if ($result['exit_code'] !== 0) {
        releaseFail('Regression test failed: ' . $testFile . '.', $result['output']);
    }

    $completedChecks[] = $testFile;
    releasePass($testFile, $testStart);
}

$mariaDbIncluded = filter_var(getenv('RELEASE_VERIFY_MARIADB') ?: 'false', FILTER_VALIDATE_BOOL);

if ($mariaDbIncluded) {
    $databaseName = trim((string) (getenv('DB_DATABASE') ?: ''));
    $confirmed = filter_var(getenv('RELEASE_TEST_DATABASE_CONFIRMED') ?: 'false', FILTER_VALIDATE_BOOL);

    if ($databaseName === '' || (preg_match('/(?:^test_|_test$)/i', $databaseName) !== 1 && !$confirmed)) {
        releaseFail(
            'MariaDB tests require a dedicated test database name ending in _test or starting with test_.',
            'Never run the release integration suite against the production database.'
        );
    }

    foreach ((array) ($gate['mariadb_tests'] ?? []) as $testDefinition) {
        $testFile = (string) ($testDefinition['file'] ?? '');
        $absolutePath = APP_ROOT . '/' . ltrim($testFile, '/');

        if ($testFile === '' || !is_file($absolutePath)) {
            releaseFail('Registered MariaDB regression test is missing: ' . $testFile . '.');
        }

        $testStart = microtime(true);
        $result = runReleaseCommand(
            [PHP_BINARY, $absolutePath],
            (array) ($testDefinition['environment'] ?? [])
        );

        if ($result['exit_code'] !== 0) {
            releaseFail('MariaDB regression test failed: ' . $testFile . '.', $result['output']);
        }

        $completedChecks[] = $testFile . ':mariadb';
        releasePass($testFile . ' [MariaDB]', $testStart);
    }
} else {
    echo "[INFO] MariaDB integration suite not requested; use a dedicated test DB with RELEASE_VERIFY_MARIADB=1.\n";
}

if ($recordVerification) {
    $git = releaseExecutable('GIT_BINARY', 'git');

    if ($git === null) {
        releaseFail('Git is required to record a production verification. Set GIT_BINARY if necessary.');
    }

    $status = runReleaseCommand([$git, 'status', '--porcelain', '--untracked-files=all']);

    if ($status['exit_code'] !== 0) {
        releaseFail('Unable to inspect Git worktree.', $status['output']);
    }

    if ($status['output'] !== '') {
        releaseFail('Production verification can only be recorded from a clean Git worktree.', $status['output']);
    }

    $commit = runReleaseCommand([$git, 'rev-parse', 'HEAD']);
    $tag = runReleaseCommand([$git, 'describe', '--tags', '--exact-match', 'HEAD']);

    if ($commit['exit_code'] !== 0 || $commit['output'] === '') {
        releaseFail('Unable to resolve the Git commit.', $commit['output']);
    }

    if ($tag['exit_code'] !== 0 || $tag['output'] === '') {
        releaseFail('Production verification requires an exact version tag on HEAD.', $tag['output']);
    }

    $releaseSource = file_get_contents(APP_ROOT . '/app/Core/ReleaseNoteStore.php');

    if ($releaseSource === false || preg_match("/CURRENT_RELEASE\\s*=\\s*'([^']+)'/", $releaseSource, $releaseMatch) !== 1) {
        releaseFail('Unable to read CURRENT_RELEASE from ReleaseNoteStore.');
    }

    if ((string) $releaseMatch[1] !== $tag['output']) {
        releaseFail(sprintf(
            'Version mismatch: ReleaseNoteStore is %s but HEAD tag is %s.',
            (string) $releaseMatch[1],
            $tag['output']
        ));
    }

    $manifest = [
        'schema_version' => 1,
        'architecture_schema_version' => $architecture['schema_version'],
        'version' => $tag['output'],
        'commit' => $commit['output'],
        'verified_at_utc' => gmdate(DATE_ATOM),
        'php_version' => PHP_VERSION,
        'mariadb_integration_included' => $mariaDbIncluded,
        'checks' => $completedChecks,
    ];
    $manifestDirectory = APP_ROOT . '/tmp';

    if (!is_dir($manifestDirectory) && !mkdir($manifestDirectory, 0775, true) && !is_dir($manifestDirectory)) {
        releaseFail('Unable to create the verification manifest directory.');
    }

    $encodedManifest = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

    if (file_put_contents($manifestDirectory . '/release-verification.json', $encodedManifest, LOCK_EX) === false) {
        releaseFail('Unable to write the release verification manifest.');
    }

    echo "[PASS] Verification recorded for " . $tag['output'] . ' @ ' . substr($commit['output'], 0, 12) . ".\n";
}

printf("\nRelease gate passed in %.2fs.\n", microtime(true) - $startedAt);
