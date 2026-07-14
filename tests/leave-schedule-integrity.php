<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\Database;
use App\Core\Session;
use App\Core\StateStore;
use App\Core\UserProfileStore;
use App\Modules\Leave\LeaveApprovalMailer;
use App\Modules\Leave\LeaveStore;
use App\Modules\Shift\ShiftStore;

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/takii-leave-schedule-' . bin2hex(random_bytes(8));
$testDriver = strtolower(trim((string) (getenv('TEST_STATE_DRIVER') ?: 'file')));
$databaseConnection = null;
$documentKeys = ['user_profiles', 'access_control', 'leave_requests', 'leave_mail_outbox', 'shifts'];

define('APP_ROOT', $testRoot);
require $projectRoot . '/vendor/autoload.php';

function scheduleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeScheduleTestTree(string $path): void
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
            removeScheduleTestTree($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

function profileUpdatePayload(array $profile, string $firstName, string $lastName): array
{
    return [
        'new_email' => (string) ($profile['email'] ?? ''),
        'first_name' => $firstName,
        'last_name' => $lastName,
        'role' => (string) ($profile['role'] ?? ''),
        'department' => (string) ($profile['department'] ?? ''),
        'pdks_id' => (string) ($profile['pdks_id'] ?? ''),
        'started_on' => (string) ($profile['started_on'] ?? ''),
        'employment_type' => (string) ($profile['employment_type'] ?? 'full_time'),
        'phone' => (string) ($profile['phone'] ?? ''),
        'personal_phone' => (string) ($profile['personal_phone'] ?? ''),
        'birth_date' => (string) ($profile['birth_date'] ?? ''),
        'leave_opening_total_days' => (string) ($profile['leave_opening_total_days'] ?? 0),
        'leave_opening_used_days' => (string) ($profile['leave_opening_used_days'] ?? 0),
        'leave_opening_remaining_days' => (string) ($profile['leave_opening_remaining_days'] ?? 0),
        'leave_opening_snapshot_date' => (string) ($profile['leave_opening_snapshot_date'] ?? ''),
        'leave_opening_source' => (string) ($profile['leave_opening_source'] ?? ''),
        'national_id' => (string) ($profile['national_id'] ?? ''),
        'address' => (string) ($profile['address'] ?? ''),
        'emergency_contact_name' => (string) ($profile['emergency_contact_name'] ?? ''),
        'emergency_contact_phone' => (string) ($profile['emergency_contact_phone'] ?? ''),
        'education_level' => (string) ($profile['education_level'] ?? ''),
        'school' => (string) ($profile['school'] ?? ''),
        'faculty' => (string) ($profile['faculty'] ?? ''),
        'graduation_year' => (string) ($profile['graduation_year'] ?? ''),
        'hr_notes' => (string) ($profile['hr_notes'] ?? ''),
        'workforce_roles' => is_array($profile['workforce_roles'] ?? null) ? $profile['workforce_roles'] : [],
        'shift_key' => (string) ($profile['shift_key'] ?? ''),
        'password' => '',
        'password_confirmation' => '',
    ];
}

try {
    mkdir($testRoot . '/storage', 0770, true);
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
    $created = $profiles->createProfile([
        'new_email' => 'schedule.integrity@takii.com.tr',
        'first_name' => 'Schedule',
        'last_name' => 'Integrity',
        'role' => 'Weekend Duty Specialist',
        'department' => 'Operations',
        'pdks_id' => 'SCHEDULE-001',
        'started_on' => '2010-01-01',
        'employment_type' => 'full_time',
        'leave_opening_total_days' => '100',
        'leave_opening_used_days' => '0',
        'leave_opening_remaining_days' => '100',
        'leave_opening_snapshot_date' => '2029-12-31',
        'leave_opening_source' => 'schedule-test',
        'workforce_roles' => ['weekend_duty'],
        'shift_key' => 'gunduz-shift',
        'password' => 'schedule-test-123',
        'password_confirmation' => 'schedule-test-123',
    ]);
    scheduleAssert(!empty($created['ok']), 'Schedule fixture profile could not be created.');
    $profile = $profiles->find('schedule.integrity@takii.com.tr');
    scheduleAssert(is_array($profile), 'Schedule fixture profile could not be loaded.');
    $personnelId = (string) ($profile['personnel_id'] ?? '');
    scheduleAssert(preg_match('/^PER-[A-F0-9]{16}$/', $personnelId) === 1, 'Stable personnel ID was not generated.');
    scheduleAssert(str_starts_with($profiles->exportProfilesCsv(), "\xEF\xBB\xBFpersonnel_id,"), 'Personnel ID is missing from export.');

    $directory = $profiles->users();
    $access = new AccessControl($directory, $modules, $stateStore);

    $legacyShiftPath = $testRoot . '/storage/shifts.json';
    $legacyShiftGuard = $stateStore->beginWrite('shifts', $legacyShiftPath, []);
    $stateStore->write('shifts', $legacyShiftPath, [
        'version' => 1,
        'templates' => [],
        'deleted_seed_templates' => [],
        'weekend_plans' => [
            '2029-11-schedule-integrity-takii-com-tr' => [
                'key' => '2029-11-schedule-integrity-takii-com-tr',
                'month' => '2029-11',
                'profile_key' => 'schedule.integrity@takii.com.tr',
                'shift_key' => 'gunduz-shift',
                'working_days' => ['sat'],
                'note' => 'Legacy recurring plan',
            ],
        ],
    ]);
    $legacyShiftGuard->release();

    $shifts = new ShiftStore($profiles, $stateStore);
    $legacyPlans = array_values(array_filter(
        $shifts->weekendPlans(),
        static fn (array $plan): bool => ($plan['month'] ?? '') === '2029-11'
    ));
    scheduleAssert(count($legacyPlans) === 1, 'Legacy monthly plan was not preserved.');
    scheduleAssert(count($legacyPlans[0]['working_dates'] ?? []) >= 4, 'Legacy weekdays were not expanded to exact dates.');
    scheduleAssert(!array_key_exists('working_days', $legacyPlans[0]), 'Legacy recurring weekdays survived schema migration.');

    scheduleAssert(!empty($shifts->saveWeekendPlan([
        'month' => '2030-03',
        'profile_key' => 'schedule.integrity@takii.com.tr',
        'shift_key' => 'gunduz-shift',
        'working_dates' => ['2030-03-02', '2030-03-06'],
        'note' => 'Exact-date duty plan',
    ])['ok']), 'Exact-date monthly plan could not be saved.');
    scheduleAssert($shifts->isWorkingDateForUser($profile, '2030-03-02'), 'Selected duty date is not treated as working.');
    scheduleAssert(!$shifts->isWorkingDateForUser($profile, '2030-03-09'), 'The next occurrence of the same weekday was repeated unexpectedly.');
    scheduleAssert(($shifts->holidayForDate('2026-03-19')['day_part'] ?? '') === 'afternoon', 'Official half-day holiday is missing.');
    scheduleAssert(($shifts->holidayForDate('2026-03-20')['day_part'] ?? '') === 'full', 'Official full-day holiday is missing.');
    $overlappingHoliday = $shifts->holidayForDate('2033-01-01');
    scheduleAssert(($overlappingHoliday['day_part'] ?? '') === 'full', 'Overlapping public holidays did not retain the full-day rule.');
    scheduleAssert(count($overlappingHoliday['name_keys'] ?? []) === 2, 'Overlapping public holiday names were not preserved.');

    $leave = new LeaveStore($access, new LeaveApprovalMailer(), $stateStore, $profiles, $shifts);
    $entitlementPolicy = $leave->entitlementPolicy();
    scheduleAssert(array_column($entitlementPolicy['bands'] ?? [], 'min_year') === [1, 6, 15], 'Entitlement service-year ranges are inconsistent.');
    scheduleAssert(array_column($entitlementPolicy['bands'] ?? [], 'days') === [14, 20, 26], 'Entitlement day bands are inconsistent.');
    scheduleAssert((int) ($entitlementPolicy['age_minimum']['days'] ?? 0) === 20, 'Age-based minimum entitlement is inconsistent.');
    $dutyLeave = $leave->create($profile, [
        'starts_on' => '2030-03-02',
        'ends_on' => '2030-03-02',
        'type_key' => 'leave.type.annual',
        'day_part' => 'full',
        'note' => 'Exact duty date',
    ]);
    scheduleAssert(!empty($dutyLeave['ok']), 'Leave on the selected duty date was rejected.');
    $repeatedSaturday = $leave->create($profile, [
        'starts_on' => '2030-03-09',
        'ends_on' => '2030-03-09',
        'type_key' => 'leave.type.annual',
        'day_part' => 'full',
        'note' => 'Unselected repeated weekday',
    ]);
    scheduleAssert(empty($repeatedSaturday['ok']) && ($repeatedSaturday['message'] ?? '') === 'leave.flash.no_working_day', 'Unselected repeated weekday was counted as working.');

    $fullHolidayLeave = $leave->create($profile, [
        'starts_on' => '2030-04-23',
        'ends_on' => '2030-04-23',
        'type_key' => 'leave.type.annual',
        'day_part' => 'full',
        'note' => 'Official full holiday',
    ]);
    scheduleAssert(empty($fullHolidayLeave['ok']) && ($fullHolidayLeave['message'] ?? '') === 'leave.flash.no_working_day', 'Official full-day holiday consumed leave.');

    $halfHolidayLeave = $leave->create($profile, [
        'starts_on' => '2030-10-28',
        'ends_on' => '2030-10-28',
        'type_key' => 'leave.type.annual',
        'day_part' => 'full',
        'note' => 'Official afternoon holiday',
    ]);
    scheduleAssert(!empty($halfHolidayLeave['ok']), 'Morning work on an official half-day holiday was rejected.');
    $halfHolidayRequest = null;

    foreach ($leave->all() as $request) {
        if (($request['note'] ?? '') === 'Official afternoon holiday') {
            $halfHolidayRequest = $request;
            break;
        }
    }

    scheduleAssert(is_array($halfHolidayRequest) && (float) ($halfHolidayRequest['total_days'] ?? 0) === 0.5, 'Official half-day holiday did not reduce the leave charge to 0.5.');

    $leavePath = $testRoot . '/storage/leave-requests.json';
    $leaveGuard = $stateStore->beginWrite('leave_requests', $leavePath, ['version' => 1, 'requests' => []]);
    $leaveData = $stateStore->read('leave_requests', $leavePath, ['version' => 1, 'requests' => []]);
    unset($leaveData['requests'][0]['requester_id']);
    $stateStore->write('leave_requests', $leavePath, $leaveData);
    $leaveGuard->release();
    $leave = new LeaveStore($access, new LeaveApprovalMailer(), $stateStore, $profiles, $shifts);
    scheduleAssert((string) ($leave->all()[0]['requester_id'] ?? '') === $personnelId, 'Legacy leave requester ID was not backfilled.');

    $pendingBeforeRename = (float) ($leave->balanceForUser($profile)['pending_days'] ?? 0);
    $renamed = $profiles->updateProfile(
        'schedule.integrity@takii.com.tr',
        profileUpdatePayload($profile, 'Renamed', 'Employee')
    );
    scheduleAssert(!empty($renamed['ok']), 'Profile name change failed.');
    $renamedProfile = $profiles->find('schedule.integrity@takii.com.tr');
    scheduleAssert(is_array($renamedProfile), 'Renamed profile could not be loaded.');
    scheduleAssert((string) ($renamedProfile['personnel_id'] ?? '') === $personnelId, 'Personnel ID changed with the name.');
    $pendingAfterRename = (float) ($leave->balanceForUser($renamedProfile)['pending_days'] ?? 0);
    scheduleAssert(abs($pendingBeforeRename - $pendingAfterRename) < 0.001, 'Existing leave stopped affecting the balance after the name change.');

    $privacyViewerCreated = $profiles->createProfile([
        'new_email' => 'calendar.viewer@takii.com.tr',
        'first_name' => 'Calendar',
        'last_name' => 'Viewer',
        'role' => 'Finance Specialist',
        'department' => 'Finance',
        'pdks_id' => 'CALENDAR-001',
        'started_on' => '2020-01-01',
        'employment_type' => 'full_time',
        'shift_key' => 'gunduz-shift',
        'password' => 'calendar-test-123',
        'password_confirmation' => 'calendar-test-123',
    ]);
    scheduleAssert(!empty($privacyViewerCreated['ok']), 'Calendar privacy viewer could not be created.');
    $privacyViewer = $profiles->find('calendar.viewer@takii.com.tr');
    scheduleAssert(is_array($privacyViewer), 'Calendar privacy viewer could not be loaded.');

    $privateActor = 'PRIVATE-CALENDAR-DECISION-MAKER';
    $privateReason = 'PRIVATE-CALENDAR-REJECTION-REASON';
    $privateNote = 'PRIVATE-CALENDAR-REQUEST-NOTE';
    $privacyGuard = $stateStore->beginWrite('leave_requests', $leavePath, ['version' => 1, 'requests' => []]);
    $privacyData = $stateStore->read('leave_requests', $leavePath, ['version' => 1, 'requests' => []]);
    $privacyRequestFound = false;

    foreach ($privacyData['requests'] as &$privacyRequest) {
        if (($privacyRequest['starts_on'] ?? '') !== '2030-03-02') {
            continue;
        }

        $privacyRequest['note'] = $privateNote;
        $privacyRequest['status'] = 'rejected';
        $privacyRequest['calendar_state'] = 'blocked';
        $privacyRequest['approvals']['manager_1'] = array_merge(
            is_array($privacyRequest['approvals']['manager_1'] ?? null) ? $privacyRequest['approvals']['manager_1'] : [],
            [
                'status' => 'rejected',
                'actor' => $privateActor,
                'source' => 'platform',
                'acted_at' => '2030-02-28 10:00',
                'reason' => $privateReason,
            ]
        );
        $privacyRequestFound = true;
        break;
    }
    unset($privacyRequest);

    scheduleAssert($privacyRequestFound, 'Calendar privacy leave fixture could not be found.');
    $stateStore->write('leave_requests', $leavePath, $privacyData);
    $privacyGuard->release();

    $leave = new LeaveStore($access, new LeaveApprovalMailer(), $stateStore, $profiles, $shifts);
    $privacyCalendar = $leave->calendar('day', '2030-03-02', $privacyViewer);
    $privacyEvent = $privacyCalendar['days'][0]['events'][0] ?? null;
    scheduleAssert(is_array($privacyEvent), 'Same workforce group calendar event is missing.');

    foreach (['history', 'approvals', 'note'] as $privateField) {
        scheduleAssert(!array_key_exists($privateField, $privacyEvent), 'Calendar event exposed private field: ' . $privateField);
    }

    $privacyPayload = (string) json_encode($privacyCalendar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    scheduleAssert(!str_contains($privacyPayload, $privateActor), 'Calendar payload exposed the decision maker.');
    scheduleAssert(!str_contains($privacyPayload, $privateReason), 'Calendar payload exposed the rejection reason.');
    scheduleAssert(!str_contains($privacyPayload, $privateNote), 'Calendar payload exposed the request note.');

    $leaveViewSource = (string) file_get_contents($projectRoot . '/resources/views/leave/index.php');
    $appJsSource = (string) file_get_contents($projectRoot . '/public/assets/app.js');
    scheduleAssert(!str_contains($leaveViewSource, 'data-history'), 'Calendar HTML still contains a data-history attribute.');
    scheduleAssert(!str_contains($appJsSource, 'dataset.history'), 'Calendar JavaScript still reads approval history.');
    scheduleAssert(!str_contains($appJsSource, 'dataset.approvals'), 'Calendar JavaScript still reads approval flow data.');
    scheduleAssert(!str_contains($appJsSource, 'dataset.note'), 'Calendar JavaScript still reads request notes.');

    echo sprintf(
        "Leave and schedule integrity passed on %s: stable IDs, exact dates, public holidays, and calendar privacy verified.\n",
        $testDriver
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "Leave and schedule integrity failed: " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($databaseConnection instanceof PDO) {
        $databaseConnection->exec(
            "DELETE FROM app_state_documents WHERE document_key IN ('"
            . implode("','", $documentKeys)
            . "')"
        );
    }

    removeScheduleTestTree($testRoot);
}
