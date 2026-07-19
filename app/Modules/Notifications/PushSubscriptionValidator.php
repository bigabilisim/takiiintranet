<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use Closure;

final class PushSubscriptionValidator
{
    private const EXACT_HOSTS = [
        'android.googleapis.com',
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
    ];
    private const HOST_SUFFIXES = [
        '.notify.windows.com',
    ];

    private readonly Closure $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver !== null
            ? Closure::fromCallable($resolver)
            : static fn (string $host): array => self::resolveHost($host);
    }

    public function isValid(array $subscription): bool
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];

        return $this->isEndpointAllowed($endpoint)
            && $this->decodedKeyLength((string) ($keys['p256dh'] ?? '')) === 65
            && $this->decodedKeyStartsWith((string) ($keys['p256dh'] ?? ''), "\x04")
            && $this->decodedKeyLength((string) ($keys['auth'] ?? '')) === 16;
    }

    public function isEndpointAllowed(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $endpoint) === 1) {
            return false;
        }

        $parts = parse_url($endpoint);

        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ((int) ($parts['port'] ?? 443)) !== 443
        ) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $path = (string) ($parts['path'] ?? '');

        if ($host === '' || $path === '' || $path === '/' || !$this->isAllowedHost($host)) {
            return false;
        }

        $addresses = ($this->resolver)($host);

        if (!is_array($addresses) || $addresses === []) {
            return false;
        }

        foreach (array_unique(array_map('strval', $addresses)) as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return false;
            }
        }

        return true;
    }

    private function isAllowedHost(string $host): bool
    {
        if (in_array($host, self::EXACT_HOSTS, true)) {
            return true;
        }

        foreach (self::HOST_SUFFIXES as $suffix) {
            if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function decodedKeyLength(string $value): int
    {
        $decoded = $this->decodeBase64Url($value);

        return $decoded === null ? -1 : strlen($decoded);
    }

    private function decodedKeyStartsWith(string $value, string $prefix): bool
    {
        $decoded = $this->decodeBase64Url($value);

        return $decoded !== null && str_starts_with($decoded, $prefix);
    }

    private function decodeBase64Url(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 256 || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);

        return is_string($decoded) ? $decoded : null;
    }

    private static function resolveHost(string $host): array
    {
        $addresses = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);

            foreach (is_array($records) ? $records : [] as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    if (isset($record[$key]) && is_string($record[$key])) {
                        $addresses[] = $record[$key];
                    }
                }
            }
        }

        if ($addresses === [] && function_exists('gethostbynamel')) {
            $ipv4 = @gethostbynamel($host);

            if (is_array($ipv4)) {
                $addresses = array_merge($addresses, $ipv4);
            }
        }

        return array_values(array_unique($addresses));
    }
}
