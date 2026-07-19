<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use SodiumException;

final class StatePayloadCipher
{
    private const FORMAT_PREFIX = 'enc:v1:';
    private const KEY_BYTES = 32;
    private const NONCE_BYTES = 24;

    /** @param array<string, string> $decryptionKeys */
    private function __construct(
        private readonly ?string $currentKey,
        private readonly string $currentKeyId,
        private readonly array $decryptionKeys,
    ) {
    }

    public static function disabled(): self
    {
        return new self(null, '', []);
    }

    public static function fromEncodedKeys(string $currentKey, array|string $previousKeys = [], bool $required = true): self
    {
        $currentKey = trim($currentKey);

        if ($currentKey === '') {
            if ($required) {
                throw new RuntimeException('APP_DATA_ENCRYPTION_KEY is required for MariaDB state storage.');
            }

            return self::disabled();
        }

        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new RuntimeException('The sodium PHP extension is required for encrypted state storage.');
        }

        $decodedCurrentKey = self::decodeKey($currentKey);
        $currentKeyId = self::keyId($decodedCurrentKey);
        $decryptionKeys = [$currentKeyId => $decodedCurrentKey];
        $previousKeys = is_array($previousKeys)
            ? $previousKeys
            : preg_split('/\s*,\s*/', trim($previousKeys), -1, PREG_SPLIT_NO_EMPTY);

        foreach (is_array($previousKeys) ? $previousKeys : [] as $previousKey) {
            $previousKey = trim((string) $previousKey);

            if ($previousKey === '') {
                continue;
            }

            $decodedPreviousKey = self::decodeKey($previousKey);
            $decryptionKeys[self::keyId($decodedPreviousKey)] = $decodedPreviousKey;
        }

        return new self($decodedCurrentKey, $currentKeyId, $decryptionKeys);
    }

    public function enabled(): bool
    {
        return $this->currentKey !== null;
    }

    public function currentPrefix(): string
    {
        if (!$this->enabled()) {
            return '';
        }

        return self::FORMAT_PREFIX . $this->currentKeyId . ':';
    }

    public function isEncrypted(string $payload): bool
    {
        return str_starts_with($payload, self::FORMAT_PREFIX);
    }

    public function usesCurrentKey(string $payload): bool
    {
        return $this->enabled() && str_starts_with($payload, $this->currentPrefix());
    }

    public function encrypt(string $documentKey, string $plaintext): string
    {
        if ($this->currentKey === null) {
            return $plaintext;
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $this->additionalData($documentKey),
            $nonce,
            $this->currentKey
        );

        return $this->currentPrefix() . self::base64UrlEncode($nonce . $ciphertext);
    }

    public function decrypt(string $documentKey, string $payload): string
    {
        if (!$this->isEncrypted($payload)) {
            return $payload;
        }

        $parts = explode(':', $payload, 4);

        if (count($parts) !== 4 || $parts[0] !== 'enc' || $parts[1] !== 'v1') {
            throw new RuntimeException(sprintf('Unsupported encryption format for state document "%s".', $documentKey));
        }

        $keyId = $parts[2];
        $key = $this->decryptionKeys[$keyId] ?? null;

        if (!is_string($key)) {
            throw new RuntimeException(sprintf('No decryption key is available for state document "%s".', $documentKey));
        }

        $combined = self::base64UrlDecode($parts[3]);

        if ($combined === null || strlen($combined) <= self::NONCE_BYTES) {
            throw new RuntimeException(sprintf('Encrypted payload is malformed for state document "%s".', $documentKey));
        }

        $nonce = substr($combined, 0, self::NONCE_BYTES);
        $ciphertext = substr($combined, self::NONCE_BYTES);

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $this->additionalData($documentKey),
                $nonce,
                $key
            );
        } catch (SodiumException $exception) {
            throw new RuntimeException(sprintf('Unable to decrypt state document "%s".', $documentKey), 0, $exception);
        }

        if (!is_string($plaintext)) {
            throw new RuntimeException(sprintf('Unable to decrypt state document "%s".', $documentKey));
        }

        return $plaintext;
    }

    private static function decodeKey(string $encodedKey): string
    {
        if (str_starts_with($encodedKey, 'base64:')) {
            $encodedKey = substr($encodedKey, strlen('base64:'));
        }

        $decoded = base64_decode($encodedKey, true);

        if (!is_string($decoded) || strlen($decoded) !== self::KEY_BYTES) {
            throw new RuntimeException('State encryption keys must be base64-encoded 32-byte values.');
        }

        return $decoded;
    }

    private static function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }

    private function additionalData(string $documentKey): string
    {
        return 'mytakii-state:v1:' . $documentKey;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);

        return is_string($decoded) ? $decoded : null;
    }
}
