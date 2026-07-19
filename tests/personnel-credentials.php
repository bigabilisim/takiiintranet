<?php

declare(strict_types=1);

use App\Core\StateStore;
use App\Core\UserProfileStore;
use App\Modules\Auth\PasswordResetMailer;
use App\Modules\Auth\PasswordResetStore;
use App\Modules\Auth\PersonnelCredentialService;

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/takii-personnel-credentials-' . bin2hex(random_bytes(8));

define('APP_ROOT', $testRoot);
require $projectRoot . '/vendor/autoload.php';

final class PersonnelCredentialTestMailer extends PasswordResetMailer
{
    public bool $shouldSend = true;
    public array $credentials = [];

    public function send(array $profile, string $resetUrl, string $expiresAt): array
    {
        return ['ok' => true, 'status' => 'sent', 'transport' => 'test'];
    }

    public function sendTemporaryPassword(array $profile, string $username, string $temporaryPassword): array
    {
        $this->credentials[] = [
            'email' => (string) ($profile['email'] ?? ''),
            'username' => $username,
            'password' => $temporaryPassword,
        ];

        return $this->shouldSend
            ? ['ok' => true, 'status' => 'sent', 'transport' => 'test']
            : ['ok' => false, 'status' => 'not_sent', 'transport' => 'test'];
    }
}

function credentialAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeCredentialTestTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . '/' . $entry;

        if (is_dir($child)) {
            removeCredentialTestTree($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

try {
    mkdir($testRoot . '/storage', 0770, true);
    $stateStore = new StateStore(null, [
        'driver' => 'file',
        'auto_migrate' => true,
        'lock_timeout' => 10,
        'lock_directory' => $testRoot . '/locks',
    ]);
    $profiles = new UserProfileStore([], $stateStore);
    $mailer = new PersonnelCredentialTestMailer($stateStore);
    $resets = new PasswordResetStore($profiles, $mailer, $stateStore);
    $credentials = new PersonnelCredentialService($profiles, $resets, $mailer);

    $emailProfile = $profiles->createProfile([
        'new_email' => 'deniz@example.test',
        'first_name' => 'Deniz',
        'last_name' => 'Örnek',
        'role' => 'HR Specialist',
        'department' => 'İnsan Kaynakları',
        'password' => 'Initial-1234',
        'password_confirmation' => 'Initial-1234',
    ]);
    credentialAssert(($emailProfile['ok'] ?? false) === true, 'Email profile could not be created.');
    credentialAssert(($emailProfile['username'] ?? '') === 'denizornek', 'Turkish username was not generated as isimsoyisim.');

    $duplicateName = $profiles->createProfile([
        'first_name' => 'Deniz',
        'last_name' => 'Örnek',
        'role' => 'HR Assistant',
        'department' => 'İnsan Kaynakları',
    ]);
    credentialAssert(($duplicateName['ok'] ?? false) === true, 'Duplicate-name profile could not be created.');
    credentialAssert(($duplicateName['username'] ?? '') === 'denizornek2', 'Duplicate username suffix was not generated.');

    $ibrahim = $profiles->createProfile([
        'first_name' => 'İbrahim',
        'last_name' => 'Gürbüz',
        'role' => 'Personel',
        'department' => 'Takii Gazileri - Mavi Yaka',
    ]);
    credentialAssert(($ibrahim['ok'] ?? false) === true, 'No-email profile could not be created.');
    credentialAssert(($ibrahim['username'] ?? '') === 'ibrahimgurbuz', 'No-email username was not generated correctly.');

    $duplicateUpdate = $profiles->updateProfile((string) $duplicateName['profile_key'], [
        'username' => 'denizornek',
        'first_name' => 'Deniz',
        'last_name' => 'Örnek',
        'role' => 'HR Assistant',
        'department' => 'İnsan Kaynakları',
    ]);
    credentialAssert(($duplicateUpdate['ok'] ?? true) === false, 'Duplicate username update was accepted.');
    credentialAssert(($duplicateUpdate['message'] ?? '') === 'personnel.flash.username_duplicate', 'Duplicate username error was not returned.');

    $customUpdate = $profiles->updateProfile((string) $duplicateName['profile_key'], [
        'username' => 'deniztest',
        'first_name' => 'Deniz',
        'last_name' => 'Örnek',
        'role' => 'HR Assistant',
        'department' => 'İnsan Kaynakları',
    ]);
    credentialAssert(($customUpdate['ok'] ?? false) === true, 'Editable username could not be saved.');

    $resets->request('deniz@example.test', 'https://intranet.example.test');
    $emailReset = $credentials->reset((string) $emailProfile['profile_key']);
    credentialAssert(($emailReset['ok'] ?? false) === true, 'Email credential reset failed.');
    credentialAssert(($emailReset['delivery'] ?? '') === 'email', 'Email credential was not marked as emailed.');
    credentialAssert(($emailReset['password'] ?? '') === '', 'Emailed password leaked through the service result.');
    $emailedCredential = $mailer->credentials[array_key_last($mailer->credentials)] ?? [];
    credentialAssert(($emailedCredential['username'] ?? '') === 'denizornek', 'Username was not included in the credential email.');
    credentialAssert(
        $profiles->verifyCredentials('denizornek', (string) ($emailedCredential['password'] ?? '')) !== null,
        'Username login failed after email credential reset.'
    );

    $resetDocument = $stateStore->read('password_resets', $testRoot . '/storage/password-resets.json');
    $resetRequest = $resetDocument['requests'][0] ?? [];
    credentialAssert(($resetRequest['invalidated_reason'] ?? '') === 'password_reset_by_admin', 'Outstanding reset token was not revoked.');

    $screenReset = $credentials->reset((string) $ibrahim['profile_key']);
    credentialAssert(($screenReset['delivery'] ?? '') === 'screen', 'No-email credential was not assigned to screen delivery.');
    credentialAssert((string) ($screenReset['password'] ?? '') !== '', 'No-email password was not returned for one-time display.');
    credentialAssert(
        $profiles->verifyCredentials('ibrahimgurbuz', (string) $screenReset['password']) !== null,
        'No-email username login failed after credential reset.'
    );

    $mailer->shouldSend = false;
    $fallbackReset = $credentials->reset((string) $emailProfile['profile_key']);
    credentialAssert(($fallbackReset['delivery'] ?? '') === 'screen_fallback', 'Mail failure did not fall back to one-time screen delivery.');
    credentialAssert((string) ($fallbackReset['password'] ?? '') !== '', 'Fallback password was not returned for one-time display.');

    $profileStorage = (string) file_get_contents($testRoot . '/storage/user-profiles.json');
    credentialAssert(!str_contains($profileStorage, (string) $screenReset['password']), 'No-email plaintext password was persisted.');
    credentialAssert(!str_contains($profileStorage, (string) $fallbackReset['password']), 'Fallback plaintext password was persisted.');

    echo "Personnel credential test passed: usernames, login, email delivery, screen fallback, and token revocation.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Personnel credential test failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    removeCredentialTestTree($testRoot);
}
