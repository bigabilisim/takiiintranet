<?php

namespace App\Modules\Auth;

use App\Core\StateStore;
use App\Core\UserProfileStore;
use DateInterval;
use DateTimeImmutable;

class PasswordResetStore
{
    private const VERSION = 1;
    private const STATE_KEY = 'password_resets';
    private const TOKEN_TTL_HOURS = 2;
    private const MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly UserProfileStore $userProfiles,
        private readonly PasswordResetMailer $mailer,
        private readonly StateStore $stateStore,
    ) {
    }

    public function request(string $email, string $baseUrl): array
    {
        $profile = $this->userProfiles->profileForPasswordReset($email);

        if ($profile === null) {
            return ['ok' => true, 'message' => 'auth.password_reset.requested', 'sent' => false];
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT' . self::TOKEN_TTL_HOURS . 'H'));
        $resetUrl = rtrim($baseUrl, '/') . '/password-reset/' . $token;
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $data = $this->loadData();

        foreach ($data['requests'] as $index => $existingRequest) {
            if ((string) ($existingRequest['profile_key'] ?? '') !== (string) ($profile['profile_key'] ?? '')
                || (string) ($existingRequest['used_at'] ?? '') !== '') {
                continue;
            }

            $data['requests'][$index]['used_at'] = date('Y-m-d H:i');
            $data['requests'][$index]['invalidated_at'] = date('Y-m-d H:i');
            $data['requests'][$index]['invalidated_reason'] = 'superseded_by_new_request';
        }

        $record = [
            'id' => 'PWR-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
            'email' => (string) ($profile['email'] ?? ''),
            'profile_key' => (string) ($profile['profile_key'] ?? ''),
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt->format('Y-m-d H:i'),
            'used_at' => '',
            'requested_at' => date('Y-m-d H:i'),
            'mail_status' => 'pending',
            'mail_transport' => '',
        ];

        $data['requests'][] = $record;
        $data = $this->prune($data);
        $mailResult = $this->mailer->send($profile, $resetUrl, $record['expires_at']);

        foreach ($data['requests'] as $index => $request) {
            if (($request['id'] ?? '') !== $record['id']) {
                continue;
            }

            $data['requests'][$index]['mail_status'] = (string) ($mailResult['status'] ?? 'unknown');
            $data['requests'][$index]['mail_transport'] = (string) ($mailResult['transport'] ?? '');

            if (empty($mailResult['ok'])) {
                $data['requests'][$index]['used_at'] = date('Y-m-d H:i');
                $data['requests'][$index]['invalidated_at'] = date('Y-m-d H:i');
                $data['requests'][$index]['invalidated_reason'] = 'mail_delivery_failed';
            }

            break;
        }

        $this->saveData($data);

        return ['ok' => true, 'message' => 'auth.password_reset.requested', 'sent' => !empty($mailResult['ok'])];
    }

    public function validateToken(string $token): ?array
    {
        $token = trim($token);

        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);

        foreach ($this->loadData()['requests'] as $request) {
            if (!hash_equals((string) ($request['token_hash'] ?? ''), $hash)) {
                continue;
            }

            if (($request['used_at'] ?? '') !== '' || $this->isExpired((string) ($request['expires_at'] ?? ''))) {
                return null;
            }

            $profile = $this->userProfiles->profileForPasswordReset((string) ($request['email'] ?? ''));

            if ($profile === null || (string) ($profile['profile_key'] ?? '') !== (string) ($request['profile_key'] ?? '')) {
                return null;
            }

            return array_merge($request, ['profile' => $profile]);
        }

        return null;
    }

    public function reset(string $token, string $password, string $passwordConfirmation): array
    {
        if ($password === '' || strlen($password) < self::MIN_PASSWORD_LENGTH || strlen($password) > 4096 || $password !== $passwordConfirmation) {
            return ['ok' => false, 'message' => 'auth.password_reset.password_invalid'];
        }

        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $record = $this->validateToken($token);

        if ($record === null) {
            return ['ok' => false, 'message' => 'auth.password_reset.invalid'];
        }

        if (!$this->userProfiles->setPasswordForProfileKey((string) ($record['profile_key'] ?? ''), $password)) {
            return ['ok' => false, 'message' => 'auth.password_reset.invalid'];
        }

        $data = $this->loadData();
        $hash = hash('sha256', trim($token));

        foreach ($data['requests'] as $index => $request) {
            if ((string) ($request['profile_key'] ?? '') !== (string) ($record['profile_key'] ?? '')
                || (string) ($request['used_at'] ?? '') !== '') {
                continue;
            }

            $data['requests'][$index]['used_at'] = date('Y-m-d H:i');
            $data['requests'][$index]['invalidated_at'] = date('Y-m-d H:i');
            $data['requests'][$index]['invalidated_reason'] = hash_equals((string) ($request['token_hash'] ?? ''), $hash)
                ? 'password_reset_completed'
                : 'password_changed';
        }

        $this->saveData($data);

        return ['ok' => true, 'message' => 'auth.password_reset.completed'];
    }

    public function revokeForIdentity(string $oldProfileKey, string $oldEmail, string $reason = 'identity_changed'): int
    {
        $identities = array_values(array_unique(array_filter([$oldProfileKey, $oldEmail])));

        if ($identities === []) {
            return 0;
        }

        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $data = $this->loadData();
        $revoked = 0;

        foreach ($data['requests'] as $index => $request) {
            if (!is_array($request) || (string) ($request['used_at'] ?? '') !== '') {
                continue;
            }

            if (!in_array((string) ($request['profile_key'] ?? ''), $identities, true)
                && !in_array((string) ($request['email'] ?? ''), $identities, true)) {
                continue;
            }

            $data['requests'][$index]['used_at'] = date('Y-m-d H:i');
            $data['requests'][$index]['invalidated_at'] = date('Y-m-d H:i');
            $data['requests'][$index]['invalidated_reason'] = $reason;
            $revoked++;
        }

        if ($revoked > 0) {
            $this->saveData($data);
        }

        return $revoked;
    }

    private function loadData(): array
    {
        $decoded = $this->stateStore->read(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $data = is_array($decoded) ? $decoded : [];
        $data['version'] = self::VERSION;
        $data['requests'] = is_array($data['requests'] ?? null) ? $data['requests'] : [];

        return $data;
    }

    private function saveData(array $data): void
    {
        $data['version'] = self::VERSION;
        $data['requests'] = is_array($data['requests'] ?? null) ? $data['requests'] : [];
        $this->stateStore->write(self::STATE_KEY, $this->dataPath(), $data);
    }

    private function emptyData(): array
    {
        return ['version' => self::VERSION, 'requests' => []];
    }

    private function prune(array $data): array
    {
        $data['requests'] = array_values(array_filter(
            is_array($data['requests'] ?? null) ? $data['requests'] : [],
            fn (array $request): bool => ($request['used_at'] ?? '') === '' || !$this->isOlderThanDays((string) ($request['used_at'] ?? ''), 7)
        ));

        return $data;
    }

    private function isExpired(string $expiresAt): bool
    {
        if ($expiresAt === '') {
            return true;
        }

        return strtotime($expiresAt) < time();
    }

    private function isOlderThanDays(string $date, int $days): bool
    {
        if ($date === '') {
            return false;
        }

        return strtotime($date) < strtotime('-' . $days . ' days');
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/password-resets.json';
    }
}
