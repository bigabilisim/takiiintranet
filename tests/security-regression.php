<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\AuditLogStore;
use App\Core\RateLimiter;
use App\Core\StateStore;
use App\Core\UserProfileStore;
use App\Modules\Auth\PasswordResetMailer;
use App\Modules\Auth\PasswordResetStore;
use App\Modules\Leave\LeaveStore;
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
    $stateStore = new StateStore(null, [
        'driver' => 'file',
        'auto_migrate' => true,
        'lock_timeout' => 10,
        'lock_directory' => $testRoot . '/locks',
    ]);

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
    new LeaveStore($leaveAccess, null, $stateStore, $leaveProfiles, new ShiftStore($leaveProfiles, $stateStore));
    $redactedLeaveOutbox = $stateStore->read('leave_mail_outbox', $leaveOutboxPath, []);
    $redactedLeaveJson = json_encode($redactedLeaveOutbox, JSON_UNESCAPED_SLASHES) ?: '';
    securityAssert(!str_contains($redactedLeaveJson, '/leave/mail-approval/'), 'Historical leave approval URL was not redacted.');
    securityAssert(!array_key_exists('body', $redactedLeaveOutbox['messages'][0] ?? []), 'Leave mail body remained in the outbox.');
    securityAssert(!array_key_exists('portal_url', $redactedLeaveOutbox['messages'][0] ?? []), 'Leave portal URL remained in the outbox.');

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
