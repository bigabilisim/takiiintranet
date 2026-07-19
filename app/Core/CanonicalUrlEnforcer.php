<?php

declare(strict_types=1);

namespace App\Core;

final class CanonicalUrlEnforcer
{
    public static function redirectTarget(array $server, string $appUrl, bool $trustProxy = false): ?string
    {
        $parts = parse_url(trim($appUrl));

        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        $canonicalHost = strtolower(trim((string) ($parts['host'] ?? '')));

        if ($canonicalHost === '') {
            return null;
        }

        $canonicalAuthority = $canonicalHost;
        $canonicalPort = (int) ($parts['port'] ?? 443);

        if ($canonicalPort !== 443) {
            $canonicalAuthority .= ':' . $canonicalPort;
        }

        $httpsValue = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        $isSecure = in_array($httpsValue, ['on', '1', 'true'], true)
            || (int) ($server['SERVER_PORT'] ?? 0) === 443;

        if ($trustProxy) {
            $forwardedProto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            $isSecure = $forwardedProto === 'https' || ($forwardedProto === '' && $isSecure);
        }

        $requestAuthority = strtolower(trim(explode(',', (string) ($server['HTTP_HOST'] ?? ''))[0]));

        if ($isSecure && hash_equals($canonicalAuthority, $requestAuthority)) {
            return null;
        }

        $requestUri = str_replace(["\r", "\n"], '', (string) ($server['REQUEST_URI'] ?? '/'));

        if ($requestUri === '' || !str_starts_with($requestUri, '/')) {
            $requestUri = '/';
        }

        return 'https://' . $canonicalAuthority . $requestUri;
    }

    public static function enforceFromEnvironment(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $trustProxy = filter_var(getenv('TRUST_PROXY') ?: 'false', FILTER_VALIDATE_BOOL);
        $target = self::redirectTarget(
            $_SERVER,
            (string) (getenv('APP_URL') ?: ''),
            $trustProxy === true
        );

        if ($target === null) {
            return;
        }

        header('Location: ' . $target, true, 308);
        header('Cache-Control: no-store');
        exit;
    }
}
