<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

function releaseAssertionExecutable(string $environmentKey, string $fallback): ?string
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

/**
 * @return array{exit_code: int, output: string}
 */
function runReleaseAssertion(array $command): array
{
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        APP_ROOT
    );

    if (!is_resource($process)) {
        return ['exit_code' => 1, 'output' => 'Process could not be started.'];
    }

    $output = trim((string) stream_get_contents($pipes[1]) . "\n" . (string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit_code' => proc_close($process), 'output' => trim($output)];
}

function releaseAssertionFail(string $message): never
{
    fwrite(STDERR, "Release assertion failed: " . $message . "\n");
    exit(1);
}

$manifestPath = APP_ROOT . '/tmp/release-verification.json';

if (!is_file($manifestPath)) {
    releaseAssertionFail('verification manifest is missing; run composer verify:release:record.');
}

try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    releaseAssertionFail('verification manifest is invalid JSON.');
}

if (!is_array($manifest) || (int) ($manifest['schema_version'] ?? 0) !== 1) {
    releaseAssertionFail('verification manifest schema is invalid.');
}

$verifiedAt = strtotime((string) ($manifest['verified_at_utc'] ?? ''));

if ($verifiedAt === false || $verifiedAt < time() - 86400) {
    releaseAssertionFail('verification is older than 24 hours; run the release gate again.');
}

$architecture = require APP_ROOT . '/config/architecture.php';

if ((int) ($manifest['architecture_schema_version'] ?? 0) !== (int) ($architecture['schema_version'] ?? -1)) {
    releaseAssertionFail('architecture schema changed after verification.');
}

$git = releaseAssertionExecutable('GIT_BINARY', 'git');

if ($git === null) {
    releaseAssertionFail('Git is unavailable; set GIT_BINARY to the executable path.');
}

$status = runReleaseAssertion([$git, 'status', '--porcelain', '--untracked-files=all']);

if ($status['exit_code'] !== 0 || $status['output'] !== '') {
    releaseAssertionFail('Git worktree is not clean or could not be inspected.');
}

$commit = runReleaseAssertion([$git, 'rev-parse', 'HEAD']);
$tag = runReleaseAssertion([$git, 'describe', '--tags', '--exact-match', 'HEAD']);

if ($commit['exit_code'] !== 0 || !hash_equals((string) ($manifest['commit'] ?? ''), $commit['output'])) {
    releaseAssertionFail('current commit does not match the verified commit.');
}

if ($tag['exit_code'] !== 0 || !hash_equals((string) ($manifest['version'] ?? ''), $tag['output'])) {
    releaseAssertionFail('current tag does not match the verified release version.');
}

printf(
    "Release assertion passed: %s @ %s, verified %s.\n",
    (string) $manifest['version'],
    substr((string) $manifest['commit'], 0, 12),
    (string) $manifest['verified_at_utc']
);
