<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\LocationScope;
use App\Core\Session;
use App\Core\StateStore;
use App\Core\UserProfileStore;
use App\Modules\Leave\LeaveApprovalMailer;
use App\Modules\Leave\LeaveStore;
use App\Modules\Messaging\MessageStore;
use App\Modules\Shift\ShiftStore;

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/takii-location-visibility-' . bin2hex(random_bytes(8));

define('APP_ROOT', $testRoot);
require $projectRoot . '/vendor/autoload.php';

function locationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeLocationTestTree(string $path): void
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
            removeLocationTestTree($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

function createLocationProfile(UserProfileStore $profiles, array $input): array
{
    $result = $profiles->createProfile(array_merge([
        'role' => 'Specialist',
        'started_on' => '2020-01-01',
        'employment_type' => 'full_time',
        'leave_opening_total_days' => '50',
        'leave_opening_used_days' => '0',
        'leave_opening_remaining_days' => '50',
        'leave_opening_snapshot_date' => '2029-12-31',
        'password' => 'location-test-123',
        'password_confirmation' => 'location-test-123',
    ], $input));
    locationAssert(!empty($result['ok']), 'Location fixture profile could not be created.');

    $profile = $profiles->find((string) $input['new_email']);
    locationAssert(is_array($profile), 'Location fixture profile could not be loaded.');

    return $profile;
}

function calendarRequesters(array $calendar): array
{
    $requesters = [];

    foreach ($calendar['days'] ?? [] as $day) {
        foreach ($day['events'] ?? [] as $event) {
            $requesters[] = (string) ($event['requester'] ?? '');
        }
    }

    return $requesters;
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

    $antalyaOne = createLocationProfile($profiles, [
        'new_email' => 'antalya.one@takii.com.tr',
        'first_name' => 'Antalya',
        'last_name' => 'One',
        'department' => 'RD ofis',
        'shift_key' => 'antalya-1-shift',
    ]);
    $antalyaTwo = createLocationProfile($profiles, [
        'new_email' => 'antalya.two@takii.com.tr',
        'first_name' => 'Antalya',
        'last_name' => 'Two',
        'department' => 'RD Long Term',
        'shift_key' => 'antalya-2-shift',
    ]);
    $bursa = createLocationProfile($profiles, [
        'new_email' => 'bursa.one@takii.com.tr',
        'first_name' => 'Bursa',
        'last_name' => 'One',
        'department' => 'Prod ofis',
        'shift_key' => 'bursa-shift',
    ]);

    locationAssert(($antalyaOne['location'] ?? '') === LocationScope::ANTALYA, 'RD profile was not migrated to Antalya.');
    locationAssert(($bursa['location'] ?? '') === LocationScope::BURSA, 'Prod profile was not migrated to Bursa.');
    locationAssert(LocationScope::canView($antalyaOne, $antalyaTwo), 'Same-location profiles cannot see each other.');
    locationAssert(!LocationScope::canView($antalyaOne, $bursa), 'Antalya profile can see Bursa.');
    locationAssert(!LocationScope::canView($bursa, $antalyaOne), 'Bursa profile can see Antalya.');

    $directory = $profiles->users();
    $hr = $directory['y.ekici@takii.com.tr'];
    locationAssert(LocationScope::hasGlobalVisibility($hr), 'HR manager did not receive global location visibility.');
    locationAssert(LocationScope::canView($hr, $bursa), 'HR manager cannot see Bursa.');

    $messages = new MessageStore($directory, $stateStore);
    $antalyaRecipients = array_column($messages->recipients('antalya.one@takii.com.tr'), 'email');
    locationAssert(in_array('antalya.two@takii.com.tr', $antalyaRecipients, true), 'Same-location message recipient is missing.');
    locationAssert(!in_array('bursa.one@takii.com.tr', $antalyaRecipients, true), 'Cross-location message recipient is visible.');
    locationAssert(empty($messages->send($antalyaOne, [
        'to_email' => 'bursa.one@takii.com.tr',
        'subject' => 'Blocked cross-location message',
        'body' => 'This must not be sent.',
    ])['ok']), 'Cross-location message was accepted.');
    locationAssert(!empty($messages->send($antalyaOne, [
        'to_email' => 'antalya.two@takii.com.tr',
        'subject' => 'Allowed same-location message',
        'body' => 'This message is allowed.',
    ])['ok']), 'Same-location message was rejected.');
    locationAssert(!empty($messages->send($hr, [
        'to_email' => 'bursa.one@takii.com.tr',
        'subject' => 'Allowed HR message',
        'body' => 'HR can contact both locations.',
    ])['ok']), 'HR cross-location message was rejected.');

    $access = new AccessControl($directory, $modules, $stateStore);
    $access->setUserPermissions('antalya.two@takii.com.tr', [
        'admin.company.manage',
        'module.messages.access',
        'messaging.send',
    ]);
    $expandedDirectory = $access->usersByIdentity();
    locationAssert(LocationScope::hasGlobalVisibility($expandedDirectory['antalya.two@takii.com.tr'] ?? []), 'Delegated admin did not receive global location visibility.');
    $expandedMessages = new MessageStore($expandedDirectory, $stateStore);
    $delegatedAdminRecipients = array_column($expandedMessages->recipients('antalya.two@takii.com.tr'), 'email');
    locationAssert(in_array('bursa.one@takii.com.tr', $delegatedAdminRecipients, true), 'Delegated admin cannot see both message locations.');
    locationAssert($access->setDepartmentPolicy('RD ofis', [
        'manager_approval_count' => 1,
        'manager_1_email' => 'bursa.one@takii.com.tr',
        'hr_email' => 'y.ekici@takii.com.tr',
    ]), 'Cross-location approval policy fixture could not be saved.');
    $shifts = new ShiftStore($profiles, $stateStore);
    $antalyaShiftPersonnel = array_column($shifts->personnel($antalyaOne), 'email');
    locationAssert(in_array('antalya.two@takii.com.tr', $antalyaShiftPersonnel, true), 'Same-location shift personnel is missing.');
    locationAssert(!in_array('bursa.one@takii.com.tr', $antalyaShiftPersonnel, true), 'Cross-location shift personnel is visible.');
    locationAssert(empty($shifts->assignToProfiles('', ['bursa.one@takii.com.tr'], false, $antalyaOne)['ok']), 'Cross-location shift assignment was accepted.');
    $hrShiftPersonnel = array_column($shifts->personnel($hr), 'email');
    locationAssert(in_array('antalya.one@takii.com.tr', $hrShiftPersonnel, true) && in_array('bursa.one@takii.com.tr', $hrShiftPersonnel, true), 'HR cannot see both locations in shift planning.');
    $leave = new LeaveStore($access, new LeaveApprovalMailer(), $stateStore, $profiles, $shifts);

    $antalyaLeaveResult = null;

    foreach ([$antalyaOne, $bursa] as $leaveUser) {
        $result = $leave->create($leaveUser, [
            'starts_on' => '2030-01-07',
            'ends_on' => '2030-01-07',
            'type_key' => 'leave.type.annual',
            'day_part' => 'full',
            'note' => 'Location visibility fixture',
        ]);
        locationAssert(!empty($result['ok']), 'Location leave fixture could not be created.');

        if (($leaveUser['email'] ?? '') === 'antalya.one@takii.com.tr') {
            $antalyaLeaveResult = $result;
        }
    }

    locationAssert(is_array($antalyaLeaveResult) && ($antalyaLeaveResult['notifications'] ?? []) === [], 'Cross-location manager received an approval notification.');
    $antalyaLeaveRequest = null;

    foreach ($leave->all() as $leaveRequest) {
        if (($leaveRequest['requester_email'] ?? '') === 'antalya.one@takii.com.tr') {
            $antalyaLeaveRequest = $leaveRequest;
            break;
        }
    }

    locationAssert(is_array($antalyaLeaveRequest), 'Antalya leave request fixture is missing.');
    $crossLocationToken = (string) ($antalyaLeaveRequest['approval_tokens']['manager_1'] ?? '');
    $crossLocationDecision = $leave->advanceByToken($crossLocationToken, 'approve');
    locationAssert(empty($crossLocationDecision['ok']) && ($crossLocationDecision['message'] ?? '') === 'leave.flash.not_allowed', 'Cross-location mail token approved the leave request.');

    $antalyaCalendar = calendarRequesters($leave->calendar('day', '2030-01-07', $antalyaTwo));
    locationAssert(in_array('Antalya One', $antalyaCalendar, true), 'Antalya leave is missing from Antalya calendar.');
    locationAssert(!in_array('Bursa One', $antalyaCalendar, true), 'Bursa leave is visible in Antalya calendar.');

    $bursaCalendar = calendarRequesters($leave->calendar('day', '2030-01-07', $bursa));
    locationAssert(in_array('Bursa One', $bursaCalendar, true), 'Bursa leave is missing from Bursa calendar.');
    locationAssert(!in_array('Antalya One', $bursaCalendar, true), 'Antalya leave is visible in Bursa calendar.');

    $hrCalendar = calendarRequesters($leave->calendar('day', '2030-01-07', $hr));
    locationAssert(in_array('Antalya One', $hrCalendar, true) && in_array('Bursa One', $hrCalendar, true), 'HR cannot see both location calendars.');

    $csv = $profiles->exportProfilesCsv([$antalyaOne]);
    locationAssert(str_contains($csv, 'department,location,pdks_id'), 'Location is missing from personnel export.');
    locationAssert(str_contains($csv, ',antalya,'), 'Export did not include the profile location.');

    echo "Location visibility passed: personnel policy, messages, shifts, leave calendar, HR exception, and export verified.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Location visibility failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    removeLocationTestTree($testRoot);
}
