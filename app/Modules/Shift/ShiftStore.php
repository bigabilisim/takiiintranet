<?php

namespace App\Modules\Shift;

use App\Core\LocationScope;
use App\Core\StateStore;
use App\Core\UserProfileStore;
use DateTimeImmutable;
use RuntimeException;

class ShiftStore
{
    private const VERSION = 2;
    private const STATE_KEY = 'shifts';
    private const DAY_ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    private const HOLIDAY_FULL = 'full';
    private const HOLIDAY_AFTERNOON = 'afternoon';

    public function __construct(
        private readonly UserProfileStore $userProfiles,
        private readonly StateStore $stateStore,
    ) {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $this->data();
    }

    public function templates(): array
    {
        $templates = array_values($this->data()['templates'] ?? []);

        foreach ($templates as &$template) {
            $template = $this->decorateTemplate($template);
        }
        unset($template);

        usort($templates, fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $templates;
    }

    public function enabledTemplates(): array
    {
        return array_values(array_filter(
            $this->templates(),
            static fn (array $template): bool => !empty($template['is_enabled'])
        ));
    }

    public function templateMap(): array
    {
        $map = [];

        foreach ($this->templates() as $template) {
            $map[(string) ($template['key'] ?? '')] = $template;
        }

        return $map;
    }

    public function findTemplate(string $key): ?array
    {
        $key = $this->cleanKey($key);

        if ($key === '') {
            return null;
        }

        $template = $this->data()['templates'][$key] ?? null;

        return is_array($template) ? $this->decorateTemplate($template) : null;
    }

    public function saveTemplate(array $input): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $name = $this->cleanText((string) ($input['name'] ?? ''), 120);
        $startsAt = $this->cleanTime((string) ($input['starts_at'] ?? ''));
        $endsAt = $this->cleanTime((string) ($input['ends_at'] ?? ''));
        $days = $this->daysFromInput($input);

        if ($name === '' || $startsAt === '' || $endsAt === '' || $days === []) {
            return ['ok' => false, 'message' => 'shift.flash.invalid'];
        }

        $data = $this->loadWritableData();
        $data['version'] = self::VERSION;
        $data['templates'] = is_array($data['templates'] ?? null) ? $data['templates'] : [];

        $currentKey = $this->cleanKey((string) ($input['shift_key'] ?? ''));
        $key = $currentKey !== '' && isset($data['templates'][$currentKey])
            ? $currentKey
            : $this->uniqueKey($this->slug($name), $data['templates']);

        $template = [
            'key' => $key,
            'name' => $name,
            'days' => $days,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_minutes' => max(0, min(240, (int) ($input['break_minutes'] ?? 0))),
            'is_enabled' => !empty($input['is_enabled']),
            'created_at' => (string) ($data['templates'][$key]['created_at'] ?? date('Y-m-d H:i')),
            'updated_at' => date('Y-m-d H:i'),
        ];

        $data['templates'][$key] = $template;
        $this->saveData($data);

        return [
            'ok' => true,
            'message' => 'shift.flash.saved',
            'template' => $this->decorateTemplate($template),
        ];
    }

    public function deleteTemplate(string $key): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $key = $this->cleanKey($key);
        $data = $this->loadWritableData();
        $data['templates'] = is_array($data['templates'] ?? null) ? $data['templates'] : [];
        $data['deleted_seed_templates'] = is_array($data['deleted_seed_templates'] ?? null) ? $data['deleted_seed_templates'] : [];

        if ($key === '' || !isset($data['templates'][$key])) {
            return ['ok' => false, 'message' => 'shift.flash.template_required'];
        }

        $assignedCount = $this->assignedCountFor($key);

        if ($assignedCount > 0) {
            return [
                'ok' => false,
                'message' => 'shift.flash.delete_blocked_assigned',
                'assigned_count' => $assignedCount,
            ];
        }

        unset($data['templates'][$key]);

        if (array_key_exists($key, $this->seedTemplates())) {
            $data['deleted_seed_templates'][$key] = date('Y-m-d H:i');
        }

        $this->saveData($data);

        return ['ok' => true, 'message' => 'shift.flash.deleted'];
    }

    public function personnel(?array $viewer = null): array
    {
        $templates = $this->templateMap();
        $personnel = array_values($this->userProfiles->users());

        if (is_array($viewer)) {
            $personnel = array_values(array_filter(
                $personnel,
                static fn (array $profile): bool => LocationScope::canView($viewer, $profile)
            ));
        }

        foreach ($personnel as &$profile) {
            $shiftKey = (string) ($profile['shift_key'] ?? '');
            $template = $templates[$shiftKey] ?? null;
            $profile['shift_label'] = is_array($template) ? (string) ($template['name'] ?? '') : '';
            $profile['shift_summary'] = is_array($template) ? (string) ($template['summary'] ?? '') : '';
        }
        unset($profile);

        usort($personnel, fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $personnel;
    }

    public function weekendDutyPersonnel(?array $viewer = null): array
    {
        return array_values(array_filter(
            $this->personnel($viewer),
            fn (array $profile): bool => $this->isWeekendDutyProfile($profile)
        ));
    }

    public function holidays(): array
    {
        $holidays = array_values($this->data()['holidays'] ?? []);

        foreach ($holidays as &$holiday) {
            $holiday = $this->decorateHoliday($holiday);
        }
        unset($holiday);

        usort($holidays, fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        return $holidays;
    }

    public function holidayForDate(string $date): ?array
    {
        $date = $this->cleanDate($date);

        if ($date === '') {
            return null;
        }

        $holiday = $this->data()['holidays'][$date] ?? null;

        return is_array($holiday) ? $this->decorateHoliday($holiday) : null;
    }

    public function saveHoliday(array $input): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $date = $this->cleanDate((string) ($input['date'] ?? ''));
        $name = $this->cleanText((string) ($input['name'] ?? ''), 120);
        $dayPart = (string) ($input['day_part'] ?? self::HOLIDAY_FULL);
        $dayPart = in_array($dayPart, [self::HOLIDAY_FULL, self::HOLIDAY_AFTERNOON], true)
            ? $dayPart
            : '';

        if ($date === '' || $name === '' || $dayPart === '') {
            return ['ok' => false, 'message' => 'shift.flash.holiday_invalid'];
        }

        $data = $this->data();
        $current = is_array($data['holidays'][$date] ?? null) ? $data['holidays'][$date] : [];

        if (($current['source'] ?? '') === 'official') {
            return ['ok' => false, 'message' => 'shift.flash.holiday_official_locked'];
        }

        $holiday = [
            'key' => $date,
            'date' => $date,
            'name' => $name,
            'name_keys' => [],
            'day_part' => $dayPart,
            'country_code' => 'TR',
            'source' => 'manual',
            'source_url' => '',
            'created_at' => (string) ($current['created_at'] ?? date('Y-m-d H:i')),
            'updated_at' => date('Y-m-d H:i'),
        ];
        $data['version'] = self::VERSION;
        $data['holidays'][$date] = $holiday;
        $this->saveData($data);

        return ['ok' => true, 'message' => 'shift.flash.holiday_saved', 'holiday' => $this->decorateHoliday($holiday)];
    }

    public function deleteHoliday(string $date): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $date = $this->cleanDate($date);
        $data = $this->data();
        $holiday = is_array($data['holidays'][$date] ?? null) ? $data['holidays'][$date] : null;

        if ($holiday === null) {
            return ['ok' => false, 'message' => 'shift.flash.holiday_not_found'];
        }

        if (($holiday['source'] ?? '') === 'official') {
            return ['ok' => false, 'message' => 'shift.flash.holiday_official_locked'];
        }

        unset($data['holidays'][$date]);
        $this->saveData($data);

        return ['ok' => true, 'message' => 'shift.flash.holiday_deleted'];
    }

    public function isWorkingDateForUser(array $user, DateTimeImmutable|string $date): bool
    {
        $day = $date instanceof DateTimeImmutable ? $date : new DateTimeImmutable($date);
        $dateValue = $day->format('Y-m-d');
        $month = $day->format('Y-m');
        $data = $this->data();

        foreach (($data['weekend_plans'] ?? []) as $plan) {
            if (!is_array($plan) || (string) ($plan['month'] ?? '') !== $month || !$this->planBelongsToUser($plan, $user)) {
                continue;
            }

            return in_array($dateValue, $this->cleanWorkingDates($plan['working_dates'] ?? [], $month), true);
        }

        $shiftKey = $this->cleanKey((string) ($user['shift_key'] ?? ''));
        $template = is_array($data['templates'][$shiftKey] ?? null) ? $data['templates'][$shiftKey] : [];
        $workingDays = $this->cleanDays($template['days'] ?? []);

        if ($workingDays === []) {
            $workingDays = ['mon', 'tue', 'wed', 'thu', 'fri'];
        }

        return in_array($this->dayKey($day), $workingDays, true);
    }

    public function weekendPlans(?array $viewer = null): array
    {
        $plans = array_values($this->data()['weekend_plans'] ?? []);
        $templates = $this->templateMap();
        $users = $this->userProfiles->users();

        if (is_array($viewer)) {
            $plans = array_values(array_filter($plans, function (array $plan) use ($users, $viewer): bool {
                $profile = $users[(string) ($plan['profile_key'] ?? '')] ?? null;

                return is_array($profile) && LocationScope::canView($viewer, $profile);
            }));
        }

        foreach ($plans as &$plan) {
            $profileKey = (string) ($plan['profile_key'] ?? '');
            $shiftKey = (string) ($plan['shift_key'] ?? '');
            $profile = is_array($users[$profileKey] ?? null) ? $users[$profileKey] : [];
            $template = is_array($templates[$shiftKey] ?? null) ? $templates[$shiftKey] : [];

            $plan['profile_name'] = (string) ($profile['name'] ?? $profileKey);
            $plan['department'] = (string) ($profile['department'] ?? '');
            $plan['shift_name'] = (string) ($template['name'] ?? $shiftKey);
            $plan['personnel_id'] = (string) ($plan['personnel_id'] ?? ($profile['personnel_id'] ?? ''));
            $plan['working_dates'] = $this->cleanWorkingDates($plan['working_dates'] ?? [], (string) ($plan['month'] ?? ''));
            $plan['working_date_count'] = count($plan['working_dates']);
            $plan['note'] = $this->cleanText((string) ($plan['note'] ?? ''), 240);
        }
        unset($plan);

        usort($plans, function (array $a, array $b): int {
            $month = strcmp((string) ($b['month'] ?? ''), (string) ($a['month'] ?? ''));

            return $month !== 0 ? $month : strcmp((string) ($a['profile_name'] ?? ''), (string) ($b['profile_name'] ?? ''));
        });

        return $plans;
    }

    public function saveWeekendPlan(array $input, ?array $viewer = null): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $month = $this->cleanMonth((string) ($input['month'] ?? ''));
        $profileKey = $this->cleanProfileKey((string) ($input['profile_key'] ?? ''));
        $shiftKey = $this->cleanKey((string) ($input['shift_key'] ?? ''));
        $workingDates = $this->cleanWorkingDates($input['working_dates'] ?? [], $month);

        if ($workingDates === [] && isset($input['working_days'])) {
            $workingDates = $this->expandWorkingDays($month, $this->cleanDays($input['working_days'] ?? []));
        }

        if ($month === '' || $profileKey === '' || $shiftKey === '' || $workingDates === []) {
            return ['ok' => false, 'message' => 'shift.flash.weekend_plan_invalid'];
        }

        $users = $this->userProfiles->users();

        if (!is_array($users[$profileKey] ?? null)) {
            return ['ok' => false, 'message' => 'shift.flash.personnel_required'];
        }

        if (is_array($viewer) && !LocationScope::canView($viewer, $users[$profileKey])) {
            return ['ok' => false, 'message' => 'shift.flash.not_allowed'];
        }

        if (!$this->isWeekendDutyProfile($users[$profileKey])) {
            return ['ok' => false, 'message' => 'shift.flash.weekend_plan_scope'];
        }

        if ($this->findTemplate($shiftKey) === null) {
            return ['ok' => false, 'message' => 'shift.flash.template_required'];
        }

        $data = $this->loadWritableData();
        $data['version'] = self::VERSION;
        $data['templates'] = is_array($data['templates'] ?? null) ? $data['templates'] : [];
        $data['weekend_plans'] = is_array($data['weekend_plans'] ?? null) ? $data['weekend_plans'] : [];

        $key = $this->weekendPlanKey($month, $profileKey);
        $previous = is_array($data['weekend_plans'][$key] ?? null) ? $data['weekend_plans'][$key] : [];

        $data['weekend_plans'][$key] = [
            'key' => $key,
            'month' => $month,
            'profile_key' => $profileKey,
            'personnel_id' => (string) ($users[$profileKey]['personnel_id'] ?? ''),
            'shift_key' => $shiftKey,
            'working_dates' => $workingDates,
            'note' => $this->cleanText((string) ($input['note'] ?? ''), 240),
            'created_at' => (string) ($previous['created_at'] ?? date('Y-m-d H:i')),
            'updated_at' => date('Y-m-d H:i'),
        ];

        $this->saveData($data);

        return [
            'ok' => true,
            'message' => 'shift.flash.weekend_plan_saved',
            'plan' => $data['weekend_plans'][$key],
        ];
    }

    public function deleteWeekendPlan(string $key, ?array $viewer = null): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $key = $this->cleanKey($key);
        $data = $this->loadWritableData();
        $data['weekend_plans'] = is_array($data['weekend_plans'] ?? null) ? $data['weekend_plans'] : [];

        if ($key === '' || !isset($data['weekend_plans'][$key])) {
            return ['ok' => false, 'message' => 'shift.flash.weekend_plan_not_found'];
        }

        $profileKey = (string) ($data['weekend_plans'][$key]['profile_key'] ?? '');
        $profile = $this->userProfiles->find($profileKey);

        if (is_array($viewer) && (!is_array($profile) || !LocationScope::canView($viewer, $profile))) {
            return ['ok' => false, 'message' => 'shift.flash.not_allowed'];
        }

        unset($data['weekend_plans'][$key]);
        $this->saveData($data);

        return ['ok' => true, 'message' => 'shift.flash.weekend_plan_deleted'];
    }

    public function migrateUserIdentity(string $oldIdentity, string $newIdentity): int
    {
        if ($oldIdentity === '' || $newIdentity === '' || $oldIdentity === $newIdentity) {
            return 0;
        }

        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $data = $this->loadWritableData();
        $plans = is_array($data['weekend_plans'] ?? null) ? $data['weekend_plans'] : [];
        $migrated = 0;

        foreach ($plans as $currentKey => $plan) {
            if (!is_array($plan) || (string) ($plan['profile_key'] ?? '') !== $oldIdentity) {
                continue;
            }

            $newKey = $this->weekendPlanKey((string) ($plan['month'] ?? ''), $newIdentity);

            if ($newKey === '') {
                throw new RuntimeException('Unable to generate the migrated weekend plan key.');
            }

            if ($newKey !== (string) $currentKey && isset($plans[$newKey])) {
                throw new RuntimeException('The target identity already has a weekend plan for this month.');
            }

            unset($plans[$currentKey]);
            $plan['key'] = $newKey;
            $plan['profile_key'] = $newIdentity;
            $plan['updated_at'] = date('Y-m-d H:i');
            $plans[$newKey] = $plan;
            $migrated++;
        }

        if ($migrated > 0) {
            $data['weekend_plans'] = $plans;
            $this->saveData($data);
        }

        return $migrated;
    }

    public function assignToProfiles(string $shiftKey, array $profileKeys, bool $allProfiles, ?array $viewer = null): array
    {
        $shiftKey = $this->cleanKey($shiftKey);

        if ($shiftKey !== '') {
            $template = $this->findTemplate($shiftKey);

            if ($template === null || empty($template['is_enabled'])) {
                return ['ok' => false, 'message' => 'shift.flash.template_required'];
            }
        }

        $users = $this->userProfiles->users();

        if ($allProfiles) {
            $profileKeys = array_keys(array_filter(
                $users,
                static fn (array $profile): bool => !is_array($viewer) || LocationScope::canView($viewer, $profile)
            ));
        }

        $profileKeys = array_values(array_unique(array_filter(array_map('strval', $profileKeys))));

        if ($profileKeys === []) {
            return ['ok' => false, 'message' => 'shift.flash.personnel_required'];
        }

        foreach ($profileKeys as $profileKey) {
            $profile = $users[$profileKey] ?? null;

            if (!is_array($profile) || (is_array($viewer) && !LocationScope::canView($viewer, $profile))) {
                return ['ok' => false, 'message' => 'shift.flash.not_allowed'];
            }
        }

        if ($shiftKey !== '') {
            $conflicts = $this->assignmentConflicts($shiftKey, $profileKeys);

            if ($conflicts !== []) {
                return [
                    'ok' => false,
                    'message' => 'shift.flash.assignment_conflict',
                    'conflict_count' => count($conflicts),
                    'conflicts' => $conflicts,
                    'shift_key' => $shiftKey,
                ];
            }
        }

        $result = $this->userProfiles->setShiftForProfiles($profileKeys, $shiftKey);

        return [
            'ok' => ((int) ($result['updated'] ?? 0)) > 0,
            'message' => ((int) ($result['updated'] ?? 0)) > 0 ? 'shift.flash.assigned' : 'shift.flash.personnel_required',
            'updated' => (int) ($result['updated'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'shift_key' => $shiftKey,
        ];
    }

    public function dayLabels(): array
    {
        return [
            'mon' => 'shift.day.mon',
            'tue' => 'shift.day.tue',
            'wed' => 'shift.day.wed',
            'thu' => 'shift.day.thu',
            'fri' => 'shift.day.fri',
            'sat' => 'shift.day.sat',
            'sun' => 'shift.day.sun',
        ];
    }

    private function assignmentConflicts(string $targetShiftKey, array $profileKeys): array
    {
        $users = $this->userProfiles->users();
        $templates = $this->templateMap();
        $conflicts = [];

        foreach ($profileKeys as $profileKey) {
            $profileKey = (string) $profileKey;
            $profile = $users[$profileKey] ?? null;

            if (!is_array($profile)) {
                continue;
            }

            $currentShiftKey = $this->cleanKey((string) ($profile['shift_key'] ?? ''));

            if ($currentShiftKey === '' || $currentShiftKey === $targetShiftKey) {
                continue;
            }

            $conflicts[] = [
                'profile_key' => $profileKey,
                'name' => (string) ($profile['name'] ?? $profileKey),
                'shift_key' => $currentShiftKey,
                'shift_name' => (string) ($templates[$currentShiftKey]['name'] ?? $currentShiftKey),
            ];
        }

        return $conflicts;
    }

    private function isWeekendDutyProfile(array $profile): bool
    {
        $roles = is_array($profile['workforce_roles'] ?? null) ? $profile['workforce_roles'] : [];

        return in_array('weekend_duty', array_map('strval', $roles), true);
    }

    private function decorateTemplate(array $template): array
    {
        $template['key'] = $this->cleanKey((string) ($template['key'] ?? ''));
        $template['days'] = $this->cleanDays($template['days'] ?? []);
        $template['starts_at'] = $this->cleanTime((string) ($template['starts_at'] ?? '08:00')) ?: '08:00';
        $template['ends_at'] = $this->cleanTime((string) ($template['ends_at'] ?? '17:00')) ?: '17:00';
        $template['break_minutes'] = max(0, min(240, (int) ($template['break_minutes'] ?? 0)));
        $template['is_enabled'] = !empty($template['is_enabled']);
        $template['assigned_count'] = $this->assignedCountFor((string) ($template['key'] ?? ''));
        $template['weekly_hours'] = $this->weeklyHours($template);
        $template['summary'] = $this->summary($template);

        return $template;
    }

    private function weeklyHours(array $template): float
    {
        $startsAt = $this->minutes((string) ($template['starts_at'] ?? '00:00'));
        $endsAt = $this->minutes((string) ($template['ends_at'] ?? '00:00'));
        $duration = $endsAt - $startsAt;

        if ($duration <= 0) {
            $duration += 24 * 60;
        }

        $duration = max(0, $duration - (int) ($template['break_minutes'] ?? 0));

        return round((count((array) ($template['days'] ?? [])) * $duration) / 60, 2);
    }

    private function summary(array $template): string
    {
        return implode(', ', (array) ($template['days'] ?? []))
            . ' / ' . (string) ($template['starts_at'] ?? '')
            . '-' . (string) ($template['ends_at'] ?? '');
    }

    private function assignedCountFor(string $shiftKey): int
    {
        if ($shiftKey === '') {
            return 0;
        }

        $count = 0;

        foreach ($this->userProfiles->users() as $profile) {
            if ((string) ($profile['shift_key'] ?? '') === $shiftKey) {
                $count++;
            }
        }

        return $count;
    }

    private function daysFromInput(array $input): array
    {
        $pattern = (string) ($input['day_pattern'] ?? 'custom');

        if ($pattern === 'weekdays') {
            return ['mon', 'tue', 'wed', 'thu', 'fri'];
        }

        if ($pattern === 'weekend') {
            return ['sat', 'sun'];
        }

        if ($pattern === 'all') {
            return self::DAY_ORDER;
        }

        return $this->cleanDays($input['days'] ?? []);
    }

    private function cleanDays(mixed $days): array
    {
        $days = is_array($days) ? $days : [];
        $selected = array_values(array_intersect(self::DAY_ORDER, array_map('strval', $days)));

        return array_values(array_filter(self::DAY_ORDER, static fn (string $day): bool => in_array($day, $selected, true)));
    }

    private function cleanMonth(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\\d{4}-\\d{2}$/', $value) === 1 ? $value : '';
    }

    private function cleanDate(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function cleanWorkingDates(mixed $dates, string $month): array
    {
        $month = $this->cleanMonth($month);

        if ($month === '') {
            return [];
        }

        $dates = is_array($dates) ? $dates : [];
        $clean = [];

        foreach ($dates as $date) {
            $date = $this->cleanDate((string) $date);

            if ($date !== '' && str_starts_with($date, $month . '-')) {
                $clean[$date] = true;
            }
        }

        $clean = array_keys($clean);
        sort($clean);

        return $clean;
    }

    private function expandWorkingDays(string $month, array $workingDays): array
    {
        $month = $this->cleanMonth($month);

        if ($month === '' || $workingDays === []) {
            return [];
        }

        $day = new DateTimeImmutable($month . '-01');
        $dates = [];

        while ($day->format('Y-m') === $month) {
            if (in_array($this->dayKey($day), $workingDays, true)) {
                $dates[] = $day->format('Y-m-d');
            }

            $day = $day->modify('+1 day');
        }

        return $dates;
    }

    private function cleanProfileKey(string $value): string
    {
        return substr(trim($value), 0, 160);
    }

    private function weekendPlanKey(string $month, string $profileKey): string
    {
        return $this->cleanKey($month . '-' . $this->slug($profileKey));
    }

    private function planBelongsToUser(array $plan, array $user): bool
    {
        $planPersonnelId = trim((string) ($plan['personnel_id'] ?? ''));
        $userPersonnelId = trim((string) ($user['personnel_id'] ?? ''));

        if ($planPersonnelId !== '' && $userPersonnelId !== '') {
            return hash_equals($planPersonnelId, $userPersonnelId);
        }

        $profileKey = (string) ($plan['profile_key'] ?? '');
        $userKeys = array_values(array_unique(array_filter(array_map('trim', [
            (string) ($user['profile_key'] ?? ''),
            (string) ($user['email'] ?? ''),
            (string) ($user['pdks_id'] ?? ''),
        ]))));

        return $profileKey !== '' && in_array($profileKey, $userKeys, true);
    }

    private function dayKey(DateTimeImmutable $day): string
    {
        return self::DAY_ORDER[((int) $day->format('N')) - 1] ?? 'mon';
    }

    private function data(): array
    {
        $data = $this->loadWritableData();
        $dirty = false;

        if (!is_array($data['templates'] ?? null)) {
            $data['templates'] = [];
            $dirty = true;
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            $data['version'] = self::VERSION;
            $dirty = true;
        }

        if (!is_array($data['deleted_seed_templates'] ?? null)) {
            $data['deleted_seed_templates'] = [];
            $dirty = true;
        }

        if (!is_array($data['weekend_plans'] ?? null)) {
            $data['weekend_plans'] = [];
            $dirty = true;
        }

        if (!is_array($data['holidays'] ?? null)) {
            $data['holidays'] = [];
            $dirty = true;
        }

        foreach ($data['weekend_plans'] as $key => $plan) {
            if (!is_array($plan)) {
                unset($data['weekend_plans'][$key]);
                $dirty = true;
                continue;
            }

            $month = $this->cleanMonth((string) ($plan['month'] ?? ''));
            $workingDates = array_key_exists('working_dates', $plan)
                ? $this->cleanWorkingDates($plan['working_dates'], $month)
                : $this->expandWorkingDays($month, $this->cleanDays($plan['working_days'] ?? []));
            $profile = $this->userProfiles->find((string) ($plan['profile_key'] ?? ''));
            $normalized = array_merge($plan, [
                'working_dates' => $workingDates,
                'personnel_id' => (string) ($plan['personnel_id'] ?? ($profile['personnel_id'] ?? '')),
            ]);
            unset($normalized['working_days']);

            if ($normalized != $plan) {
                $data['weekend_plans'][$key] = $normalized;
                $dirty = true;
            }
        }

        foreach (array_keys($data['deleted_seed_templates']) as $deletedSeedKey) {
            if (isset($data['templates'][$deletedSeedKey])) {
                unset($data['templates'][$deletedSeedKey]);
                $dirty = true;
            }
        }

        foreach ($this->seedTemplates() as $key => $template) {
            if (isset($data['templates'][$key])) {
                continue;
            }

            if (isset($data['deleted_seed_templates'][$key])) {
                continue;
            }

            $data['templates'][$key] = $template;
            $dirty = true;
        }

        foreach ($this->seedHolidays() as $date => $holiday) {
            $existing = is_array($data['holidays'][$date] ?? null) ? $data['holidays'][$date] : [];

            if (($existing['source'] ?? '') === 'manual') {
                continue;
            }

            $holiday['created_at'] = (string) ($existing['created_at'] ?? $holiday['created_at']);
            $holiday['updated_at'] = (string) ($existing['updated_at'] ?? $holiday['updated_at']);

            if ($existing != $holiday) {
                $data['holidays'][$date] = $holiday;
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->saveData($data);
        }

        return $data;
    }

    private function seedTemplates(): array
    {
        return [
            'gunduz-shift' => [
                'key' => 'gunduz-shift',
                'name' => 'Gunduz Shift',
                'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                'starts_at' => '08:00',
                'ends_at' => '17:00',
                'break_minutes' => 60,
                'is_enabled' => true,
                'created_at' => date('Y-m-d H:i'),
                'updated_at' => date('Y-m-d H:i'),
            ],
            'gece-shift' => [
                'key' => 'gece-shift',
                'name' => 'Gece Shift',
                'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                'starts_at' => '20:00',
                'ends_at' => '04:00',
                'break_minutes' => 60,
                'is_enabled' => false,
                'created_at' => date('Y-m-d H:i'),
                'updated_at' => date('Y-m-d H:i'),
            ],
        ];
    }

    private function seedHolidays(): array
    {
        $holidays = [];

        for ($year = 2025; $year <= 2035; $year++) {
            $this->addOfficialHoliday($holidays, $year . '-01-01', 'shift.holiday.name.new_year');
            $this->addOfficialHoliday($holidays, $year . '-04-23', 'shift.holiday.name.national_sovereignty');
            $this->addOfficialHoliday($holidays, $year . '-05-01', 'shift.holiday.name.labor_day');
            $this->addOfficialHoliday($holidays, $year . '-05-19', 'shift.holiday.name.youth_sports');
            $this->addOfficialHoliday($holidays, $year . '-07-15', 'shift.holiday.name.democracy_unity');
            $this->addOfficialHoliday($holidays, $year . '-08-30', 'shift.holiday.name.victory');
            $this->addOfficialHoliday($holidays, $year . '-10-28', 'shift.holiday.name.republic', self::HOLIDAY_AFTERNOON);
            $this->addOfficialHoliday($holidays, $year . '-10-29', 'shift.holiday.name.republic');
        }

        $religious = [
            2025 => ['2025-03-29', ['2025-03-30', '2025-03-31', '2025-04-01'], '2025-06-05', ['2025-06-06', '2025-06-07', '2025-06-08', '2025-06-09']],
            2026 => ['2026-03-19', ['2026-03-20', '2026-03-21', '2026-03-22'], '2026-05-26', ['2026-05-27', '2026-05-28', '2026-05-29', '2026-05-30']],
            2027 => ['2027-03-08', ['2027-03-09', '2027-03-10', '2027-03-11'], '2027-05-15', ['2027-05-16', '2027-05-17', '2027-05-18', '2027-05-19']],
            2028 => ['2028-02-25', ['2028-02-26', '2028-02-27', '2028-02-28'], '2028-05-04', ['2028-05-05', '2028-05-06', '2028-05-07', '2028-05-08']],
            2029 => ['2029-02-13', ['2029-02-14', '2029-02-15', '2029-02-16'], '2029-04-23', ['2029-04-24', '2029-04-25', '2029-04-26', '2029-04-27']],
            2030 => ['2030-02-03', ['2030-02-04', '2030-02-05', '2030-02-06'], '2030-04-12', ['2030-04-13', '2030-04-14', '2030-04-15', '2030-04-16']],
            2031 => ['2031-01-23', ['2031-01-24', '2031-01-25', '2031-01-26'], '2031-04-01', ['2031-04-02', '2031-04-03', '2031-04-04', '2031-04-05']],
            2032 => ['2032-01-13', ['2032-01-14', '2032-01-15', '2032-01-16'], '2032-03-21', ['2032-03-22', '2032-03-23', '2032-03-24', '2032-03-25']],
        ];

        foreach ($religious as [$ramadanEve, $ramadanDays, $sacrificeEve, $sacrificeDays]) {
            $this->addOfficialHoliday($holidays, $ramadanEve, 'shift.holiday.name.ramadan_eve', self::HOLIDAY_AFTERNOON);

            foreach ($ramadanDays as $date) {
                $this->addOfficialHoliday($holidays, $date, 'shift.holiday.name.ramadan');
            }

            $this->addOfficialHoliday($holidays, $sacrificeEve, 'shift.holiday.name.sacrifice_eve', self::HOLIDAY_AFTERNOON);

            foreach ($sacrificeDays as $date) {
                $this->addOfficialHoliday($holidays, $date, 'shift.holiday.name.sacrifice');
            }
        }

        foreach ([
            ['2033-01-01', ['2033-01-02', '2033-01-03', '2033-01-04']],
            ['2033-12-22', ['2033-12-23', '2033-12-24', '2033-12-25']],
            ['2034-12-11', ['2034-12-12', '2034-12-13', '2034-12-14']],
            ['2035-11-30', ['2035-12-01', '2035-12-02', '2035-12-03']],
        ] as [$eve, $feastDays]) {
            $this->addOfficialHoliday($holidays, $eve, 'shift.holiday.name.ramadan_eve', self::HOLIDAY_AFTERNOON);

            foreach ($feastDays as $date) {
                $this->addOfficialHoliday($holidays, $date, 'shift.holiday.name.ramadan');
            }
        }

        foreach ([
            ['2033-03-10', ['2033-03-11', '2033-03-12', '2033-03-13', '2033-03-14']],
            ['2034-02-28', ['2034-03-01', '2034-03-02', '2034-03-03', '2034-03-04']],
            ['2035-02-17', ['2035-02-18', '2035-02-19', '2035-02-20', '2035-02-21']],
        ] as [$eve, $feastDays]) {
            $this->addOfficialHoliday($holidays, $eve, 'shift.holiday.name.sacrifice_eve', self::HOLIDAY_AFTERNOON);

            foreach ($feastDays as $date) {
                $this->addOfficialHoliday($holidays, $date, 'shift.holiday.name.sacrifice');
            }
        }

        ksort($holidays);

        return $holidays;
    }

    private function addOfficialHoliday(array &$holidays, string $date, string $nameKey, string $dayPart = self::HOLIDAY_FULL): void
    {
        $current = is_array($holidays[$date] ?? null) ? $holidays[$date] : [];
        $nameKeys = array_values(array_unique(array_merge(
            is_array($current['name_keys'] ?? null) ? $current['name_keys'] : [],
            [$nameKey]
        )));
        $resolvedDayPart = ($current['day_part'] ?? '') === self::HOLIDAY_FULL || $dayPart === self::HOLIDAY_FULL
            ? self::HOLIDAY_FULL
            : self::HOLIDAY_AFTERNOON;

        $holidays[$date] = [
            'key' => $date,
            'date' => $date,
            'name' => '',
            'name_keys' => $nameKeys,
            'day_part' => $resolvedDayPart,
            'country_code' => 'TR',
            'source' => 'official',
            'source_url' => 'https://vakithesaplama.diyanet.gov.tr/2429_kanun.php',
            'created_at' => (string) ($current['created_at'] ?? date('Y-m-d H:i')),
            'updated_at' => (string) ($current['updated_at'] ?? date('Y-m-d H:i')),
        ];
    }

    private function decorateHoliday(array $holiday): array
    {
        $holiday['key'] = $this->cleanDate((string) ($holiday['key'] ?? $holiday['date'] ?? ''));
        $holiday['date'] = $this->cleanDate((string) ($holiday['date'] ?? $holiday['key'] ?? ''));
        $holiday['name'] = $this->cleanText((string) ($holiday['name'] ?? ''), 120);
        $holiday['name_keys'] = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($holiday['name_keys'] ?? null) ? $holiday['name_keys'] : []
        ))));
        $holiday['day_part'] = ($holiday['day_part'] ?? '') === self::HOLIDAY_AFTERNOON
            ? self::HOLIDAY_AFTERNOON
            : self::HOLIDAY_FULL;
        $holiday['duration_days'] = $holiday['day_part'] === self::HOLIDAY_AFTERNOON ? 0.5 : 1.0;
        $holiday['source'] = ($holiday['source'] ?? '') === 'manual' ? 'manual' : 'official';

        return $holiday;
    }

    private function loadWritableData(): array
    {
        return $this->stateStore->read(self::STATE_KEY, $this->dataPath(), $this->emptyData());
    }

    private function saveData(array $data): void
    {
        $this->stateStore->write(self::STATE_KEY, $this->dataPath(), $data);
    }

    private function emptyData(): array
    {
        return [
            'version' => self::VERSION,
            'templates' => [],
            'deleted_seed_templates' => [],
            'weekend_plans' => [],
            'holidays' => [],
        ];
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/shifts.json';
    }

    private function uniqueKey(string $base, array $templates): string
    {
        $base = $base !== '' ? $base : 'shift';
        $key = $base;
        $index = 2;

        while (isset($templates[$key])) {
            $key = $base . '-' . $index;
            $index++;
        }

        return $key;
    }

    private function slug(string $value): string
    {
        $value = strtr($value, [
            'İ' => 'I',
            'ı' => 'i',
            'Ş' => 'S',
            'ş' => 's',
            'Ğ' => 'G',
            'ğ' => 'g',
            'Ü' => 'U',
            'ü' => 'u',
            'Ö' => 'O',
            'ö' => 'o',
            'Ç' => 'C',
            'ç' => 'c',
        ]);
        $value = strtolower($value);

        return substr(trim(preg_replace('/[^a-z0-9]+/', '-', $value) ?? '', '-'), 0, 80);
    }

    private function cleanKey(string $value): string
    {
        return substr(trim(preg_replace('/[^a-z0-9-]+/', '', strtolower($value)) ?? '', '-'), 0, 80);
    }

    private function cleanText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return substr($value, 0, $maxLength);
    }

    private function cleanTime(string $value): string
    {
        $value = trim($value);

        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            return '';
        }

        return $value;
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
