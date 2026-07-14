<?php
$usersByEmail = [];
foreach ($users as $knownUser) {
    $usersByEmail[$knownUser['email']] = $knownUser;
}
$userName = fn (string $email): string => $usersByEmail[$email]['name'] ?? $email;
$departmentHierarchy = is_array($departmentHierarchy ?? null) ? $departmentHierarchy : [];
$departmentParents = is_array($departmentParents ?? null) ? $departmentParents : [];
$departmentUserCounts = is_array($departmentUserCounts ?? null) ? $departmentUserCounts : [];
$departmentChildCounts = is_array($departmentChildCounts ?? null) ? $departmentChildCounts : [];
$departmentPolicies = is_array($departmentPolicies ?? null) ? $departmentPolicies : [];
$parentDepartments = array_values(array_filter(
    $departmentHierarchy,
    static fn (array $department): bool => (string) ($department['parent'] ?? '') === ''
));
$subDepartments = array_values(array_filter(
    $departmentHierarchy,
    static fn (array $department): bool => (string) ($department['parent'] ?? '') !== ''
));
$departmentChoices = array_values(array_filter(
    $departmentHierarchy,
    static fn (array $department): bool => (string) ($department['name'] ?? '') !== ''
));
?>

<section class="page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($t('leave_policy.eyebrow'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('leave_policy.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: #4a68a8">
        <?= htmlspecialchars($t('leave_policy.summary'), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="admin-stack leave-policy-stack">
    <div class="admin-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('leave_policy.create_sub_department'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <form class="department-create-form" method="post" action="/leave/policies/departments/create">
            <?= $csrf() ?>
            <label>
                <span><?= htmlspecialchars($t('admin.parent_department'), ENT_QUOTES, 'UTF-8') ?></span>
                <select name="parent_department" required>
                    <option value=""><?= htmlspecialchars($t('admin.not_specified'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($parentDepartments as $departmentNode): ?>
                        <?php $departmentName = (string) ($departmentNode['name'] ?? ''); ?>
                        <?php if ($departmentName === '') { continue; } ?>
                        <option value="<?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= htmlspecialchars($t('leave_policy.sub_department_name'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="text" name="department_name" maxlength="100" required>
            </label>
            <button class="button compact" type="submit"><?= htmlspecialchars($t('leave_policy.add_sub_department'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <p class="admin-settings-hint"><?= htmlspecialchars($t('leave_policy.create_hint'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="admin-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('leave_policy.assign_existing_title'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <form class="department-create-form" method="post" action="/leave/policies/departments/assign-parent">
            <?= $csrf() ?>
            <label>
                <span><?= htmlspecialchars($t('leave_policy.department_to_assign'), ENT_QUOTES, 'UTF-8') ?></span>
                <select name="department" required>
                    <option value=""><?= htmlspecialchars($t('admin.not_specified'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($departmentChoices as $departmentNode): ?>
                        <?php $departmentName = (string) ($departmentNode['name'] ?? ''); ?>
                        <?php if ($departmentName === '') { continue; } ?>
                        <option value="<?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(str_repeat('-- ', (int) ($departmentNode['level'] ?? 0)) . $departmentName, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= htmlspecialchars($t('admin.parent_department'), ENT_QUOTES, 'UTF-8') ?></span>
                <select name="parent_department" required>
                    <option value=""><?= htmlspecialchars($t('admin.not_specified'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($departmentChoices as $departmentNode): ?>
                        <?php $departmentName = (string) ($departmentNode['name'] ?? ''); ?>
                        <?php if ($departmentName === '') { continue; } ?>
                        <option value="<?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(str_repeat('-- ', (int) ($departmentNode['level'] ?? 0)) . $departmentName, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button compact" type="submit"><?= htmlspecialchars($t('leave_policy.assign_existing_submit'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <p class="admin-settings-hint"><?= htmlspecialchars($t('leave_policy.assign_existing_hint'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="admin-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('leave_policy.sub_department_policies'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>

        <?php if ($subDepartments === []): ?>
            <p class="empty-inline"><?= htmlspecialchars($t('leave_policy.empty'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <div class="permission-matrix user-directory-list department-directory-list">
                <?php foreach ($subDepartments as $departmentNode): ?>
                    <?php $department = (string) ($departmentNode['name'] ?? ''); ?>
                    <?php if ($department === '') { continue; } ?>
                    <?php $policy = $departmentPolicies[$department] ?? []; ?>
                    <?php $departmentParent = (string) ($departmentParents[$department] ?? ($departmentNode['parent'] ?? '')); ?>
                    <?php $departmentUserCount = (int) ($departmentUserCounts[$department] ?? 0); ?>
                    <?php $departmentChildCount = (int) ($departmentChildCounts[$department] ?? ($departmentNode['child_count'] ?? 0)); ?>
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
                                <strong>
                                    <?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8') ?>
                                    <em class="department-parent-chip"><?= htmlspecialchars($departmentParent, ENT_QUOTES, 'UTF-8') ?></em>
                                </strong>
                                <span class="user-directory-meta">
                                    <?= htmlspecialchars($t('admin.policy_preview'), ENT_QUOTES, 'UTF-8') ?>
                                    <?= htmlspecialchars($departmentFlow !== [] ? implode(' / ', $departmentFlow) : $t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?>
                                    / <?= htmlspecialchars($t('admin.department_user_count', ['count' => $departmentUserCount]), ENT_QUOTES, 'UTF-8') ?>
                                    / <?= htmlspecialchars($t('admin.department_child_count', ['count' => $departmentChildCount]), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </span>
                            <span class="button compact user-directory-edit"><?= htmlspecialchars($t('admin.edit'), ENT_QUOTES, 'UTF-8') ?></span>
                        </summary>
                        <form class="department-policy-card department-edit-panel" method="post" action="/leave/policies">
                            <?= $csrf() ?>
                            <input type="hidden" name="department" value="<?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="user-edit-actions">
                                <button
                                    class="button compact danger"
                                    type="submit"
                                    formaction="/leave/policies/departments/delete"
                                    formnovalidate
                                    onclick="return confirm('<?= htmlspecialchars($t('leave_policy.delete_sub_department_confirm'), ENT_QUOTES, 'UTF-8') ?>')"
                                ><?= htmlspecialchars($t('admin.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                                <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.save'), ENT_QUOTES, 'UTF-8') ?></button>
                            </div>
                            <strong class="profile-section-title"><?= htmlspecialchars($t('leave_policy.policy_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <p class="department-policy-hint"><?= htmlspecialchars($t('leave_policy.policy_hint'), ENT_QUOTES, 'UTF-8') ?></p>
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
                                    <span><?= htmlspecialchars($t('admin.department_manager'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <select name="manager_1_email">
                                        <option value=""><?= htmlspecialchars($t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php foreach ($users as $candidate): ?>
                                            <option value="<?= htmlspecialchars($candidate['email'], ENT_QUOTES, 'UTF-8') ?>" <?= ($policy['manager_1_email'] ?? '') === $candidate['email'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($candidate['name'] . ' / ' . $candidate['email'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= htmlspecialchars($t('admin.department_second_manager'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <select name="manager_2_email">
                                        <option value=""><?= htmlspecialchars($t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php foreach ($users as $candidate): ?>
                                            <option value="<?= htmlspecialchars($candidate['email'], ENT_QUOTES, 'UTF-8') ?>" <?= ($policy['manager_2_email'] ?? '') === $candidate['email'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($candidate['name'] . ' / ' . $candidate['email'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= htmlspecialchars($t('admin.department_hr_approver'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <select name="hr_email">
                                        <option value=""><?= htmlspecialchars($t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php foreach ($users as $candidate): ?>
                                            <option value="<?= htmlspecialchars($candidate['email'], ENT_QUOTES, 'UTF-8') ?>" <?= ($policy['hr_email'] ?? '') === $candidate['email'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($candidate['name'] . ' / ' . $candidate['email'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <p>
                                <?= htmlspecialchars($t('admin.policy_preview'), ENT_QUOTES, 'UTF-8') ?>
                                <strong><?= htmlspecialchars($departmentFlow !== [] ? implode(' / ', $departmentFlow) : $t('admin.no_assignee'), ENT_QUOTES, 'UTF-8') ?></strong>
                            </p>
                            <div class="user-edit-actions">
                                <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.save'), ENT_QUOTES, 'UTF-8') ?></button>
                            </div>
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
