<?php
$usersByEmail = [];
foreach ($users as $knownUser) {
    $usersByEmail[$knownUser['email']] = $knownUser;
}
$userName = fn (string $email): string => $usersByEmail[$email]['name'] ?? $email;
$employmentTypes = ['full_time', 'part_time', 'contractor', 'intern'];
$educationLevels = ['', 'high_school', 'associate', 'bachelor', 'master', 'doctorate', 'other'];
?>

<section class="page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($t('admin.eyebrow'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('admin.access_title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: #2f6f62">
        <?= htmlspecialchars($t('admin.access_summary'), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="admin-stack">
    <div class="admin-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('admin.settings'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="admin-settings-grid">
            <a class="button compact" href="/admin/access/users/export"><?= htmlspecialchars($t('admin.personnel_export'), ENT_QUOTES, 'UTF-8') ?></a>
            <form class="personnel-import-form" method="post" action="/admin/access/users/import" enctype="multipart/form-data">
                <?= $csrf() ?>
                <label>
                    <span><?= htmlspecialchars($t('admin.personnel_csv'), ENT_QUOTES, 'UTF-8') ?></span>
                    <input type="file" name="personnel_file" accept=".csv,text/csv" required>
                </label>
                <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.personnel_import'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>
        <p class="admin-settings-hint"><?= htmlspecialchars($t('admin.personnel_import_hint'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="admin-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('admin.user_permissions'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="permission-matrix user-directory-list">
            <?php foreach ($users as $managedUser): ?>
                <?php
                    $currentDepartmentOptions = array_values(array_unique(array_filter(array_merge(
                        $departments,
                        [(string) ($managedUser['department'] ?? '')]
                    ))));
                    sort($currentDepartmentOptions);
                ?>
                <details class="user-directory-item">
                    <summary class="user-directory-row">
                        <span class="user-directory-main">
                            <strong><?= htmlspecialchars($managedUser['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="user-directory-meta"><?= htmlspecialchars($managedUser['email'] . ' / ' . $managedUser['role'] . ' / ' . $managedUser['department'], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <span class="button compact user-directory-edit"><?= htmlspecialchars($t('admin.edit'), ENT_QUOTES, 'UTF-8') ?></span>
                    </summary>
                    <form class="permission-card user-edit-panel" method="post" action="/admin/access/users" data-permission-card>
                    <?= $csrf() ?>
                    <input type="hidden" name="email" value="<?= htmlspecialchars($managedUser['email'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="user-edit-actions">
                        <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.save'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                    <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_identity'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="profile-grid">
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.first_name'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="first_name" maxlength="80" value="<?= htmlspecialchars((string) ($managedUser['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.last_name'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="last_name" maxlength="80" value="<?= htmlspecialchars((string) ($managedUser['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.role'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="role" maxlength="100" value="<?= htmlspecialchars((string) ($managedUser['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.department'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="department" required>
                                <?php foreach ($currentDepartmentOptions as $departmentOption): ?>
                                    <option value="<?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?>" <?= ($managedUser['department'] ?? '') === $departmentOption ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.pdks_id'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="pdks_id" maxlength="80" value="<?= htmlspecialchars((string) ($managedUser['pdks_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.started_on'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="date" name="started_on" value="<?= htmlspecialchars((string) ($managedUser['started_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.employment_type'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="employment_type">
                                <option value=""><?= htmlspecialchars($t('admin.not_specified'), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php foreach ($employmentTypes as $employmentType): ?>
                                    <option value="<?= htmlspecialchars($employmentType, ENT_QUOTES, 'UTF-8') ?>" <?= ($managedUser['employment_type'] ?? '') === $employmentType ? 'selected' : '' ?>>
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
                            <input type="text" name="phone" maxlength="40" value="<?= htmlspecialchars((string) ($managedUser['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.personal_phone'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="personal_phone" maxlength="40" value="<?= htmlspecialchars((string) ($managedUser['personal_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.birth_date'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="date" name="birth_date" value="<?= htmlspecialchars((string) ($managedUser['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.leave_opening_total_days'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="number" name="leave_opening_total_days" min="0" step="0.5" value="<?= htmlspecialchars((string) ($managedUser['leave_opening_total_days'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.leave_opening_used_days'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="number" name="leave_opening_used_days" min="0" step="0.5" value="<?= htmlspecialchars((string) ($managedUser['leave_opening_used_days'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.leave_opening_remaining_days'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="number" name="leave_opening_remaining_days" min="0" step="0.5" value="<?= htmlspecialchars((string) ($managedUser['leave_opening_remaining_days'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.leave_opening_snapshot_date'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="date" name="leave_opening_snapshot_date" value="<?= htmlspecialchars((string) ($managedUser['leave_opening_snapshot_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.leave_opening_source'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="leave_opening_source" maxlength="120" value="<?= htmlspecialchars((string) ($managedUser['leave_opening_source'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.national_id'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="national_id" maxlength="40" value="<?= htmlspecialchars((string) ($managedUser['national_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.emergency_contact_name'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="emergency_contact_name" maxlength="120" value="<?= htmlspecialchars((string) ($managedUser['emergency_contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.emergency_contact_phone'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="emergency_contact_phone" maxlength="40" value="<?= htmlspecialchars((string) ($managedUser['emergency_contact_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="profile-field-wide">
                            <span><?= htmlspecialchars($t('admin.profile.address'), ENT_QUOTES, 'UTF-8') ?></span>
                            <textarea name="address" rows="2" maxlength="400"><?= htmlspecialchars((string) ($managedUser['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </label>
                    </div>
                    <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_education'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="profile-grid">
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.education_level'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="education_level">
                                <?php foreach ($educationLevels as $educationLevel): ?>
                                    <option value="<?= htmlspecialchars($educationLevel, ENT_QUOTES, 'UTF-8') ?>" <?= ($managedUser['education_level'] ?? '') === $educationLevel ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($educationLevel === '' ? $t('admin.not_specified') : $t('admin.education.' . $educationLevel), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.school'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="school" maxlength="160" value="<?= htmlspecialchars((string) ($managedUser['school'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.faculty'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="faculty" maxlength="160" value="<?= htmlspecialchars((string) ($managedUser['faculty'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('admin.profile.graduation_year'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="number" name="graduation_year" min="1900" max="2099" value="<?= htmlspecialchars((string) ($managedUser['graduation_year'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="profile-field-wide">
                            <span><?= htmlspecialchars($t('admin.profile.hr_notes'), ENT_QUOTES, 'UTF-8') ?></span>
                            <textarea name="hr_notes" rows="2" maxlength="600"><?= htmlspecialchars((string) ($managedUser['hr_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
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
                    <strong class="profile-section-title"><?= htmlspecialchars($t('admin.profile_permissions'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="permission-grid">
                        <?php foreach ($permissionCatalog as $permission): ?>
                            <?php $isChecked = in_array($permission['permission'], $managedUser['permissions'], true); ?>
                            <label class="toggle-row <?= ($permission['parent_permission'] ?? '') !== '' ? 'is-dependent' : '' ?>">
                                <input
                                    type="checkbox"
                                    name="permissions[]"
                                    value="<?= htmlspecialchars($permission['permission'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-permission-input="<?= htmlspecialchars($permission['permission'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-is-module-permission="<?= str_starts_with($permission['permission'], 'module.') ? '1' : '0' ?>"
                                    <?= ($permission['parent_permission'] ?? '') !== '' ? 'data-parent-permission="' . htmlspecialchars($permission['parent_permission'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                                    <?= $isChecked ? 'checked' : '' ?>
                                    <?= $managedUser['is_system_admin'] && $permission['permission'] === 'admin.company.manage' ? 'disabled' : '' ?>
                                >
                                <?php if ($managedUser['is_system_admin'] && $permission['permission'] === 'admin.company.manage'): ?>
                                    <input type="hidden" name="permissions[]" value="admin.company.manage">
                                <?php endif; ?>
                                <span>
                                    <strong><?= htmlspecialchars($t($permission['label_key']), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <small><?= htmlspecialchars($t($permission['group_key']), ENT_QUOTES, 'UTF-8') ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="user-edit-actions">
                        <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.save'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="admin-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('admin.departments'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <form class="department-create-form" method="post" action="/admin/access/departments/create">
            <?= $csrf() ?>
            <label>
                <span><?= htmlspecialchars($t('admin.department_name'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="text" name="department_name" maxlength="100" required>
            </label>
            <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.add_department'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <div class="permission-matrix user-directory-list department-directory-list">
            <?php foreach ($departments as $department): ?>
                <?php $policy = $departmentPolicies[$department] ?? []; ?>
                <?php $departmentUserCount = (int) ($departmentUserCounts[$department] ?? 0); ?>
                <?php
                    $departmentFlow = array_values(array_filter([
                        $userName($policy['manager_1_email'] ?? ''),
                        (int) ($policy['manager_approval_count'] ?? 1) === 2 ? $userName($policy['manager_2_email'] ?? '') : '',
                        $userName($policy['hr_email'] ?? ''),
                    ], fn (string $name): bool => $name !== ''));
                ?>
                <details class="user-directory-item department-directory-item">
                    <summary class="user-directory-row">
                        <span class="user-directory-main">
                            <strong><?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="user-directory-meta">
                                <?= htmlspecialchars($t('admin.policy_preview'), ENT_QUOTES, 'UTF-8') ?>
                                <?= htmlspecialchars($departmentFlow !== [] ? implode(' / ', $departmentFlow) : $t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?>
                                / <?= htmlspecialchars($t('admin.department_user_count', ['count' => $departmentUserCount]), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </span>
                        <span class="button compact user-directory-edit"><?= htmlspecialchars($t('admin.edit'), ENT_QUOTES, 'UTF-8') ?></span>
                    </summary>
                    <form class="department-policy-card department-edit-panel" method="post" action="/admin/access/departments">
                    <?= $csrf() ?>
                    <input type="hidden" name="department" value="<?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="user-edit-actions">
                        <button
                            class="button compact danger"
                            type="submit"
                            formaction="/admin/access/departments/delete"
                            formnovalidate
                            onclick="return confirm('<?= htmlspecialchars($t('admin.delete_department_confirm'), ENT_QUOTES, 'UTF-8') ?>')"
                        ><?= htmlspecialchars($t('admin.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.save'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                    <strong class="profile-section-title"><?= htmlspecialchars($t('admin.department_leave_policy'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="profile-grid department-policy-fields">
                        <label>
                            <span><?= htmlspecialchars($t('admin.manager_approval_count'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="manager_approval_count">
                                <option value="1" <?= (int) ($policy['manager_approval_count'] ?? 1) === 1 ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t('leave.manager_count.one'), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                                <option value="2" <?= (int) ($policy['manager_approval_count'] ?? 1) === 2 ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t('leave.manager_count.two'), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            </select>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('leave.stage.manager_1'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="manager_1_email">
                                <option value=""><?= htmlspecialchars($t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php foreach ($users as $candidate): ?>
                                    <option value="<?= htmlspecialchars($candidate['email'], ENT_QUOTES, 'UTF-8') ?>" <?= ($policy['manager_1_email'] ?? '') === $candidate['email'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($candidate['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('leave.stage.manager_2'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="manager_2_email">
                                <option value=""><?= htmlspecialchars($t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php foreach ($users as $candidate): ?>
                                    <option value="<?= htmlspecialchars($candidate['email'], ENT_QUOTES, 'UTF-8') ?>" <?= ($policy['manager_2_email'] ?? '') === $candidate['email'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($candidate['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('leave.stage.hr'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="hr_email">
                                <option value=""><?= htmlspecialchars($t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php foreach ($users as $candidate): ?>
                                    <option value="<?= htmlspecialchars($candidate['email'], ENT_QUOTES, 'UTF-8') ?>" <?= ($policy['hr_email'] ?? '') === $candidate['email'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($candidate['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <p>
                        <?= htmlspecialchars($t('admin.policy_preview'), ENT_QUOTES, 'UTF-8') ?>
                        <strong><?= htmlspecialchars($departmentFlow !== [] ? implode(' / ', $departmentFlow) : $t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?></strong>
                        / <?= htmlspecialchars($t('admin.department_user_count', ['count' => $departmentUserCount]), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="user-edit-actions">
                        <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.save'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="admin-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('admin.audit_logs'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <?php if (empty($auditLogs)): ?>
            <p class="empty-inline"><?= htmlspecialchars($t('admin.audit_empty'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <div class="audit-log-list">
                <?php foreach ($auditLogs as $entry): ?>
                    <?php
                        $actionKey = 'audit.action.' . (string) ($entry['action'] ?? '');
                        $actionLabel = $t($actionKey);
                        $actionLabel = $actionLabel === $actionKey ? (string) ($entry['action'] ?? '') : $actionLabel;
                    ?>
                    <article class="audit-log-row">
                        <strong><?= htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars((string) ($entry['entity_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <small>
                            <?= htmlspecialchars((string) ($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            / <?= htmlspecialchars((string) (($entry['actor_name'] ?? '') ?: ($entry['actor_email'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                        </small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
