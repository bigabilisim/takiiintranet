<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\Database;
use App\Core\Session;
use App\Core\StateStore;
use App\Core\UserProfileStore;
use App\Modules\Leave\LeaveApprovalMailer;
use App\Modules\Leave\LeaveStore;
use App\Modules\Messaging\MessageStore;
use App\Modules\Notifications\PushNotificationStore;
use App\Modules\Shift\ShiftStore;

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/takii-store-smoke-' . bin2hex(random_bytes(8));
$testDriver = strtolower(trim((string) (getenv('TEST_STATE_DRIVER') ?: 'file')));
$databaseConnection = null;

define('APP_ROOT', $testRoot);
require $projectRoot . '/vendor/autoload.php';

function assertResult(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTestTree(string $path): void
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
            removeTestTree($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
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
        'vapid.json',
    ] as $filename) {
        $source = $projectRoot . '/storage/' . $filename;

        if (is_file($source)) {
            copy($source, $testRoot . '/storage/' . $filename);
        }
    }

    putenv('MAIL_TRANSPORT=outbox');
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
        'lock_timeout' => 10,
        'lock_directory' => $testRoot . '/locks',
    ]);
    $profiles = new UserProfileStore($appConfig['demo_users'], $stateStore);
    $directory = $profiles->users();
    $access = new AccessControl($directory, $modules, $stateStore);
    $messages = new MessageStore($directory, $stateStore);
    $push = new PushNotificationStore($stateStore);
    $shifts = new ShiftStore($profiles, $stateStore);
    $leave = new LeaveStore($access, new LeaveApprovalMailer(), $stateStore, $profiles, $shifts);

    $createdProfile = $profiles->createProfile([
        'new_email' => 'state.test@takii.com.tr',
        'first_name' => 'State',
        'last_name' => 'Test',
        'role' => 'Tester',
        'department' => 'Operations',
        'password' => 'state-test-123',
        'password_confirmation' => 'state-test-123',
    ]);
    assertResult(($createdProfile['ok'] ?? false) === true, 'Personnel create failed.');
    assertResult($profiles->find('state.test@takii.com.tr') !== null, 'Created personnel was not persisted.');
    assertResult(($profiles->deleteProfile('state.test@takii.com.tr')['ok'] ?? false) === true, 'Personnel delete failed.');

    assertResult(($access->createDepartment('State Test Root')['ok'] ?? false) === true, 'Department create failed.');
    assertResult(($access->createDepartment('State Test Child', 'State Test Root')['ok'] ?? false) === true, 'Child department create failed.');
    assertResult($access->setDepartmentPolicy('State Test Child', [
        'manager_approval_count' => 1,
        'manager_1_email' => 'bilal@bigabilisim.com',
        'hr_email' => 'y.ekici@takii.com.tr',
    ]), 'Department policy update failed.');
    assertResult(($access->deleteDepartment('State Test Child')['ok'] ?? false) === true, 'Child department delete failed.');
    assertResult(($access->deleteDepartment('State Test Root')['ok'] ?? false) === true, 'Department delete failed.');

    $sender = $directory['bilal@bigabilisim.com'];
    $sender['email'] = 'bilal@bigabilisim.com';
    $subject = 'State smoke ' . bin2hex(random_bytes(4));
    assertResult(($messages->send($sender, [
        'to_email' => 'y.ekici@takii.com.tr',
        'subject' => $subject,
        'body' => 'Transactional state smoke test.',
    ])['ok'] ?? false) === true, 'Message send failed.');
    $sentMessage = null;

    foreach ($messages->sent('bilal@bigabilisim.com') as $message) {
        if (($message['subject'] ?? '') === $subject) {
            $sentMessage = $message;
            break;
        }
    }

    assertResult(is_array($sentMessage), 'Sent message was not persisted.');
    $messageId = (string) $sentMessage['id'];
    assertResult(($messages->markRead($messageId, 'y.ekici@takii.com.tr')['ok'] ?? false) === true, 'Message read update failed.');
    assertResult(($messages->delete($messageId, $sender)['ok'] ?? false) === true, 'Message delete failed.');
    assertResult(($messages->restore($messageId, $sender)['ok'] ?? false) === true, 'Message restore failed.');

    $subscription = [
        'endpoint' => 'https://push.example.test/' . bin2hex(random_bytes(8)),
        'keys' => ['p256dh' => 'test-public-key', 'auth' => 'test-auth-token'],
    ];
    assertResult(($push->subscribe('bilal@bigabilisim.com', $subscription)['ok'] ?? false) === true, 'Push subscribe failed.');
    assertResult(($push->unsubscribe('bilal@bigabilisim.com', $subscription)['ok'] ?? false) === true, 'Push unsubscribe failed.');

    $leaveUser = $directory['bilal@bigabilisim.com'];
    $leaveUser['email'] = 'bilal@bigabilisim.com';
    $leaveNote = 'State smoke ' . bin2hex(random_bytes(4));
    $leaveResult = $leave->create($leaveUser, [
        'starts_on' => '2030-01-07',
        'ends_on' => '2030-01-07',
        'type_key' => 'leave.type.annual',
        'day_part' => 'full',
        'note' => $leaveNote,
    ]);
    assertResult(($leaveResult['ok'] ?? false) === true, 'Leave create failed.');
    $createdLeave = null;

    foreach ($leave->all() as $request) {
        if (($request['note'] ?? '') === $leaveNote) {
            $createdLeave = $request;
            break;
        }
    }

    assertResult(is_array($createdLeave), 'Created leave was not persisted.');
    assertResult(($leave->cancelByRequesterBeforeFirstApproval((string) $createdLeave['id'], $leaveUser)['ok'] ?? false) === true, 'Leave cancellation failed.');

    echo sprintf(
        "Core store smoke test passed on %s: personnel, access, messages, push and leave.\n",
        $testDriver
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "Core store smoke test failed: " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($databaseConnection instanceof PDO) {
        $databaseConnection->exec(
            "DELETE FROM app_state_documents WHERE document_key IN ("
            . "'user_profiles','access_control','messages','push_subscriptions','leave_requests','leave_mail_outbox','shifts'"
            . ")"
        );
    }

    removeTestTree($testRoot);
}
