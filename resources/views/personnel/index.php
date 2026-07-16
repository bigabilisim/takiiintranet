<?php
$employmentTypes = ['full_time', 'part_time', 'contractor', 'intern'];
$educationLevels = ['', 'high_school', 'associate', 'bachelor', 'master', 'doctorate', 'other'];
$formatDays = static function (mixed $value): string {
    $number = is_numeric($value) ? (float) $value : 0.0;

    if (abs($number - round($number)) < 0.001) {
        return (string) (int) round($number);
    }

    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
};
$personnelGroupCounts = is_array($personnelGroupCounts ?? null) ? $personnelGroupCounts : [];
$personnelGroups = [
    'all' => ['label' => 'personnel.group.all', 'class' => 'all'],
    'office' => ['label' => 'personnel.group.office', 'class' => 'office'],
    'blue' => ['label' => 'personnel.group.blue', 'class' => 'blue'],
    'system' => ['label' => 'personnel.group.system', 'class' => 'system'],
];
$workforceAssignments = [
    'hr' => ['label' => 'personnel.assignment.hr', 'hint' => 'personnel.assignment.hr_hint'],
    'hr_assistant_antalya' => ['label' => 'personnel.assignment.hr_assistant_antalya', 'hint' => 'personnel.assignment.hr_assistant_antalya_hint'],
    'hr_assistant_bursa' => ['label' => 'personnel.assignment.hr_assistant_bursa', 'hint' => 'personnel.assignment.hr_assistant_bursa_hint'],
    'manager' => ['label' => 'personnel.assignment.manager', 'hint' => 'personnel.assignment.manager_hint'],
    'shift_planner' => ['label' => 'personnel.assignment.shift_planner', 'hint' => 'personnel.assignment.shift_planner_hint'],
    'weekend_duty' => ['label' => 'personnel.assignment.weekend_duty', 'hint' => 'personnel.assignment.weekend_duty_hint'],
];
$personnelGroupLabel = static fn (string $group): string => $personnelGroups[$group]['label'] ?? $personnelGroups['office']['label'];
$departmentOptions = is_array($departmentOptions ?? null) ? $departmentOptions : array_map(
    static fn (string $department): array => ['name' => $department, 'label' => $department, 'parent' => '', 'level' => 0],
    $departments
);
$departmentOptionNames = array_column($departmentOptions, 'name');
$departmentOptionLabel = static function (string $department) use (&$departmentOptions): string {
    foreach ($departmentOptions as $option) {
        if (($option['name'] ?? '') === $department) {
            return (string) ($option['label'] ?? $department);
        }
    }

    return $department;
};
$departmentSelectOptions = static function (string $currentDepartment) use ($departmentOptions, $departmentOptionNames, $departmentOptionLabel): array {
    $options = $departmentOptions;

    if ($currentDepartment !== '' && !in_array($currentDepartment, $departmentOptionNames, true)) {
        $options[] = [
            'name' => $currentDepartment,
            'label' => $departmentOptionLabel($currentDepartment),
            'parent' => '',
            'level' => 0,
        ];
    }

    return $options;
};
$locationOptions = is_array($locationOptions ?? null) ? $locationOptions : [];
$shiftTemplates = is_array($shiftTemplates ?? null) ? $shiftTemplates : [];
$shiftOptions = is_array($shiftOptions ?? null) ? $shiftOptions : [];
$temporaryCredential = is_array($temporaryCredential ?? null) ? $temporaryCredential : [];
$shiftOptionKeys = array_map(static fn (array $shiftOption): string => (string) ($shiftOption['key'] ?? ''), $shiftOptions);
$shiftMap = [];

foreach ($shiftTemplates as $shiftTemplate) {
    $shiftMap[(string) ($shiftTemplate['key'] ?? '')] = $shiftTemplate;
}

$shiftLabel = static function (string $shiftKey) use ($shiftMap, $t): string {
    if ($shiftKey !== '' && isset($shiftMap[$shiftKey])) {
        return (string) ($shiftMap[$shiftKey]['name'] ?? $shiftKey);
    }

    return $t('personnel.shift_unassigned');
};
?>

<section class="page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($t('personnel.eyebrow'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('personnel.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: #2f6f62">
        <?= htmlspecialchars($t('personnel.summary', ['count' => count($personnel)]), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ((string) ($temporaryCredential['password'] ?? '') !== ''): ?>
    <section class="personnel-credential-reveal" role="alert" aria-live="assertive">
        <div>
            <strong><?= htmlspecialchars($t('personnel.credential_title'), ENT_QUOTES, 'UTF-8') ?></strong>
            <span><?= htmlspecialchars((string) ($temporaryCredential['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <dl>
            <div>
                <dt><?= htmlspecialchars($t('personnel.username'), ENT_QUOTES, 'UTF-8') ?></dt>
                <dd><code><?= htmlspecialchars((string) ($temporaryCredential['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></dd>
            </div>
            <div>
                <dt><?= htmlspecialchars($t('personnel.temporary_password'), ENT_QUOTES, 'UTF-8') ?></dt>
                <dd><code><?= htmlspecialchars((string) $temporaryCredential['password'], ENT_QUOTES, 'UTF-8') ?></code></dd>
            </div>
        </dl>
        <small><?= htmlspecialchars($t('personnel.credential_once'), ENT_QUOTES, 'UTF-8') ?></small>
    </section>
<?php endif; ?>

<section class="personnel-panel">
    <div class="personnel-toolbar">
        <div>
            <strong><?= htmlspecialchars($t('personnel.table_title'), ENT_QUOTES, 'UTF-8') ?></strong>
            <span><?= htmlspecialchars($canWritePersonnel ? $t('personnel.mode.write') : $t('personnel.mode.read'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="personnel-toolbar-actions">
            <label class="personnel-search">
                <span><?= htmlspecialchars($t('personnel.search_label'), ENT_QUOTES, 'UTF-8') ?></span>
                <input
                    type="search"
                    data-personnel-filter
                    placeholder="<?= htmlspecialchars($t('personnel.search_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="off"
                >
            </label>
            <div class="personnel-group-filters" aria-label="<?= htmlspecialchars($t('personnel.group_filter'), ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($personnelGroups as $groupKey => $groupMeta): ?>
                    <button
                        class="personnel-group-filter personnel-group-filter--<?= htmlspecialchars($groupMeta['class'], ENT_QUOTES, 'UTF-8') ?> <?= $groupKey === 'all' ? 'is-active' : '' ?>"
                        type="button"
                        data-personnel-group-filter="<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>"
                        aria-pressed="<?= $groupKey === 'all' ? 'true' : 'false' ?>"
                    >
                        <span><?= htmlspecialchars($t($groupMeta['label']), ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars((string) ($personnelGroupCounts[$groupKey] ?? 0), ENT_QUOTES, 'UTF-8') ?></strong>
                    </button>
                <?php endforeach; ?>
            </div>
            <span><?= htmlspecialchars($t('personnel.permission_hint'), ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($canExportPersonnel): ?>
                <a class="button compact" href="/personnel/export/xlsx"><?= htmlspecialchars($t('personnel.export_xlsx'), ENT_QUOTES, 'UTF-8') ?></a>
                <a class="button compact" href="/personnel/export"><?= htmlspecialchars($t('personnel.export_csv'), ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canWritePersonnel): ?>
        <details class="personnel-create-card">
            <summary>
                <span>
                    <strong><?= htmlspecialchars($t('personnel.create_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <small><?= htmlspecialchars($t('personnel.create_subtitle'), ENT_QUOTES, 'UTF-8') ?></small>
                </span>
            </summary>

            <form class="personnel-edit-form" method="post" action="/personnel/create">
                <?= $csrf() ?>

                <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_identity'), ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="profile-grid">
                    <label>
                        <span><?= htmlspecialchars($t('auth.email'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="email" name="new_email" maxlength="160" placeholder="<?= htmlspecialchars($t('personnel.email_none'), ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('personnel.username'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="username" maxlength="40" pattern="[a-z0-9]{3,40}" placeholder="<?= htmlspecialchars($t('personnel.username_placeholder'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.first_name'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="first_name" maxlength="80" required>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.last_name'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="last_name" maxlength="80" required>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.role'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="role" maxlength="100" value="Personel" required>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.department'), ENT_QUOTES, 'UTF-8') ?></span>
                        <select name="department" required>
                            <option value=""><?= htmlspecialchars($t('admin.not_specified'), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php foreach ($departmentOptions as $departmentOption): ?>
                                <?php $departmentOptionName = (string) ($departmentOption['name'] ?? ''); ?>
                                <option value="<?= htmlspecialchars($departmentOptionName, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string) ($departmentOption['label'] ?? $departmentOptionName), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.location'), ENT_QUOTES, 'UTF-8') ?></span>
                        <select name="location" required>
                            <option value=""><?= htmlspecialchars($t('admin.not_specified'), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php foreach ($locationOptions as $locationKey => $locationLabelKey): ?>
                                <option value="<?= htmlspecialchars((string) $locationKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($t((string) $locationLabelKey), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.pdks_id'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="pdks_id" maxlength="80">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.started_on'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="date" name="started_on">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.employment_type'), ENT_QUOTES, 'UTF-8') ?></span>
                        <select name="employment_type">
                            <?php foreach ($employmentTypes as $employmentType): ?>
                                <option value="<?= htmlspecialchars($employmentType, ENT_QUOTES, 'UTF-8') ?>" <?= $employmentType === 'full_time' ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t('admin.employment.' . $employmentType), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <strong class="profile-section-title"><?= htmlspecialchars($t('personnel.assignment_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="personnel-assignment-heading">
                    <small><?= htmlspecialchars($t('personnel.assignment_hint'), ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="profile-assignment-grid">
                    <?php foreach ($workforceAssignments as $assignmentKey => $assignmentMeta): ?>
                        <label class="profile-assignment-card">
                            <input type="checkbox" name="workforce_roles[]" value="<?= htmlspecialchars($assignmentKey, ENT_QUOTES, 'UTF-8') ?>"<?= str_starts_with($assignmentKey, 'hr_assistant_') ? ' data-hr-assistant-location-role' : '' ?>>
                            <span>
                                <strong><?= htmlspecialchars($t($assignmentMeta['label']), ENT_QUOTES, 'UTF-8') ?></strong>
                                <small><?= htmlspecialchars($t($assignmentMeta['hint']), ENT_QUOTES, 'UTF-8') ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <strong class="profile-section-title"><?= htmlspecialchars($t('personnel.shift_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="personnel-assignment-heading">
                    <small><?= htmlspecialchars($t('personnel.shift_hint'), ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="profile-grid">
                    <label>
                        <span><?= htmlspecialchars($t('personnel.column.shift'), ENT_QUOTES, 'UTF-8') ?></span>
                        <select name="shift_key">
                            <option value=""><?= htmlspecialchars($t('personnel.shift_unassigned'), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php foreach ($shiftOptions as $shiftOption): ?>
                                <option value="<?= htmlspecialchars((string) ($shiftOption['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string) ($shiftOption['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_hr'), ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="profile-grid">
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.phone'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="phone" maxlength="40">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.personal_phone'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="personal_phone" maxlength="40">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.birth_date'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="date" name="birth_date">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.leave_opening_total_days'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="number" name="leave_opening_total_days" min="0" step="0.5" value="0">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.leave_opening_used_days'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="number" name="leave_opening_used_days" min="0" step="0.5" value="0">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.leave_opening_remaining_days'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="number" name="leave_opening_remaining_days" min="0" step="0.5" value="0">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.leave_opening_snapshot_date'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="date" name="leave_opening_snapshot_date">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.leave_opening_source'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="leave_opening_source" maxlength="120">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.national_id'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="national_id" maxlength="40">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.emergency_contact_name'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="emergency_contact_name" maxlength="120">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.emergency_contact_phone'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="emergency_contact_phone" maxlength="40">
                    </label>
                    <label class="profile-field-wide">
                        <span><?= htmlspecialchars($t('admin.profile.address'), ENT_QUOTES, 'UTF-8') ?></span>
                        <textarea name="address" rows="2" maxlength="400"></textarea>
                    </label>
                </div>

                <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_education'), ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="profile-grid">
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.education_level'), ENT_QUOTES, 'UTF-8') ?></span>
                        <select name="education_level">
                            <?php foreach ($educationLevels as $educationLevel): ?>
                                <option value="<?= htmlspecialchars($educationLevel, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($educationLevel === '' ? $t('admin.not_specified') : $t('admin.education.' . $educationLevel), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.school'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="school" maxlength="160">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.faculty'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="faculty" maxlength="160">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.graduation_year'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="number" name="graduation_year" min="1900" max="2099">
                    </label>
                    <label class="profile-field-wide">
                        <span><?= htmlspecialchars($t('admin.profile.hr_notes'), ENT_QUOTES, 'UTF-8') ?></span>
                        <textarea name="hr_notes" rows="2" maxlength="600"></textarea>
                    </label>
                </div>

                <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_access'), ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="profile-grid">
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.password'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="password" name="password" minlength="6" autocomplete="new-password">
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('admin.profile.password_confirmation'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="password" name="password_confirmation" minlength="6" autocomplete="new-password">
                    </label>
                </div>

                <div class="personnel-actions">
                    <button class="button compact" type="submit"><?= htmlspecialchars($t('personnel.create_submit'), ENT_QUOTES, 'UTF-8') ?></button>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <div class="personnel-table" role="table" aria-label="<?= htmlspecialchars($t('personnel.table_title'), ENT_QUOTES, 'UTF-8') ?>">
        <div class="personnel-table-head" role="row">
            <span><?= htmlspecialchars($t('personnel.column.person'), ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars($t('admin.profile.department'), ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars($t('admin.profile.role'), ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars($t('personnel.column.shift'), ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars($t('admin.profile.pdks_id'), ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars($t('leave.balance.remaining'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <?php $currentPersonnelGroup = null; ?>
        <?php foreach ($personnel as $profile): ?>
            <?php
                $profileKey = (string) ($profile['profile_key'] ?? ($profile['email'] ?? ''));
                $email = (string) ($profile['email'] ?? '');
                $username = (string) ($profile['username'] ?? '');
                $emailLabel = $email !== '' ? $email : $t('personnel.email_none');
                $profileGroup = (string) ($profile['personnel_group'] ?? 'office');
                $profileGroup = array_key_exists($profileGroup, $personnelGroups) && $profileGroup !== 'all' ? $profileGroup : 'office';
                $profileGroupClass = $personnelGroups[$profileGroup]['class'];
                $profileGroupLabel = $t($personnelGroupLabel($profileGroup));
                $profileShiftKey = (string) ($profile['shift_key'] ?? '');
                $profileShiftLabel = $shiftLabel($profileShiftKey);
                $profileLocation = (string) ($profile['location'] ?? '');
                $profileLocationLabel = $profileLocation !== '' ? $t('location.' . $profileLocation) : $t('admin.not_specified');
                $profileWorkforceRoles = is_array($profile['workforce_roles'] ?? null)
                    ? array_values(array_intersect(array_keys($workforceAssignments), $profile['workforce_roles']))
                    : [];
                $profileWorkforceLabels = array_map(
                    static fn (string $role): string => $t($workforceAssignments[$role]['label']),
                    $profileWorkforceRoles
                );
                $currentDepartmentOptions = $departmentSelectOptions((string) ($profile['department'] ?? ''));
                $canDeleteThisProfile = $canDeletePersonnel && !empty($deletableEmails[$profileKey]);
                $searchText = trim(implode(' ', [
                    $profileKey,
                    $email,
                    $username,
                    $emailLabel,
                    (string) ($profile['name'] ?? ''),
                    (string) ($profile['first_name'] ?? ''),
                    (string) ($profile['last_name'] ?? ''),
                    (string) ($profile['department'] ?? ''),
                    $profileLocationLabel,
                    (string) ($profile['role'] ?? ''),
                    $profileShiftLabel,
                    (string) ($profile['pdks_id'] ?? ''),
                    $profileGroupLabel,
                    implode(' ', $profileWorkforceLabels),
                ]));
            ?>
            <?php if ($currentPersonnelGroup !== $profileGroup): ?>
                <?php $currentPersonnelGroup = $profileGroup; ?>
                <div
                    class="personnel-group-header personnel-group-header--<?= htmlspecialchars($profileGroupClass, ENT_QUOTES, 'UTF-8') ?>"
                    data-personnel-group-header
                    data-personnel-group="<?= htmlspecialchars($profileGroup, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <span><?= htmlspecialchars($profileGroupLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars($t('personnel.group_count', ['count' => (string) ($personnelGroupCounts[$profileGroup] ?? 0)]), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
            <?php endif; ?>
            <details
                class="personnel-table-row personnel-table-row--<?= htmlspecialchars($profileGroupClass, ENT_QUOTES, 'UTF-8') ?>"
                data-personnel-row
                data-personnel-group="<?= htmlspecialchars($profileGroup, ENT_QUOTES, 'UTF-8') ?>"
                data-personnel-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>"
            >
                <summary>
                    <span>
                        <em class="personnel-group-chip personnel-group-chip--<?= htmlspecialchars($profileGroupClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($profileGroupLabel, ENT_QUOTES, 'UTF-8') ?></em>
                        <strong><?= htmlspecialchars((string) ($profile['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                        <small>@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></small>
                        <small><?= htmlspecialchars($emailLabel, ENT_QUOTES, 'UTF-8') ?></small>
                        <?php if ($profileWorkforceRoles !== []): ?>
                            <span class="personnel-assignment-badges">
                                <?php foreach ($profileWorkforceRoles as $assignmentRole): ?>
                                    <em><?= htmlspecialchars($t($workforceAssignments[$assignmentRole]['label']), ENT_QUOTES, 'UTF-8') ?></em>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <span>
                        <?= htmlspecialchars((string) ($profile['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        <small><?= htmlspecialchars($profileLocationLabel, ENT_QUOTES, 'UTF-8') ?></small>
                    </span>
                    <span><?= htmlspecialchars((string) ($profile['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= htmlspecialchars($profileShiftLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= htmlspecialchars((string) ($profile['pdks_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= htmlspecialchars($formatDays($profile['leave_opening_remaining_days'] ?? 0), ENT_QUOTES, 'UTF-8') ?></span>
                </summary>

                <?php if ($canWritePersonnel): ?>
                    <form class="personnel-edit-form" method="post" action="/personnel/update">
                        <?= $csrf() ?>
                        <input type="hidden" name="profile_key" value="<?= htmlspecialchars($profileKey, ENT_QUOTES, 'UTF-8') ?>">

                        <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_identity'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <div class="profile-grid">
                            <label>
                                <span><?= htmlspecialchars($t('auth.email'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="email" name="new_email" maxlength="160" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($t('personnel.email_none'), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('personnel.username'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="username" maxlength="40" pattern="[a-z0-9]{3,40}" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" required>
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.first_name'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="first_name" maxlength="80" value="<?= htmlspecialchars((string) ($profile['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.last_name'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="last_name" maxlength="80" value="<?= htmlspecialchars((string) ($profile['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.role'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="role" maxlength="100" value="<?= htmlspecialchars((string) ($profile['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.department'), ENT_QUOTES, 'UTF-8') ?></span>
                                <select name="department" required>
                                    <?php foreach ($currentDepartmentOptions as $departmentOption): ?>
                                        <?php $departmentOptionName = (string) ($departmentOption['name'] ?? ''); ?>
                                        <option value="<?= htmlspecialchars($departmentOptionName, ENT_QUOTES, 'UTF-8') ?>" <?= ($profile['department'] ?? '') === $departmentOptionName ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) ($departmentOption['label'] ?? $departmentOptionName), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.location'), ENT_QUOTES, 'UTF-8') ?></span>
                                <select name="location" required>
                                    <?php foreach ($locationOptions as $locationKey => $locationLabelKey): ?>
                                        <option value="<?= htmlspecialchars((string) $locationKey, ENT_QUOTES, 'UTF-8') ?>" <?= $profileLocation === (string) $locationKey ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($t((string) $locationLabelKey), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.pdks_id'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="pdks_id" maxlength="80" value="<?= htmlspecialchars((string) ($profile['pdks_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.started_on'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="date" name="started_on" value="<?= htmlspecialchars((string) ($profile['started_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.employment_type'), ENT_QUOTES, 'UTF-8') ?></span>
                                <select name="employment_type">
                                    <option value=""><?= htmlspecialchars($t('admin.not_specified'), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php foreach ($employmentTypes as $employmentType): ?>
                                        <option value="<?= htmlspecialchars($employmentType, ENT_QUOTES, 'UTF-8') ?>" <?= ($profile['employment_type'] ?? '') === $employmentType ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($t('admin.employment.' . $employmentType), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <strong class="profile-section-title"><?= htmlspecialchars($t('personnel.assignment_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <div class="personnel-assignment-heading">
                            <small><?= htmlspecialchars($t('personnel.assignment_hint'), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                        <div class="profile-assignment-grid">
                            <?php foreach ($workforceAssignments as $assignmentKey => $assignmentMeta): ?>
                                <label class="profile-assignment-card">
                                    <input
                                        type="checkbox"
                                        name="workforce_roles[]"
                                        value="<?= htmlspecialchars($assignmentKey, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= str_starts_with($assignmentKey, 'hr_assistant_') ? 'data-hr-assistant-location-role' : '' ?>
                                        <?= in_array($assignmentKey, $profileWorkforceRoles, true) ? 'checked' : '' ?>
                                    >
                                    <span>
                                        <strong><?= htmlspecialchars($t($assignmentMeta['label']), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <small><?= htmlspecialchars($t($assignmentMeta['hint']), ENT_QUOTES, 'UTF-8') ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <strong class="profile-section-title"><?= htmlspecialchars($t('personnel.shift_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <div class="personnel-assignment-heading">
                            <small><?= htmlspecialchars($t('personnel.shift_hint'), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                        <div class="profile-grid">
                            <label>
                                <span><?= htmlspecialchars($t('personnel.column.shift'), ENT_QUOTES, 'UTF-8') ?></span>
                                <select name="shift_key">
                                    <option value=""><?= htmlspecialchars($t('personnel.shift_unassigned'), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php if ($profileShiftKey !== '' && !in_array($profileShiftKey, $shiftOptionKeys, true)): ?>
                                        <option value="<?= htmlspecialchars($profileShiftKey, ENT_QUOTES, 'UTF-8') ?>" selected>
                                            <?= htmlspecialchars($profileShiftLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endif; ?>
                                    <?php foreach ($shiftOptions as $shiftOption): ?>
                                        <?php $shiftOptionKey = (string) ($shiftOption['key'] ?? ''); ?>
                                        <option value="<?= htmlspecialchars($shiftOptionKey, ENT_QUOTES, 'UTF-8') ?>" <?= $profileShiftKey === $shiftOptionKey ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) ($shiftOption['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_hr'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <div class="profile-grid">
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.phone'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="phone" maxlength="40" value="<?= htmlspecialchars((string) ($profile['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.personal_phone'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="personal_phone" maxlength="40" value="<?= htmlspecialchars((string) ($profile['personal_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.birth_date'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="date" name="birth_date" value="<?= htmlspecialchars((string) ($profile['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.leave_opening_total_days'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="number" name="leave_opening_total_days" min="0" step="0.5" value="<?= htmlspecialchars((string) ($profile['leave_opening_total_days'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.leave_opening_used_days'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="number" name="leave_opening_used_days" min="0" step="0.5" value="<?= htmlspecialchars((string) ($profile['leave_opening_used_days'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.leave_opening_remaining_days'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="number" name="leave_opening_remaining_days" min="0" step="0.5" value="<?= htmlspecialchars((string) ($profile['leave_opening_remaining_days'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.leave_opening_snapshot_date'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="date" name="leave_opening_snapshot_date" value="<?= htmlspecialchars((string) ($profile['leave_opening_snapshot_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.leave_opening_source'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="leave_opening_source" maxlength="120" value="<?= htmlspecialchars((string) ($profile['leave_opening_source'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.national_id'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="national_id" maxlength="40" value="<?= htmlspecialchars((string) ($profile['national_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.emergency_contact_name'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="emergency_contact_name" maxlength="120" value="<?= htmlspecialchars((string) ($profile['emergency_contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.emergency_contact_phone'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="emergency_contact_phone" maxlength="40" value="<?= htmlspecialchars((string) ($profile['emergency_contact_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label class="profile-field-wide">
                                <span><?= htmlspecialchars($t('admin.profile.address'), ENT_QUOTES, 'UTF-8') ?></span>
                                <textarea name="address" rows="2" maxlength="400"><?= htmlspecialchars((string) ($profile['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </label>
                        </div>

                        <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_education'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <div class="profile-grid">
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.education_level'), ENT_QUOTES, 'UTF-8') ?></span>
                                <select name="education_level">
                                    <?php foreach ($educationLevels as $educationLevel): ?>
                                        <option value="<?= htmlspecialchars($educationLevel, ENT_QUOTES, 'UTF-8') ?>" <?= ($profile['education_level'] ?? '') === $educationLevel ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($educationLevel === '' ? $t('admin.not_specified') : $t('admin.education.' . $educationLevel), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.school'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="school" maxlength="160" value="<?= htmlspecialchars((string) ($profile['school'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.faculty'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="faculty" maxlength="160" value="<?= htmlspecialchars((string) ($profile['faculty'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.graduation_year'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="number" name="graduation_year" min="1900" max="2099" value="<?= htmlspecialchars((string) ($profile['graduation_year'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label class="profile-field-wide">
                                <span><?= htmlspecialchars($t('admin.profile.hr_notes'), ENT_QUOTES, 'UTF-8') ?></span>
                                <textarea name="hr_notes" rows="2" maxlength="600"><?= htmlspecialchars((string) ($profile['hr_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </label>
                        </div>

                        <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_access'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <div class="profile-grid">
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.password'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="password" name="password" minlength="6" autocomplete="new-password">
                            </label>
                            <label>
                                <span><?= htmlspecialchars($t('admin.profile.password_confirmation'), ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="password" name="password_confirmation" minlength="6" autocomplete="new-password">
                            </label>
                        </div>

                        <div class="personnel-actions">
                            <?php if ($canManageCredentials): ?>
                                <button
                                    class="button compact secondary"
                                    type="submit"
                                    formaction="/personnel/reset-password"
                                    formnovalidate
                                    onclick="return confirm('<?= htmlspecialchars($t('personnel.password_reset_confirm'), ENT_QUOTES, 'UTF-8') ?>')"
                                ><?= htmlspecialchars($t('personnel.password_reset'), ENT_QUOTES, 'UTF-8') ?></button>
                            <?php endif; ?>
                            <?php if ($canDeleteThisProfile): ?>
                                <button
                                    class="button compact danger"
                                    type="submit"
                                    formaction="/personnel/delete"
                                    formnovalidate
                                    onclick="return confirm('<?= htmlspecialchars($t('personnel.delete_confirm'), ENT_QUOTES, 'UTF-8') ?>')"
                                ><?= htmlspecialchars($t('admin.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                            <?php endif; ?>
                            <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.save'), ENT_QUOTES, 'UTF-8') ?></button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="personnel-readonly">
                        <span><?= htmlspecialchars($t('personnel.readonly_notice'), ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars((string) ($profile['phone'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <strong><?= htmlspecialchars((string) ($profile['started_on'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <strong><?= htmlspecialchars($profileShiftLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
        <p class="personnel-empty" data-personnel-empty hidden><?= htmlspecialchars($t('personnel.search_empty'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</section>
