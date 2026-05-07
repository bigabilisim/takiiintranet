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
                            <?php foreach ($departments as $departmentOption): ?>
                                <option value="<?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?>
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
            <span><?= htmlspecialchars($t('admin.profile.pdks_id'), ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars($t('leave.balance.remaining'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <?php foreach ($personnel as $profile): ?>
            <?php
                $profileKey = (string) ($profile['profile_key'] ?? ($profile['email'] ?? ''));
                $email = (string) ($profile['email'] ?? '');
                $emailLabel = $email !== '' ? $email : $t('personnel.email_none');
                $currentDepartmentOptions = array_values(array_unique(array_filter(array_merge(
                    $departments,
                    [(string) ($profile['department'] ?? '')]
                ))));
                sort($currentDepartmentOptions);
                $canDeleteThisProfile = $canDeletePersonnel && !empty($deletableEmails[$profileKey]);
                $searchText = trim(implode(' ', [
                    $profileKey,
                    $email,
                    $emailLabel,
                    (string) ($profile['name'] ?? ''),
                    (string) ($profile['first_name'] ?? ''),
                    (string) ($profile['last_name'] ?? ''),
                    (string) ($profile['department'] ?? ''),
                    (string) ($profile['role'] ?? ''),
                    (string) ($profile['pdks_id'] ?? ''),
                ]));
            ?>
            <details class="personnel-table-row" data-personnel-row data-personnel-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                <summary>
                    <span>
                        <strong><?= htmlspecialchars((string) ($profile['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars($emailLabel, ENT_QUOTES, 'UTF-8') ?></small>
                    </span>
                    <span><?= htmlspecialchars((string) ($profile['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= htmlspecialchars((string) ($profile['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
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
                                        <option value="<?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?>" <?= ($profile['department'] ?? '') === $departmentOption ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?>
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
                    </div>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
        <p class="personnel-empty" data-personnel-empty hidden><?= htmlspecialchars($t('personnel.search_empty'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</section>
