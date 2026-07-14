<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\Database;
use App\Core\Session;
use App\Core\StateStore;
use App\Core\UserIdentityMigrationService;
use App\Core\UserProfileStore;
use App\Modules\Auth\PasswordResetMailer;
use App\Modules\Auth\PasswordResetStore;
use App\Modules\Leave\LeaveApprovalMailer;
use App\Modules\Leave\LeaveStore;
use App\Modules\Messaging\MessageStore;
use App\Modules\Notifications\PushNotificationStore;
use App\Modules\Shift\ShiftStore;

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/takii-identity-test-' . bin2hex(random_bytes(8));
$testDriver = strtolower(trim((string) (getenv('TEST_STATE_DRIVER') ?: 'file')));
$databaseConnection = null;
$documentKeys = [
    'password_resets',
    'user_profiles',
    'access_control',
    'leave_requests',
    'leave_mail_outbox',
    'messages',
    'push_subscriptions',
    'shifts',
];

define('APP_ROOT', $testRoot);
require $projectRoot . '/vendor/autoload.php';

function identityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeIdentityTestTree(string $path): void
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
            removeIdentityTestTree($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

function containsIdentityReference(mixed $value, string $identity): bool
{
    if (is_string($value)) {
        return $value === $identity;
    }

    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $key => $item) {
        if ((string) $key === $identity || containsIdentityReference($item, $identity)) {
            return true;
        }
    }

    return false;
}

function identityPlanKey(string $month, string $identity): string
{
    $slug = substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($identity)) ?? '', '-'), 0, 80);

    return substr(trim(preg_replace('/[^a-z0-9-]+/', '', strtolower($month . '-' . $slug)) ?? '', '-'), 0, 80);
}

function identityUpdateInput(string $email, string $department): array
{
    return [
        'new_email' => $email,
        'first_name' => 'Identity',
        'last_name' => 'Migration',
        'role' => 'Weekend Duty Specialist',
        'department' => $department,
        'pdks_id' => 'IDENTITY-001',
        'started_on' => '2020-01-01',
        'employment_type' => 'full_time',
        'phone' => '',
        'personal_phone' => '',
        'birth_date' => '1990-01-01',
        'leave_opening_total_days' => '30',
        'leave_opening_used_days' => '0',
        'leave_opening_remaining_days' => '30',
        'leave_opening_snapshot_date' => '2029-12-31',
        'leave_opening_source' => 'identity-test',
        'national_id' => '',
        'address' => '',
        'emergency_contact_name' => '',
        'emergency_contact_phone' => '',
        'education_level' => 'bachelor',
        'school' => 'Test University',
        'faculty' => 'Engineering',
        'graduation_year' => '2012',
        'hr_notes' => '',
        'workforce_roles' => ['weekend_duty'],
        'shift_key' => 'gunduz-shift',
        'password' => '',
        'password_confirmation' => '',
    ];
}

try {
    mkdir($testRoot . '/storage', 0770, true);

    foreach ([
        'access-control.json',
        'leave-mail-outbox.json',
        'leave-requests.json',
        'messages.json',
        'push-subscriptions.json',
        'shifts.json',
        'user-profiles.json',
    ] as $filename) {
        $source = $projectRoot . '/storage/' . $filename;

        if (is_file($source)) {
            copy($source, $testRoot . '/storage/' . $filename);
        }
    }

    putenv('APP_URL=https://identity.example.test');
    putenv('MAIL_TRANSPORT=outbox');
    putenv('PASSWORD_RESET_MAIL_TRANSPORT=outbox');
    Session::start();
    $appConfig = require $projectRoot . '/config/app.php';
    $modules = require $projectRoot . '/config/modules.php';
    $database = $testDriver === 'mariadb'
        ? new Database(require $projectRoot . '/config/database.php')
        : null;
    $databaseConnection = $database?->connection();

    $stateStore = new StateStore($database, [
        'driver' => $testDriver,
        'auto_migrate' => true,
        'lock_timeout' => 20,
        'lock_directory' => $testRoot . '/locks',
    ]);

    if ($databaseConnection instanceof PDO) {
        $stateStore->metadata('user_profiles');
        $databaseConnection->exec(
            "DELETE FROM app_state_documents WHERE document_key IN ('"
            . implode("','", $documentKeys)
            . "')"
        );
    }

    $profiles = new UserProfileStore($appConfig['demo_users'], $stateStore);
    $oldIdentity = 'identity.old@takii.com.tr';
    $newIdentity = 'identity.new@takii.com.tr';
    $department = 'Identity Test Department';
    $created = $profiles->createProfile(array_merge(
        identityUpdateInput($oldIdentity, $department),
        [
            'password' => 'identity-test-123',
            'password_confirmation' => 'identity-test-123',
        ]
    ));
    identityAssert(!empty($created['ok']), 'Identity fixture profile could not be created.');

    $directory = $profiles->users();
    $access = new AccessControl($directory, $modules, $stateStore);
    $messages = new MessageStore($directory, $stateStore);
    $push = new PushNotificationStore($stateStore);
    $shifts = new ShiftStore($profiles, $stateStore);
    $leave = new LeaveStore($access, new LeaveApprovalMailer(), $stateStore, $profiles, $shifts);
    $passwordResets = new PasswordResetStore($profiles, new PasswordResetMailer(), $stateStore);
    $identityMigration = new UserIdentityMigrationService(
        $stateStore,
        $profiles,
        $access,
        $leave,
        $messages,
        $push,
        $shifts,
        $passwordResets
    );
    $permissions = [
        'module.leave.access',
        'leave.request.create',
        'module.messages.access',
        'messaging.send',
        'module.personnel.access',
        'personnel.read',
    ];
    $access->setUserPermissions($oldIdentity, $permissions);
    identityAssert($access->setDepartmentPolicy($department, [
        'manager_approval_count' => 1,
        'manager_1_email' => $oldIdentity,
        'hr_email' => 'y.ekici@takii.com.tr',
    ]), 'Identity department policy could not be created.');
    $permissionsBefore = $access->permissionsFor($oldIdentity);

    $identityUser = $profiles->find($oldIdentity);
    identityAssert(is_array($identityUser), 'Identity fixture profile could not be loaded.');
    identityAssert(!empty($messages->send($identityUser, [
        'to_email' => 'y.ekici@takii.com.tr',
        'subject' => 'Identity migration message',
        'body' => 'Message ownership must follow the profile identity.',
    ])['ok']), 'Identity fixture message could not be created.');
    identityAssert(!empty($messages->togglePin($oldIdentity, 'y.ekici@takii.com.tr')['ok']), 'Source pin could not be created.');
    identityAssert(!empty($messages->togglePin('y.ekici@takii.com.tr', $oldIdentity)['ok']), 'Counterpart pin could not be created.');
    $subscription = [
        'endpoint' => 'https://push.example.test/identity-' . bin2hex(random_bytes(4)),
        'keys' => ['p256dh' => 'identity-public-key', 'auth' => 'identity-auth-token'],
    ];
    identityAssert(!empty($push->subscribe($oldIdentity, $subscription)['ok']), 'Identity push subscription could not be created.');
    identityAssert(!empty($leave->create($identityUser, [
        'starts_on' => '2031-01-06',
        'ends_on' => '2031-01-06',
        'type_key' => 'leave.type.annual',
        'day_part' => 'full',
        'note' => 'Identity migration leave',
    ])['ok']), 'Identity leave request could not be created.');
    identityAssert(!empty($shifts->saveWeekendPlan([
        'month' => '2031-01',
        'profile_key' => $oldIdentity,
        'shift_key' => 'gunduz-shift',
        'working_dates' => ['2031-01-04', '2031-01-05', '2031-01-11', '2031-01-12'],
        'note' => 'Identity migration shift plan',
    ])['ok']), 'Identity weekend plan could not be created.');
    identityAssert(!empty($passwordResets->request($oldIdentity, 'https://identity.example.test')['sent']), 'Identity password reset fixture could not be created.');

    Session::put('user', [
        'email' => $oldIdentity,
        'name' => 'Identity Migration',
        'role' => 'Weekend Duty Specialist',
        'department' => $department,
        'permissions' => $permissionsBefore,
    ]);

    $shiftPath = $testRoot . '/storage/shifts.json';
    $shiftGuard = $stateStore->beginWrite('shifts', $shiftPath);
    $shiftData = $stateStore->read('shifts', $shiftPath);
    $conflictKey = identityPlanKey('2031-01', $newIdentity);
    $shiftData['weekend_plans'][$conflictKey] = [
        'key' => $conflictKey,
        'month' => '2031-01',
        'profile_key' => $newIdentity,
        'shift_key' => 'gunduz-shift',
        'working_dates' => ['2031-01-04'],
        'note' => 'Intentional rollback conflict',
        'created_at' => date('Y-m-d H:i'),
        'updated_at' => date('Y-m-d H:i'),
    ];
    $stateStore->write('shifts', $shiftPath, $shiftData);
    $shiftGuard->release();

    $transactionDocuments = [
        'password_resets' => 'password-resets.json',
        'user_profiles' => 'user-profiles.json',
        'access_control' => 'access-control.json',
        'leave_requests' => 'leave-requests.json',
        'leave_mail_outbox' => 'leave-mail-outbox.json',
        'messages' => 'messages.json',
        'push_subscriptions' => 'push-subscriptions.json',
        'shifts' => 'shifts.json',
    ];
    $rollbackSnapshots = [];
    $rollbackRevisions = [];

    foreach ($transactionDocuments as $documentKey => $filename) {
        $rollbackSnapshots[$documentKey] = $stateStore->read($documentKey, $testRoot . '/storage/' . $filename);
        $rollbackRevisions[$documentKey] = (int) (($stateStore->metadata($documentKey)['revision'] ?? 0));
    }

    $failedMigration = $identityMigration->updateProfile(
        $oldIdentity,
        identityUpdateInput($newIdentity, $department)
    );
    identityAssert(empty($failedMigration['ok']), 'Conflicting identity migration unexpectedly succeeded.');
    identityAssert($profiles->find($oldIdentity) !== null, 'Profile change was not rolled back.');
    identityAssert($profiles->find($newIdentity) === null, 'Target profile survived a rolled-back migration.');
    identityAssert($access->departmentPolicy($department)['manager_1_email'] === $oldIdentity, 'Approval reference was not rolled back.');
    identityAssert(count($messages->sent($oldIdentity)) > 0, 'Message ownership was not rolled back.');

    foreach ($transactionDocuments as $documentKey => $filename) {
        identityAssert(
            $stateStore->read($documentKey, $testRoot . '/storage/' . $filename) === $rollbackSnapshots[$documentKey],
            sprintf('%s payload changed after rollback.', $documentKey)
        );

        if ($testDriver === 'mariadb') {
            identityAssert(
                (int) (($stateStore->metadata($documentKey)['revision'] ?? 0)) === $rollbackRevisions[$documentKey],
                sprintf('%s revision changed after rollback.', $documentKey)
            );
        }
    }

    $shiftGuard = $stateStore->beginWrite('shifts', $shiftPath);
    $shiftData = $stateStore->read('shifts', $shiftPath);
    unset($shiftData['weekend_plans'][$conflictKey]);
    $stateStore->write('shifts', $shiftPath, $shiftData);
    $shiftGuard->release();

    $successfulMigration = $identityMigration->updateProfile(
        $oldIdentity,
        identityUpdateInput($newIdentity, $department)
    );
    identityAssert(!empty($successfulMigration['ok']), 'Identity migration failed after conflict removal.');
    identityAssert(!empty($successfulMigration['identity_migrated']), 'Identity migration result was not marked.');
    identityAssert($profiles->find($oldIdentity) === null, 'Old profile key still exists.');
    identityAssert($profiles->find($newIdentity) !== null, 'New profile key was not created.');
    $permissionsAfter = $access->permissionsFor($newIdentity);
    identityAssert(
        $permissionsAfter === $permissionsBefore,
        'Permissions did not follow the new identity. Before=' . json_encode($permissionsBefore) . ' After=' . json_encode($permissionsAfter)
    );
    identityAssert($access->departmentPolicy($department)['manager_1_email'] === $newIdentity, 'Department approver did not follow the new identity.');
    identityAssert(count($messages->sent($newIdentity)) > 0, 'Message ownership did not follow the new identity.');
    identityAssert((string) (Session::get('user')['email'] ?? '') === $newIdentity, 'Active session did not follow the new identity.');
    identityAssert((Session::get('user')['permissions'] ?? []) === $permissionsBefore, 'Active session permissions changed during migration.');

    $documents = [
        'access_control' => 'access-control.json',
        'leave_requests' => 'leave-requests.json',
        'leave_mail_outbox' => 'leave-mail-outbox.json',
        'messages' => 'messages.json',
        'push_subscriptions' => 'push-subscriptions.json',
        'shifts' => 'shifts.json',
    ];

    foreach ($documents as $documentKey => $filename) {
        $payload = $stateStore->read($documentKey, $testRoot . '/storage/' . $filename);
        identityAssert(!containsIdentityReference($payload, $oldIdentity), sprintf('%s still contains the old identity.', $documentKey));
        identityAssert(containsIdentityReference($payload, $newIdentity), sprintf('%s does not contain the new identity.', $documentKey));
    }

    $passwordResetData = $stateStore->read(
        'password_resets',
        $testRoot . '/storage/password-resets.json',
        ['version' => 1, 'requests' => []]
    );
    $revokedReset = end($passwordResetData['requests']);
    identityAssert(is_array($revokedReset) && ($revokedReset['invalidated_reason'] ?? '') === 'identity_changed', 'Old password reset token was not revoked.');

    echo sprintf(
        "User identity migration passed on %s: rollback and coordinated reference migration verified.\n",
        $testDriver
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "User identity migration failed: " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($databaseConnection instanceof PDO) {
        $databaseConnection->exec(
            "DELETE FROM app_state_documents WHERE document_key IN ('"
            . implode("','", $documentKeys)
            . "')"
        );
    }

    removeIdentityTestTree($testRoot);
}
