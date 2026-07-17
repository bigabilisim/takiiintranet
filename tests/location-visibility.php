<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\Auth;
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
    putenv('LEAVE_BOOK_SIGNATURE_FOLLOWUP_EMAILS');
    putenv('APP_HR_PASSWORD=location-test-123');
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
    $antalyaAssistant = createLocationProfile($profiles, [
        'new_email' => 'antalya.hr.assistant@takii.com.tr',
        'first_name' => 'Antalya',
        'last_name' => 'HR Assistant',
        'department' => 'IK Antalya',
        'location' => LocationScope::ANTALYA,
        'workforce_roles' => ['hr_assistant_antalya'],
    ]);
    $bursaAssistant = createLocationProfile($profiles, [
        'new_email' => 'bursa.hr.assistant@takii.com.tr',
        'first_name' => 'Bursa',
        'last_name' => 'HR Assistant',
        'department' => 'IK Bursa',
        'location' => LocationScope::BURSA,
        'workforce_roles' => ['hr_assistant_bursa'],
    ]);
    $legacyAssistant = createLocationProfile($profiles, [
        'new_email' => 'legacy.hr.assistant@takii.com.tr',
        'first_name' => 'Legacy',
        'last_name' => 'HR Assistant',
        'department' => 'IK Antalya',
        'location' => LocationScope::ANTALYA,
        'workforce_roles' => ['hr_assistant'],
    ]);
    locationAssert(in_array('hr_assistant_antalya', $legacyAssistant['workforce_roles'] ?? [], true), 'Legacy HR assistant role was not scoped to Antalya.');
    locationAssert(!in_array('hr_assistant', $legacyAssistant['workforce_roles'] ?? [], true), 'Legacy HR assistant role remained unscoped.');

    $conflictingAssistant = $profiles->createProfile([
        'new_email' => 'conflicting.hr.assistant@takii.com.tr',
        'first_name' => 'Conflicting',
        'last_name' => 'HR Assistant',
        'role' => 'Specialist',
        'department' => 'IK',
        'location' => LocationScope::ANTALYA,
        'started_on' => '2020-01-01',
        'employment_type' => 'full_time',
        'workforce_roles' => ['hr_assistant_antalya', 'hr_assistant_bursa'],
        'password' => 'location-test-123',
        'password_confirmation' => 'location-test-123',
    ]);
    locationAssert(empty($conflictingAssistant['ok']) && ($conflictingAssistant['message'] ?? '') === 'personnel.flash.hr_assistant_location_conflict', 'Conflicting HR assistant locations were accepted.');

    locationAssert(($antalyaOne['location'] ?? '') === LocationScope::ANTALYA, 'RD profile was not migrated to Antalya.');
    locationAssert(($bursa['location'] ?? '') === LocationScope::BURSA, 'Prod profile was not migrated to Bursa.');
    locationAssert(LocationScope::canView($antalyaOne, $antalyaTwo), 'Same-location profiles cannot see each other.');
    locationAssert(!LocationScope::canView($antalyaOne, $bursa), 'Antalya profile can see Bursa.');
    locationAssert(!LocationScope::canView($bursa, $antalyaOne), 'Bursa profile can see Antalya.');

    $directory = $profiles->users();
    $hr = $directory['y.ekici@takii.com.tr'];
    locationAssert(LocationScope::hasGlobalVisibility($hr), 'HR manager did not receive global location visibility.');
    locationAssert(LocationScope::canView($hr, $bursa), 'HR manager cannot see Bursa.');
    locationAssert(!LocationScope::hasGlobalVisibility($antalyaAssistant), 'Antalya HR assistant received global visibility.');
    locationAssert(LocationScope::canView($antalyaAssistant, $antalyaOne), 'Antalya HR assistant cannot see Antalya.');
    locationAssert(!LocationScope::canView($antalyaAssistant, $bursa), 'Antalya HR assistant can see Bursa.');
    locationAssert(LocationScope::canView($bursaAssistant, $bursa), 'Bursa HR assistant cannot see Bursa.');
    locationAssert(!LocationScope::canView($bursaAssistant, $antalyaOne), 'Bursa HR assistant can see Antalya.');

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
    $expandedAntalyaAssistant = $expandedDirectory['antalya.hr.assistant@takii.com.tr'] ?? [];
    $expandedBursaAssistant = $expandedDirectory['bursa.hr.assistant@takii.com.tr'] ?? [];
    locationAssert(in_array('leave.request.manage.hr', $expandedAntalyaAssistant['permissions'] ?? [], true), 'Antalya HR assistant did not receive HR leave permission.');
    locationAssert(!LocationScope::hasGlobalVisibility($expandedAntalyaAssistant), 'Antalya HR assistant permission bypassed the location scope.');
    locationAssert(!LocationScope::canView($expandedAntalyaAssistant, $bursa), 'Antalya HR assistant permission exposed Bursa.');
    locationAssert(in_array('leave.request.manage.hr', $expandedBursaAssistant['permissions'] ?? [], true), 'Bursa HR assistant did not receive HR leave permission.');
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
    $bursaLeaveRequest = null;

    foreach ($leave->all() as $leaveRequest) {
        if (($leaveRequest['requester_email'] ?? '') === 'antalya.one@takii.com.tr') {
            $antalyaLeaveRequest = $leaveRequest;
        }

        if (($leaveRequest['requester_email'] ?? '') === 'bursa.one@takii.com.tr') {
            $bursaLeaveRequest = $leaveRequest;
        }
    }

    locationAssert(is_array($antalyaLeaveRequest), 'Antalya leave request fixture is missing.');
    locationAssert(is_array($bursaLeaveRequest), 'Bursa leave request fixture is missing.');
    $signatureRecipients = new ReflectionMethod($leave, 'signatureFollowupRecipients');
    $signatureRecipients->setAccessible(true);
    $antalyaSignatureRecipients = $signatureRecipients->invoke($leave, $antalyaLeaveRequest);
    $bursaSignatureRecipients = $signatureRecipients->invoke($leave, $bursaLeaveRequest);
    locationAssert(in_array('antalya.hr.assistant@takii.com.tr', $antalyaSignatureRecipients, true), 'Antalya HR assistant is missing from Antalya signature reminders.');
    locationAssert(!in_array('bursa.hr.assistant@takii.com.tr', $antalyaSignatureRecipients, true), 'Bursa HR assistant received an Antalya signature reminder.');
    locationAssert(in_array('bursa.hr.assistant@takii.com.tr', $bursaSignatureRecipients, true), 'Bursa HR assistant is missing from Bursa signature reminders.');
    locationAssert(!in_array('antalya.hr.assistant@takii.com.tr', $bursaSignatureRecipients, true), 'Antalya HR assistant received a Bursa signature reminder.');
    $crossLocationToken = bin2hex(random_bytes(32));
    $leavePath = $testRoot . '/storage/leave-requests.json';
    $leaveGuard = $stateStore->beginWrite('leave_requests', $leavePath, ['version' => 1, 'requests' => []]);
    $leaveData = $stateStore->read('leave_requests', $leavePath, ['version' => 1, 'requests' => []]);

    foreach ($leaveData['requests'] as &$mailRequest) {
        if (($mailRequest['id'] ?? '') !== ($antalyaLeaveRequest['id'] ?? '')) {
            continue;
        }

        // Legacy raw-token fixture verifies transparent hashing without exposing a real stored token.
        $mailRequest['approval_tokens']['manager_1'] = $crossLocationToken;
        $mailRequest['approval_token_expires_at']['manager_1'] = '2030-01-11 12:00';
    }
    unset($mailRequest);

    $stateStore->write('leave_requests', $leavePath, $leaveData);
    $leaveGuard->release();
    $leave = new LeaveStore($access, new LeaveApprovalMailer(), $stateStore, $profiles, $shifts);
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
    $antalyaAssistantCalendar = calendarRequesters($leave->calendar('day', '2030-01-07', $expandedAntalyaAssistant));
    locationAssert(in_array('Antalya One', $antalyaAssistantCalendar, true), 'Antalya HR assistant cannot see Antalya calendar.');
    locationAssert(!in_array('Bursa One', $antalyaAssistantCalendar, true), 'Antalya HR assistant can see Bursa calendar.');
    $bursaAssistantCalendar = calendarRequesters($leave->calendar('day', '2030-01-07', $expandedBursaAssistant));
    locationAssert(in_array('Bursa One', $bursaAssistantCalendar, true), 'Bursa HR assistant cannot see Bursa calendar.');
    locationAssert(!in_array('Antalya One', $bursaAssistantCalendar, true), 'Bursa HR assistant can see Antalya calendar.');

    $leaveGuard = $stateStore->beginWrite('leave_requests', $leavePath, ['version' => 1, 'requests' => []]);
    $leaveData = $stateStore->read('leave_requests', $leavePath, ['version' => 1, 'requests' => []]);
    $regionalHrRequestIds = [];

    foreach ($leaveData['requests'] as &$regionalHrRequest) {
        $requesterEmail = (string) ($regionalHrRequest['requester_email'] ?? '');

        if (!in_array($requesterEmail, ['antalya.one@takii.com.tr', 'bursa.one@takii.com.tr'], true)) {
            continue;
        }

        $regionalHrRequest['status'] = 'waiting_hr';
        $regionalHrRequest['calendar_state'] = 'pending';
        $regionalHrRequest['approval_policy']['hr_email'] = 'y.ekici@takii.com.tr';
        $regionalHrRequest['approvals']['manager_1']['status'] = 'approved';
        $regionalHrRequest['approvals']['manager_2']['status'] = 'skipped';
        $regionalHrRequest['approvals']['hr']['status'] = 'pending';
        $regionalHrRequest['approvals']['hr']['assignee'] = 'y.ekici@takii.com.tr';
        $regionalHrRequest['approvals']['hr']['actor'] = null;
        $regionalHrRequest['approvals']['hr']['source'] = null;
        $regionalHrRequest['approvals']['hr']['acted_at'] = null;
        $regionalHrRequest['approval_tokens']['hr'] = bin2hex(random_bytes(16));
        $regionalHrRequest['approval_token_expires_at']['hr'] = '2030-01-11 12:00';
        $regionalHrRequestIds[$requesterEmail] = (string) ($regionalHrRequest['id'] ?? '');
    }
    unset($regionalHrRequest);

    $stateStore->write('leave_requests', $leavePath, $leaveData);
    $leaveGuard->release();
    locationAssert(count($regionalHrRequestIds) === 2, 'Regional HR approval fixtures could not be prepared.');
    $leave = new LeaveStore($access, new LeaveApprovalMailer(), $stateStore, $profiles, $shifts);

    $hrAuth = new Auth($profiles, $access);
    locationAssert($hrAuth->attempt('y.ekici@takii.com.tr', 'location-test-123'), 'HR manager login failed.');
    $hrPendingIds = array_column($leave->pendingApprovalsFor($hrAuth), 'id');
    locationAssert(in_array($regionalHrRequestIds['antalya.one@takii.com.tr'], $hrPendingIds, true), 'HR manager cannot see the Antalya HR-stage request.');
    locationAssert(in_array($regionalHrRequestIds['bursa.one@takii.com.tr'], $hrPendingIds, true), 'HR manager cannot see the Bursa HR-stage request.');
    $hrAuth->logout();

    $antalyaAssistantAuth = new Auth($profiles, $access);
    locationAssert($antalyaAssistantAuth->attempt('antalya.hr.assistant@takii.com.tr', 'location-test-123'), 'Antalya HR assistant login failed.');
    $antalyaPendingIds = array_column($leave->pendingApprovalsFor($antalyaAssistantAuth), 'id');
    locationAssert(in_array($regionalHrRequestIds['antalya.one@takii.com.tr'], $antalyaPendingIds, true), 'Antalya HR assistant cannot see the Antalya HR-stage request.');
    locationAssert(!in_array($regionalHrRequestIds['bursa.one@takii.com.tr'], $antalyaPendingIds, true), 'Antalya HR assistant can see the Bursa HR-stage request.');
    $antalyaApprovalCalendar = $leave->calendar('day', '2030-01-07', $antalyaAssistantAuth->user(), $antalyaAssistantAuth);
    $antalyaApprovalEvent = $antalyaApprovalCalendar['days'][0]['events'][0] ?? null;
    locationAssert(is_array($antalyaApprovalEvent) && !empty($antalyaApprovalEvent['can_act']), 'Antalya HR assistant cannot use calendar quick approval for Antalya.');
    $blockedBursaApproval = $leave->advanceByPlatform(
        $regionalHrRequestIds['bursa.one@takii.com.tr'],
        $antalyaAssistantAuth->user() ?? [],
        $antalyaAssistantAuth,
        'approve'
    );
    locationAssert(empty($blockedBursaApproval['ok']) && ($blockedBursaApproval['message'] ?? '') === 'leave.flash.not_allowed', 'Antalya HR assistant approved a Bursa request.');
    $approvedAntalya = $leave->advanceByPlatform(
        $regionalHrRequestIds['antalya.one@takii.com.tr'],
        $antalyaAssistantAuth->user() ?? [],
        $antalyaAssistantAuth,
        'approve'
    );
    locationAssert(!empty($approvedAntalya['ok']), 'Antalya HR assistant could not approve an Antalya request.');
    $approvedAntalyaRequest = $leave->findById($regionalHrRequestIds['antalya.one@takii.com.tr']);
    locationAssert(($approvedAntalyaRequest['status'] ?? '') === 'approved', 'Antalya request did not complete after regional HR approval.');
    locationAssert(($approvedAntalyaRequest['approvals']['hr']['actor'] ?? '') === 'Antalya HR Assistant', 'Antalya HR assistant was not recorded in approval history.');
    $antalyaAssistantAuth->logout();

    $bursaAssistantAuth = new Auth($profiles, $access);
    locationAssert($bursaAssistantAuth->attempt('bursa.hr.assistant@takii.com.tr', 'location-test-123'), 'Bursa HR assistant login failed.');
    $bursaPendingIds = array_column($leave->pendingApprovalsFor($bursaAssistantAuth), 'id');
    locationAssert(in_array($regionalHrRequestIds['bursa.one@takii.com.tr'], $bursaPendingIds, true), 'Bursa HR assistant cannot see the Bursa HR-stage request.');
    locationAssert(!in_array($regionalHrRequestIds['antalya.one@takii.com.tr'], $bursaPendingIds, true), 'Bursa HR assistant can see the Antalya HR-stage request.');
    $approvedBursa = $leave->advanceByPlatform(
        $regionalHrRequestIds['bursa.one@takii.com.tr'],
        $bursaAssistantAuth->user() ?? [],
        $bursaAssistantAuth,
        'approve'
    );
    locationAssert(!empty($approvedBursa['ok']), 'Bursa HR assistant could not approve a Bursa request.');
    $approvedBursaRequest = $leave->findById($regionalHrRequestIds['bursa.one@takii.com.tr']);
    locationAssert(($approvedBursaRequest['status'] ?? '') === 'approved', 'Bursa request did not complete after regional HR approval.');
    locationAssert(($approvedBursaRequest['approvals']['hr']['actor'] ?? '') === 'Bursa HR Assistant', 'Bursa HR assistant was not recorded in approval history.');
    $bursaAssistantAuth->logout();

    $csv = $profiles->exportProfilesCsv([$antalyaOne]);
    locationAssert(str_contains($csv, 'department,location,pdks_id'), 'Location is missing from personnel export.');
    locationAssert(str_contains($csv, ',antalya,'), 'Export did not include the profile location.');

    echo "Location visibility passed: personnel policy, messages, shifts, leave calendar, regional HR approvals, HR exception, and export verified.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Location visibility failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    removeLocationTestTree($testRoot);
}
