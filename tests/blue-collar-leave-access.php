<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\Auth;
use App\Core\Session;
use App\Core\StateStore;
use App\Core\UserProfileStore;
use App\Modules\Leave\LeaveApprovalMailer;
use App\Modules\Leave\LeaveStore;
use App\Modules\Shift\ShiftStore;

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/takii-blue-collar-leave-' . bin2hex(random_bytes(8));

define('APP_ROOT', $testRoot);
require $projectRoot . '/vendor/autoload.php';

function blueCollarAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeBlueCollarTestTree(string $path): void
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
            removeBlueCollarTestTree($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

try {
    mkdir($testRoot . '/storage', 0770, true);
    putenv('MAIL_TRANSPORT=outbox');
    Session::start();
    $appConfig = require $projectRoot . '/config/app.php';
    $modules = require $projectRoot . '/config/modules.php';
    $stateStore = new StateStore(null, [
        'driver' => 'file',
        'auto_migrate' => true,
        'lock_timeout' => 10,
        'lock_directory' => $testRoot . '/locks',
    ]);
    $profiles = new UserProfileStore($appConfig['demo_users'], $stateStore);
    $created = $profiles->createProfile([
        'new_email' => '',
        'first_name' => 'Mavi',
        'last_name' => 'Yaka',
        'role' => 'Üretim Personeli',
        'department' => 'Takii Gazileri _ Mavi Yaka',
        'location' => 'antalya',
        'started_on' => '2020-01-01',
        'employment_type' => 'full_time',
        'leave_opening_total_days' => '30',
        'leave_opening_used_days' => '0',
        'leave_opening_remaining_days' => '30',
        'leave_opening_snapshot_date' => '2029-12-31',
        'password' => 'blue-collar-test-123',
        'password_confirmation' => 'blue-collar-test-123',
    ]);
    blueCollarAssert(!empty($created['ok']), 'Blue-collar profile without email could not be created.');

    $profileKey = (string) ($created['profile_key'] ?? '');
    $profile = $profiles->find($profileKey);
    blueCollarAssert(is_array($profile), 'Blue-collar profile could not be loaded.');
    blueCollarAssert((string) ($profile['email'] ?? '') === '', 'Blue-collar email unexpectedly became mandatory.');
    blueCollarAssert(in_array('module.leave.access', $profile['permissions'] ?? [], true), 'No-email profile did not receive leave module access.');
    blueCollarAssert(in_array('leave.request.create', $profile['permissions'] ?? [], true), 'No-email profile did not receive leave request permission.');

    $accessPath = $testRoot . '/storage/access-control.json';
    $accessGuard = $stateStore->beginWrite('access_control', $accessPath);
    $stateStore->write('access_control', $accessPath, [
        'version' => 15,
        'departments' => [],
        'user_permissions' => [$profileKey => []],
        'department_policies' => [],
    ]);
    unset($accessGuard);

    $access = new AccessControl($profiles->users(), $modules, $stateStore);
    blueCollarAssert($access->setDepartmentPolicy((string) ($profile['department'] ?? ''), [
        'manager_approval_count' => 1,
        'manager_1_email' => 'admin@example.test',
        'hr_email' => 'hr@example.test',
    ]), 'Blue-collar approval policy fixture could not be saved.');
    $permissions = $access->permissionsFor($profileKey);
    blueCollarAssert(in_array('module.leave.access', $permissions, true), 'Existing no-email profile leave module permission was not migrated.');
    blueCollarAssert(in_array('leave.request.create', $permissions, true), 'Existing no-email profile leave request permission was not migrated.');

    $auth = new Auth($profiles, $access);
    blueCollarAssert($auth->attempt((string) ($profile['username'] ?? ''), 'blue-collar-test-123'), 'Blue-collar username login failed.');
    blueCollarAssert($auth->can('module.leave.access'), 'Blue-collar session cannot open the leave module.');
    blueCollarAssert($auth->can('leave.request.create'), 'Blue-collar session cannot create a leave request.');

    $shiftStore = new ShiftStore($profiles, $stateStore);
    $leaveStore = new LeaveStore($access, new LeaveApprovalMailer(), $stateStore, $profiles, $shiftStore);
    $request = $leaveStore->create($auth->user() ?? [], [
        'starts_on' => '2030-01-07',
        'ends_on' => '2030-01-07',
        'type_key' => 'leave.type.annual',
        'day_part' => 'full',
        'note' => 'Blue-collar leave access regression',
    ]);
    blueCollarAssert(!empty($request['ok']), 'Blue-collar leave request could not be created.');

    echo "Blue-collar leave access passed: no-email login, migrated permissions, and leave creation verified.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Blue-collar leave access failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    removeBlueCollarTestTree($testRoot);
}
