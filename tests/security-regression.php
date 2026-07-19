<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\AuditLogStore;
use App\Core\CanonicalUrlEnforcer;
use App\Core\PersonnelDataPolicy;
use App\Core\RateLimiter;
use App\Core\StateStore;
use App\Core\StatePayloadCipher;
use App\Core\UserProfileStore;
use App\Modules\Auth\PasswordResetMailer;
use App\Modules\Auth\PasswordResetStore;
use App\Modules\Leave\LeaveStore;
use App\Modules\Notifications\PushNotificationStore;
use App\Modules\Notifications\PushSubscriptionValidator;
use App\Modules\Procurement\ProcurementStore;
use App\Modules\Shift\ShiftStore;
use App\Modules\Templates\TemplateSanitizer;
use App\Modules\Templates\TemplateStore;
use App\Modules\Templates\TemplateTestMailer;

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/mytakii-security-' . bin2hex(random_bytes(8));

define('APP_ROOT', $testRoot);
require $projectRoot . '/vendor/autoload.php';

final class SecurityResetMailer extends PasswordResetMailer
{
    public array $urls = [];

    public function send(array $profile, string $resetUrl, string $expiresAt): array
    {
        $this->urls[] = $resetUrl;

        return ['ok' => true, 'status' => 'sent', 'transport' => 'test'];
    }
}

function securityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeSecurityTree(string $path): void
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
            removeSecurityTree($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

try {
    mkdir($testRoot . '/storage', 0770, true);
    putenv('APP_SESSION_SECRET=' . bin2hex(random_bytes(32)));

    $publicConfiguration = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        [
            $projectRoot . '/.env.example',
            $projectRoot . '/config/app.php',
            $projectRoot . '/app/Core/ReleaseNoteStore.php',
            $projectRoot . '/storage/release-notes.json',
        ]
    ));

    foreach (['@takii.com.tr', '@bigabilisim.com', 'alarmbigabilisim'] as $privateMarker) {
        securityAssert(
            stripos($publicConfiguration, $privateMarker) === false,
            'Public source contains a production identity or infrastructure marker: ' . $privateMarker
        );
    }

    securityAssert(
        !is_file($projectRoot . '/resources/data/personnel-organization-2026-07-14.php'),
        'A confidential personnel organization plan is present in the source tree.'
    );
    $gitignore = (string) file_get_contents($projectRoot . '/.gitignore');
    securityAssert(
        str_contains($gitignore, '/resources/data/*'),
        'Confidential organization plans are not ignored by Git.'
    );
    $htaccess = (string) file_get_contents($projectRoot . '/public/.htaccess');
    securityAssert(
        str_contains($htaccess, 'https://mytakii.com%{REQUEST_URI}') && str_contains($htaccess, '[R=308,L,NE]'),
        'The web root does not enforce the canonical HTTPS origin.'
    );

    $httpRedirect = CanonicalUrlEnforcer::redirectTarget([
        'HTTPS' => 'off',
        'HTTP_HOST' => 'attacker.example',
        'REQUEST_URI' => '/login?next=%2Fmodule%2Fleave',
    ], 'https://mytakii.com');
    securityAssert($httpRedirect === 'https://mytakii.com/login?next=%2Fmodule%2Fleave', 'HTTP did not redirect to the configured HTTPS origin.');
    securityAssert(CanonicalUrlEnforcer::redirectTarget([
        'HTTPS' => 'on',
        'HTTP_HOST' => 'mytakii.com',
        'REQUEST_URI' => '/login',
    ], 'https://mytakii.com') === null, 'Canonical HTTPS requests were redirected unnecessarily.');
    securityAssert(CanonicalUrlEnforcer::redirectTarget([
        'HTTPS' => 'off',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_HOST' => 'mytakii.com',
        'REQUEST_URI' => '/',
    ], 'https://mytakii.com', true) === null, 'Trusted proxy HTTPS was not recognized.');

    $currentDataKey = base64_encode(random_bytes(32));
    $previousDataKey = base64_encode(random_bytes(32));
    $currentCipher = StatePayloadCipher::fromEncodedKeys($currentDataKey, [$previousDataKey]);
    $encryptedPayload = $currentCipher->encrypt('user_profiles', '{"national_id":"fixture"}');
    securityAssert(str_starts_with($encryptedPayload, 'enc:v1:'), 'State payload encryption format was not applied.');
    securityAssert(!str_contains($encryptedPayload, 'national_id'), 'Encrypted state payload exposed plaintext field names.');
    securityAssert($currentCipher->decrypt('user_profiles', $encryptedPayload) === '{"national_id":"fixture"}', 'Encrypted state payload could not be decrypted.');
    $documentSwapRejected = false;

    try {
        $currentCipher->decrypt('messages', $encryptedPayload);
    } catch (RuntimeException) {
        $documentSwapRejected = true;
    }

    securityAssert($documentSwapRejected, 'Encrypted state payload could be swapped between document keys.');
    $previousCipher = StatePayloadCipher::fromEncodedKeys($previousDataKey);
    $previousPayload = $previousCipher->encrypt('user_profiles', '{"version":1}');
    securityAssert($currentCipher->decrypt('user_profiles', $previousPayload) === '{"version":1}', 'Previous state encryption key could not be used during rotation.');

    $sensitiveFixture = [
        'name' => 'Employee Fixture',
        'phone' => '555-0100',
        'personal_phone' => '555-0199',
        'birth_date' => '1990-01-01',
        'national_id' => 'sensitive-fixture-id',
        'address' => 'Sensitive fixture address',
        'emergency_contact_name' => 'Sensitive Contact',
        'emergency_contact_phone' => '555-0188',
        'hr_notes' => 'Sensitive HR note',
    ];
    $managerProjection = PersonnelDataPolicy::project($sensitiveFixture, [
        'permissions' => ['personnel.read', 'personnel.write'],
        'workforce_roles' => ['manager'],
    ]);
    securityAssert(($managerProjection['phone'] ?? '') === '555-0100', 'Manager projection removed ordinary work contact data.');
    securityAssert(!array_key_exists('national_id', $managerProjection), 'Manager projection exposed national ID.');
    securityAssert(!array_key_exists('hr_notes', $managerProjection), 'Manager projection exposed HR notes.');
    $hrProjection = PersonnelDataPolicy::project($sensitiveFixture, [
        'permissions' => ['personnel.read'],
        'workforce_roles' => ['hr_assistant_antalya'],
    ]);
    securityAssert(($hrProjection['national_id'] ?? '') === 'sensitive-fixture-id', 'Authorized HR projection removed sensitive data.');

    $stateStore = new StateStore(null, [
        'driver' => 'file',
        'auto_migrate' => true,
        'lock_timeout' => 10,
        'lock_directory' => $testRoot . '/locks',
    ]);

    $base64Url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $pushValidator = new PushSubscriptionValidator(static fn (string $host): array => ['142.250.74.42']);
    $validPushSubscription = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/security-fixture',
        'keys' => [
            'p256dh' => $base64Url("\x04" . str_repeat("\x01", 64)),
            'auth' => $base64Url(str_repeat("\x02", 16)),
        ],
    ];
    securityAssert($pushValidator->isValid($validPushSubscription), 'A valid browser push subscription was rejected.');
    securityAssert(!$pushValidator->isValid(array_replace($validPushSubscription, [
        'endpoint' => 'https://127.0.0.1/internal',
    ])), 'A loopback push endpoint was accepted.');
    securityAssert(!$pushValidator->isValid(array_replace($validPushSubscription, [
        'endpoint' => 'https://attacker.example/internal',
    ])), 'A non-provider push endpoint was accepted.');
    securityAssert(!$pushValidator->isValid(array_replace_recursive($validPushSubscription, [
        'keys' => ['auth' => 'invalid'],
    ])), 'An invalid Web Push authentication key was accepted.');
    $pushStore = new PushNotificationStore($stateStore, $pushValidator);
    securityAssert(!empty($pushStore->subscribe('security.user@example.test', $validPushSubscription)['ok']), 'A valid push subscription could not be stored.');
    securityAssert(empty($pushStore->subscribe('security.user@example.test', array_replace($validPushSubscription, [
        'endpoint' => 'https://localhost/internal',
    ]))['ok']), 'The push store accepted an SSRF endpoint.');

    $resetOutboxPath = $testRoot . '/storage/password-reset-mail-outbox.json';
    $resetOutboxGuard = $stateStore->beginWrite('password_reset_mail_outbox', $resetOutboxPath, []);
    $stateStore->write('password_reset_mail_outbox', $resetOutboxPath, [
        'version' => 1,
        'messages' => [[
            'id' => 'legacy-reset-mail',
            'text' => 'https://example.test/password-reset/' . str_repeat('a', 64),
            'status' => 'queued',
        ]],
    ]);
    $resetOutboxGuard->release();
    $secureOutboxMailer = new PasswordResetMailer($stateStore);
    $redactedResetOutbox = $stateStore->read('password_reset_mail_outbox', $resetOutboxPath, []);
    $redactedResetJson = json_encode($redactedResetOutbox, JSON_UNESCAPED_SLASHES) ?: '';
    securityAssert(!str_contains($redactedResetJson, '/password-reset/'), 'Historical password reset URL was not redacted.');
    securityAssert(!array_key_exists('text', $redactedResetOutbox['messages'][0] ?? []), 'Password reset body remained in the outbox.');

    $leaveOutboxPath = $testRoot . '/storage/leave-mail-outbox.json';
    $leaveOutboxGuard = $stateStore->beginWrite('leave_mail_outbox', $leaveOutboxPath, []);
    $stateStore->write('leave_mail_outbox', $leaveOutboxPath, [
        'version' => 1,
        'messages' => [[
            'id' => 'legacy-leave-mail',
            'body' => 'Approve: https://example.test/leave/mail-approval/' . str_repeat('b', 64) . '/approve',
            'body_html' => '<a href="https://example.test/leave/mail-approval/token/approve">Approve</a>',
            'approve_url' => 'https://example.test/leave/mail-approval/token/approve',
            'portal_url' => 'https://example.test/module/leave',
            'status' => 'sent',
        ]],
    ]);
    $leaveOutboxGuard->release();
    $leaveProfiles = new UserProfileStore([], $stateStore);
    $leaveAccess = new AccessControl($leaveProfiles->users(), require $projectRoot . '/config/modules.php', $stateStore);
    $unconfiguredLeaveStore = new LeaveStore(
        $leaveAccess,
        null,
        $stateStore,
        $leaveProfiles,
        new ShiftStore($leaveProfiles, $stateStore)
    );
    $redactedLeaveOutbox = $stateStore->read('leave_mail_outbox', $leaveOutboxPath, []);
    $redactedLeaveJson = json_encode($redactedLeaveOutbox, JSON_UNESCAPED_SLASHES) ?: '';
    securityAssert(!str_contains($redactedLeaveJson, '/leave/mail-approval/'), 'Historical leave approval URL was not redacted.');
    securityAssert(!array_key_exists('body', $redactedLeaveOutbox['messages'][0] ?? []), 'Leave mail body remained in the outbox.');
    securityAssert(!array_key_exists('portal_url', $redactedLeaveOutbox['messages'][0] ?? []), 'Leave portal URL remained in the outbox.');
    $missingApproverResult = $unconfiguredLeaveStore->create([
        'personnel_id' => 'personnel-security-fixture',
        'email' => 'leave.requester@example.test',
        'name' => 'Leave Requester',
        'department' => 'Unconfigured Department',
        'location' => 'antalya',
        'started_on' => '2020-01-01',
        'leave_opening_total_days' => 30,
        'leave_opening_used_days' => 0,
        'leave_opening_remaining_days' => 30,
        'leave_opening_snapshot_date' => '2031-12-31',
    ], [
        'starts_on' => '2032-01-05',
        'ends_on' => '2032-01-05',
        'type_key' => 'leave.type.annual',
        'day_part' => 'full',
    ]);
    securityAssert(
        empty($missingApproverResult['ok']) && ($missingApproverResult['message'] ?? '') === 'leave.flash.approver_missing',
        'A leave request was created without manager and HR approvers.'
    );

    $sanitizer = new TemplateSanitizer();
    $dirtyHtml = '<div onclick="alert(1)"><script>alert(1)</script><img src="javascript:alert(2)" onerror="alert(3)"><a href="javascript:alert(4)">link</a><p style="background:url(https://evil.test/x)">safe</p></div>';
    $cleanHtml = $sanitizer->sanitizeHtml($dirtyHtml);
    securityAssert(!str_contains(strtolower($cleanHtml), '<script'), 'Template script element was not removed.');
    securityAssert(!preg_match('/\son[a-z]+\s*=/i', $cleanHtml), 'Template event handler was not removed.');
    securityAssert(!str_contains(strtolower($cleanHtml), 'javascript:'), 'Unsafe template URL was not removed.');
    securityAssert(!str_contains(strtolower($cleanHtml), 'url('), 'Unsafe inline CSS URL was not removed.');
    $cleanCss = $sanitizer->sanitizeCss('@import "https://evil.test/x.css";a{behavior:url(x);background:url(https://evil.test/x);color:red}');
    securityAssert(!str_contains(strtolower($cleanCss), '@import'), 'CSS import was not removed.');
    securityAssert(!str_contains(strtolower($cleanCss), 'behavior:'), 'Legacy CSS execution primitive was not removed.');
    securityAssert(!str_contains(strtolower($cleanCss), 'url('), 'CSS URL was not removed.');

    $templateStore = new TemplateStore($stateStore, $sanitizer);
    $templateResult = $templateStore->save(['name' => 'Security'], [
        'name' => 'Security template',
        'type' => 'mail',
        'html' => '<p onclick="alert(1)">Stored safely</p><script>alert(2)</script>',
        'css' => 'p{background:url(https://evil.test/x);color:#111}',
    ]);
    securityAssert(!empty($templateResult['ok']), 'Sanitized template could not be stored.');
    $storedTemplate = (new TemplateStore($stateStore, $sanitizer))->find((string) ($templateResult['id'] ?? ''));
    securityAssert(is_array($storedTemplate), 'Template was not persisted through StateStore.');
    securityAssert(!str_contains(strtolower((string) ($storedTemplate['html'] ?? '')), 'onclick'), 'Stored template retained an event handler.');
    securityAssert(!str_contains(strtolower((string) ($storedTemplate['css'] ?? '')), 'url('), 'Stored template retained a CSS URL.');

    $templateMailer = new TemplateTestMailer($stateStore, $sanitizer);
    $templateMailResult = $templateMailer->send(['email' => 'author@example.test', 'name' => 'Author'], [
        'to_email' => 'recipient@example.test',
        'template_id' => 'security-template',
        'template_name' => 'Security template',
        'type' => 'mail',
        'subject' => "Security\r\nBcc: attacker@example.test",
        'html' => '<p>Safe test mail</p>',
        'css' => 'p{color:#111}',
    ]);
    securityAssert(!empty($templateMailResult['ok']), 'Template test mail could not be queued.');
    securityAssert((new TemplateTestMailer($stateStore, $sanitizer))->lastRecipientForUser('author@example.test') === 'recipient@example.test', 'Template outbox was not persisted through StateStore.');

    $auditLog = new AuditLogStore($stateStore);
    $auditLog->record(['email' => 'security@example.test', 'name' => 'Security'], 'security.test', 'fixture', '1');
    securityAssert((new AuditLogStore($stateStore))->recent(1)[0]['action'] === 'security.test', 'Audit log was not persisted through StateStore.');

    $procurement = new ProcurementStore($stateStore);
    $procurementResult = $procurement->create(['name' => 'Security', 'department' => 'Test'], [
        'title' => 'Security fixture',
        'vendor' => 'Example Vendor',
        'category' => 'Software',
        'amount' => '100,00',
        'needed_on' => '2030-01-10',
        'reason' => 'State persistence test',
    ]);
    securityAssert(!empty($procurementResult['ok']), 'Procurement fixture could not be created.');
    securityAssert(count((new ProcurementStore($stateStore))->all()) === 1, 'Procurement request was not persisted through StateStore.');

    $profiles = new UserProfileStore([], $stateStore);
    $managerExport = $profiles->exportProfilesCsv([$managerProjection]);
    securityAssert(!str_contains($managerExport, 'sensitive-fixture-id'), 'Manager CSV export exposed national ID.');
    securityAssert(!str_contains($managerExport, 'Sensitive HR note'), 'Manager CSV export exposed HR notes.');
    $created = $profiles->createProfile([
        'new_email' => 'security.user@example.test',
        'first_name' => 'Security',
        'last_name' => 'User',
        'role' => 'Personel',
        'department' => 'Test',
        'password' => 'Security-1234',
        'password_confirmation' => 'Security-1234',
    ]);
    securityAssert(!empty($created['ok']), 'Security test profile could not be created.');
    putenv('PASSWORD_RESET_MAIL_TRANSPORT=outbox');
    $undeliveredReset = (new PasswordResetStore($profiles, $secureOutboxMailer, $stateStore))
        ->request('security.user@example.test', 'https://example.test');
    securityAssert(empty($undeliveredReset['sent']), 'Insecure reset outbox was reported as delivered.');
    $resetState = $stateStore->read('password_resets', $testRoot . '/storage/password-resets.json', []);
    $lastReset = end($resetState['requests']);
    securityAssert(($lastReset['invalidated_reason'] ?? '') === 'mail_delivery_failed', 'Undelivered reset token remained active.');
    putenv('PASSWORD_RESET_MAIL_TRANSPORT');
    $csv = $profiles->exportProfilesCsv([[
        'email' => '=WEBSERVICE("https://evil.test")',
        'first_name' => '+SUM(1,1)',
        'last_name' => '-2+3',
        'role' => '@malicious',
    ]]);
    securityAssert(str_contains($csv, "'=WEBSERVICE"), 'CSV formula in email was not neutralized.');
    securityAssert(str_contains($csv, "'+SUM"), 'CSV formula in name was not neutralized.');
    securityAssert(str_contains($csv, "'-2+3"), 'CSV formula with minus was not neutralized.');
    securityAssert(str_contains($csv, "'@malicious"), 'CSV formula with at sign was not neutralized.');

    $rateLimiter = new RateLimiter($stateStore);
    securityAssert($rateLimiter->attempt('login', 'security-user', 2, 60), 'First rate-limited attempt was blocked.');
    securityAssert($rateLimiter->attempt('login', 'security-user', 2, 60), 'Second rate-limited attempt was blocked.');
    securityAssert(!$rateLimiter->attempt('login', 'security-user', 2, 60), 'Rate limiter did not block the excess attempt.');
    $rateLimiter->clear('login', 'security-user');
    securityAssert($rateLimiter->attempt('login', 'security-user', 2, 60), 'Rate-limit bucket could not be cleared.');

    $mailer = new SecurityResetMailer($stateStore);
    $resets = new PasswordResetStore($profiles, $mailer, $stateStore);
    $resets->request('security.user@example.test', 'https://example.test');
    $resets->request('security.user@example.test', 'https://example.test');
    securityAssert(count($mailer->urls) === 2, 'Password reset messages were not produced.');
    $firstToken = basename((string) parse_url($mailer->urls[0], PHP_URL_PATH));
    $secondToken = basename((string) parse_url($mailer->urls[1], PHP_URL_PATH));
    securityAssert($resets->validateToken($firstToken) === null, 'Superseded password reset token remained active.');
    securityAssert($resets->validateToken($secondToken) !== null, 'Newest password reset token is invalid.');
    securityAssert(empty($resets->reset($secondToken, 'short', 'short')['ok']), 'Short password was accepted.');
    securityAssert(!empty($resets->reset($secondToken, 'Updated-Password-123', 'Updated-Password-123')['ok']), 'Valid password reset failed.');
    securityAssert($resets->validateToken($secondToken) === null, 'Used password reset token remained active.');

    $hr = $profiles->createProfile([
        'new_email' => 'security.hr@example.test',
        'first_name' => 'Security',
        'last_name' => 'HR',
        'role' => 'Muhasebe Müdürü',
        'department' => 'Test',
        'workforce_roles' => ['hr'],
    ]);
    $assistant = $profiles->createProfile([
        'new_email' => 'security.assistant@example.test',
        'first_name' => 'Security',
        'last_name' => 'Assistant',
        'role' => 'Muhasebe Asistanı',
        'department' => 'Test',
        'workforce_roles' => ['hr_assistant_antalya'],
    ]);
    securityAssert(!empty($hr['ok']) && !empty($assistant['ok']), 'Workforce-role fixtures could not be created.');
    $access = new AccessControl($profiles->users(), require $projectRoot . '/config/modules.php', $stateStore);
    $hrPermissions = $access->permissionsFor('security.hr@example.test');
    $assistantPermissions = $access->permissionsFor('security.assistant@example.test');
    securityAssert(in_array('leave.policy.manage', $hrPermissions, true), 'HR manager lacks leave policy permission.');
    securityAssert(!in_array('leave.policy.manage', $assistantPermissions, true), 'Regional HR assistant received global leave policy permission.');

    echo "Security regression passed: XSS, CSV, mail redaction, rate limits, reset tokens, and role boundaries verified.\n";
} finally {
    putenv('APP_SESSION_SECRET');
    removeSecurityTree($testRoot);
}
