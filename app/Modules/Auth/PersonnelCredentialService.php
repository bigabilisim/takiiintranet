<?php

namespace App\Modules\Auth;

use App\Core\UserProfileStore;

final class PersonnelCredentialService
{
    public function __construct(
        private readonly UserProfileStore $userProfiles,
        private readonly PasswordResetStore $passwordResets,
        private readonly PasswordResetMailer $mailer,
    ) {
    }

    public function reset(string $profileKey): array
    {
        $profile = $this->userProfiles->find($profileKey);

        if ($profile === null) {
            return ['ok' => false, 'message' => 'personnel.flash.password_reset_failed'];
        }

        $actualProfileKey = (string) ($profile['profile_key'] ?? $profileKey);
        $username = (string) ($profile['username'] ?? '');
        $password = $this->temporaryPassword();

        if ($actualProfileKey === '' || $username === '' || !$this->userProfiles->setPasswordForProfileKey($actualProfileKey, $password)) {
            return ['ok' => false, 'message' => 'personnel.flash.password_reset_failed'];
        }

        $email = trim((string) ($profile['email'] ?? ''));
        $revokedTokens = $this->passwordResets->revokeForIdentity(
            $actualProfileKey,
            $email,
            'password_reset_by_admin'
        );
        $result = [
            'ok' => true,
            'profile_key' => $actualProfileKey,
            'name' => (string) ($profile['name'] ?? ''),
            'username' => $username,
            'email' => $email,
            'password' => '',
            'delivery' => 'screen',
            'mail_transport' => 'none',
            'revoked_tokens' => $revokedTokens,
            'message' => 'personnel.flash.password_shown',
        ];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['password'] = $password;

            return $result;
        }

        $mail = $this->mailer->sendTemporaryPassword($profile, $username, $password);
        $result['mail_transport'] = (string) ($mail['transport'] ?? 'none');

        if (!empty($mail['ok']) && ($mail['status'] ?? '') === 'sent') {
            $result['delivery'] = 'email';
            $result['message'] = 'personnel.flash.password_emailed';

            return $result;
        }

        $result['password'] = $password;
        $result['delivery'] = 'screen_fallback';
        $result['message'] = 'personnel.flash.password_mail_failed';

        return $result;
    }

    private function temporaryPassword(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
        $password = [
            $this->randomCharacter('ABCDEFGHJKLMNPQRSTUVWXYZ'),
            $this->randomCharacter('abcdefghijkmnopqrstuvwxyz'),
            $this->randomCharacter('23456789'),
            $this->randomCharacter('!@#$%'),
        ];

        while (count($password) < 12) {
            $password[] = $this->randomCharacter($characters);
        }

        for ($index = count($password) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$password[$index], $password[$swap]] = [$password[$swap], $password[$index]];
        }

        return implode('', $password);
    }

    private function randomCharacter(string $characters): string
    {
        return $characters[random_int(0, strlen($characters) - 1)];
    }
}
