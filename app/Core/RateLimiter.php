<?php

declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    private const STATE_KEY = 'rate_limits';
    private const VERSION = 1;

    public function __construct(private readonly StateStore $stateStore)
    {
    }

    public function attempt(string $scope, string $subject, int $maximumAttempts, int $windowSeconds): bool
    {
        $maximumAttempts = max(1, $maximumAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $now = time();
        $key = $this->bucketKey($scope, $subject);
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $data = $this->loadData();
        $attempts = is_array($data['buckets'][$key]['attempts'] ?? null)
            ? $data['buckets'][$key]['attempts']
            : [];
        $attempts = array_values(array_filter(
            array_map('intval', $attempts),
            static fn (int $timestamp): bool => $timestamp > ($now - $windowSeconds)
        ));
        $allowed = count($attempts) < $maximumAttempts;

        if ($allowed) {
            $attempts[] = $now;
        }

        $data['buckets'][$key] = [
            'scope' => $scope,
            'attempts' => $attempts,
            'expires_at' => $now + $windowSeconds,
        ];
        $data['buckets'] = array_filter(
            $data['buckets'],
            static fn (array $bucket): bool => (int) ($bucket['expires_at'] ?? 0) >= $now
        );
        $this->saveData($data);

        return $allowed;
    }

    public function clear(string $scope, string $subject): void
    {
        $key = $this->bucketKey($scope, $subject);
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $data = $this->loadData();

        if (!isset($data['buckets'][$key])) {
            return;
        }

        unset($data['buckets'][$key]);
        $this->saveData($data);
    }

    private function bucketKey(string $scope, string $subject): string
    {
        $secret = (string) (getenv('APP_SESSION_SECRET') ?: 'mytakii-rate-limiter');

        return hash_hmac('sha256', strtolower(trim($scope)) . '|' . strtolower(trim($subject)), $secret);
    }

    private function loadData(): array
    {
        $data = $this->stateStore->read(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $data = is_array($data) ? $data : [];

        return [
            'version' => self::VERSION,
            'buckets' => is_array($data['buckets'] ?? null) ? $data['buckets'] : [],
        ];
    }

    private function saveData(array $data): void
    {
        $this->stateStore->write(self::STATE_KEY, $this->dataPath(), [
            'version' => self::VERSION,
            'buckets' => is_array($data['buckets'] ?? null) ? $data['buckets'] : [],
        ]);
    }

    private function emptyData(): array
    {
        return ['version' => self::VERSION, 'buckets' => []];
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/rate-limits.json';
    }
}
