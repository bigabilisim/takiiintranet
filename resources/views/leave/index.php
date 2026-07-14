<?php
$calendarFocus = new DateTimeImmutable($calendar['focus']);
$shift = match ($calendar['view']) {
    'week' => '1 week',
    'day' => '1 day',
    default => '1 month',
};
$calendarHref = fn (string $view, string $date): string => '/module/leave?view=' . urlencode($view) . '&date=' . urlencode($date);
$previousDate = $calendarFocus->modify('-' . $shift)->format('Y-m-d');
$nextDate = $calendarFocus->modify('+' . $shift)->format('Y-m-d');
$formatDays = static function (mixed $value): string {
    $number = is_numeric($value) ? (float) $value : 0.0;

    if (abs($number - round($number)) < 0.001) {
        return (string) (int) round($number);
    }

    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
};
$formatDateRange = static function (mixed $startsOn, mixed $endsOn): string {
    $startsOn = (string) $startsOn;
    $endsOn = (string) $endsOn;

    return $endsOn === '' || $startsOn === $endsOn ? $startsOn : $startsOn . ' - ' . $endsOn;
};
$canApproveInPlatform = $auth->can('admin.company.manage') || $auth->can('leave.request.approve.department') || $auth->can('leave.request.manage.hr');
$canCancelLeave = $auth->can('admin.company.manage') || $auth->can('leave.request.cancel');
$approvalStageOrder = ['manager_1', 'manager_2', 'hr', 'calendar'];
$leaveTypeOptions = ['leave.type.annual', 'leave.type.excuse', 'leave.type.remote'];
$dayPartOptions = ['full', 'morning', 'afternoon'];
$entitlementBands = is_array($entitlementPolicy['bands'] ?? null) ? $entitlementPolicy['bands'] : [];
$ageMinimumEntitlement = is_array($entitlementPolicy['age_minimum'] ?? null) ? $entitlementPolicy['age_minimum'] : [];
$calendarPopoverAttrs = function (array $event) use ($t, $formatDays, $formatDateRange): string {
    $attributes = [
        'type' => 'button',
        'data-calendar-popover-trigger' => '1',
        'data-popover-title' => $t('leave.popover.title'),
        'data-close-label' => $t('leave.popover.close'),
        'data-label-request-id' => $t('leave.popover.request_id'),
        'data-label-requester' => $t('leave.requester'),
        'data-label-department' => $t('leave.popover.department'),
        'data-label-type' => $t('leave.type'),
        'data-label-date-range' => $t('leave.popover.date_range'),
        'data-label-day-part' => $t('leave.day_part'),
        'data-label-total-days' => $t('leave.popover.total_days'),
        'data-label-status' => $t('leave.popover.status'),
        'data-label-decision' => $t('leave.quick_decision'),
        'data-label-approve' => $t('leave.approve'),
        'data-label-reject' => $t('leave.reject'),
        'data-label-reject-reason' => $t('leave.reject_reason'),
        'data-label-reject-placeholder' => $t('leave.reject_reason_placeholder'),
        'data-request-id' => (string) ($event['id'] ?? ''),
        'data-requester' => (string) ($event['requester'] ?? ''),
        'data-department' => (string) ($event['department'] ?? ''),
        'data-type' => $t((string) ($event['type_key'] ?? 'leave.type.annual')),
        'data-date-range' => $formatDateRange($event['starts_on'] ?? '', $event['ends_on'] ?? ''),
        'data-day-part' => $t((string) ($event['day_part_key'] ?? 'leave.day_part.full')),
        'data-total-days' => $formatDays($event['total_days'] ?? 0) . ' ' . $t('leave.days'),
        'data-status' => $t((string) ($event['status_key'] ?? '')),
        'data-can-act' => !empty($event['can_act']) ? '1' : '0',
        'data-decision-url' => (string) ($event['decision_url'] ?? ''),
        'data-csrf-token' => \App\Core\Csrf::token(),
    ];

    $html = '';

    foreach ($attributes as $name => $value) {
        $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
    }

    return $html;
};
?>

<section class="page-header leave-page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($t('leave.eyebrow'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('leave.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="view-tabs" aria-label="<?= htmlspecialchars($t('leave.calendar_view'), ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach (['month', 'week', 'day'] as $viewName): ?>
            <a class="<?= $calendar['view'] === $viewName ? 'is-active' : '' ?>" href="<?= htmlspecialchars($calendarHref($viewName, $calendar['focus']), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($t('leave.view.' . $viewName), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($canApproveInPlatform): ?>
    <section class="approval-panel leave-action-panel is-approval <?= !empty($approvalQueue) ? 'has-items' : 'is-clear' ?>" aria-label="<?= htmlspecialchars($t('leave.platform_approvals'), ENT_QUOTES, 'UTF-8') ?>">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('leave.platform_approvals'), ENT_QUOTES, 'UTF-8') ?></h2>
            <span><?= htmlspecialchars($t('leave.platform_approvals_count', ['count' => count($approvalQueue ?? [])]), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if (!empty($approvalQueue)): ?>
            <div class="approval-list">
                <?php foreach ($approvalQueue as $approvalRequest): ?>
                    <article class="approval-card">
                        <header>
                            <div>
                                <span class="module-code">LV</span>
                                <strong><?= htmlspecialchars($approvalRequest['id'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="status-pill state-<?= htmlspecialchars((string) ($approvalRequest['display_status'] ?? $approvalRequest['status']), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($t((string) $approvalRequest['current_stage_key']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <small><?= htmlspecialchars($t('leave.created_at', ['date' => (string) ($approvalRequest['created_at'] ?? '')]), ENT_QUOTES, 'UTF-8') ?></small>
                        </header>
                        <div class="leave-meta">
                            <span><?= htmlspecialchars($t('leave.requester'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars((string) ($approvalRequest['requester'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars($t('leave.popover.department'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars((string) ($approvalRequest['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars($t('leave.popover.date_range'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($formatDateRange($approvalRequest['starts_on'] ?? '', $approvalRequest['ends_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars($t('leave.day_part'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($t((string) ($approvalRequest['day_part_key'] ?? 'leave.day_part.full')), ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars($t('leave.popover.total_days'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($formatDays($approvalRequest['total_days'] ?? 0) . ' ' . $t('leave.days'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        </div>
                        <div class="stage-line">
                            <?php foreach ($approvalStageOrder as $stageKey): ?>
                                <?php
                                    $stage = is_array($approvalRequest['approvals'][$stageKey] ?? null) ? $approvalRequest['approvals'][$stageKey] : [];
                                    $stageStatus = (string) ($stage['status'] ?? 'pending');
                                    $stageMeta = trim((string) ($stage['actor'] ?? ($stage['assignee'] ?? '')) . ' ' . (string) ($stage['acted_at'] ?? ''));
                                ?>
                                <div class="stage-node is-<?= htmlspecialchars($stageStatus, ENT_QUOTES, 'UTF-8') ?>">
                                    <span aria-hidden="true"></span>
                                    <strong><?= htmlspecialchars($t((string) ($stage['label_key'] ?? 'leave.stage.' . $stageKey)), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <small><?= htmlspecialchars($t('leave.approval.' . $stageStatus), ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php if ($stageMeta !== ''): ?>
                                        <small><?= htmlspecialchars($stageMeta, ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($approvalRequest['history'])): ?>
                            <details class="leave-history">
                                <summary><?= htmlspecialchars($t('leave.history.title'), ENT_QUOTES, 'UTF-8') ?></summary>
                                <ol>
                                    <?php foreach ($approvalRequest['history'] as $historyEntry): ?>
                                        <?php
                                            $historyStage = (string) ($historyEntry['stage_key'] ?? '');
                                            $historySource = (string) ($historyEntry['source'] ?? '');
                                            $historyMeta = array_filter([
                                                (string) ($historyEntry['actor'] ?? ''),
                                                $historySource !== '' ? $t('leave.source.' . $historySource) : '',
                                                (string) ($historyEntry['at'] ?? ''),
                                            ]);
                                            $historyLabel = $t((string) ($historyEntry['label_key'] ?? ''));
                                            if ($historyStage !== '') {
                                                $historyLabel = $t($historyStage) . ' / ' . $historyLabel;
                                            }
                                        ?>
                                        <li>
                                            <strong><?= htmlspecialchars($historyLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if ($historyMeta !== []): ?>
                                                <small><?= htmlspecialchars(implode(' / ', $historyMeta), ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php endif; ?>
                                            <?php if ((string) ($historyEntry['note'] ?? '') !== ''): ?>
                                                <p><?= htmlspecialchars((string) ($historyEntry['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </details>
                        <?php endif; ?>
                        <div class="decision-row">
                            <form method="post" action="/leave/requests/<?= htmlspecialchars($approvalRequest['id'], ENT_QUOTES, 'UTF-8') ?>/decision">
                                <?= $csrf() ?>
                                <button class="button compact approve" type="submit" name="decision" value="approve"><?= htmlspecialchars($t('leave.approve'), ENT_QUOTES, 'UTF-8') ?></button>
                            </form>
                            <form class="decision-reject-form" method="post" action="/leave/requests/<?= htmlspecialchars($approvalRequest['id'], ENT_QUOTES, 'UTF-8') ?>/decision">
                                <?= $csrf() ?>
                                <label>
                                    <span><?= htmlspecialchars($t('leave.reject_reason'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <textarea name="decision_note" rows="2" maxlength="500" placeholder="<?= htmlspecialchars($t('leave.reject_reason_placeholder'), ENT_QUOTES, 'UTF-8') ?>" required></textarea>
                                </label>
                                <button class="button compact reject" type="submit" name="decision" value="reject"><?= htmlspecialchars($t('leave.reject'), ENT_QUOTES, 'UTF-8') ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-inline"><?= htmlspecialchars($t('leave.platform_approvals_empty'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($canCancelLeave): ?>
    <section class="approval-panel leave-action-panel is-cancellation <?= !empty($cancellationQueue) ? 'has-items' : 'is-clear' ?>" aria-label="<?= htmlspecialchars($t('leave.cancel_requests'), ENT_QUOTES, 'UTF-8') ?>">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('leave.cancel_requests'), ENT_QUOTES, 'UTF-8') ?></h2>
            <span><?= htmlspecialchars($t('leave.cancel_requests_count', ['count' => count($cancellationQueue ?? [])]), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if (!empty($cancellationQueue)): ?>
            <div class="approval-list">
                <?php foreach ($cancellationQueue as $cancelRequest): ?>
                    <article class="approval-card">
                        <header>
                            <div>
                                <span class="module-code">LV</span>
                                <strong><?= htmlspecialchars($cancelRequest['id'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="status-pill state-<?= htmlspecialchars((string) ($cancelRequest['display_status'] ?? $cancelRequest['status']), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($t((string) ($cancelRequest['status_key'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <small><?= htmlspecialchars($t('leave.created_at', ['date' => (string) ($cancelRequest['created_at'] ?? '')]), ENT_QUOTES, 'UTF-8') ?></small>
                        </header>
                        <div class="leave-meta">
                            <span><?= htmlspecialchars($t('leave.requester'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars((string) ($cancelRequest['requester'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars($t('leave.popover.department'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars((string) ($cancelRequest['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars($t('leave.popover.date_range'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($formatDateRange($cancelRequest['starts_on'] ?? '', $cancelRequest['ends_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars($t('leave.day_part'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($t((string) ($cancelRequest['day_part_key'] ?? 'leave.day_part.full')), ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars($t('leave.popover.total_days'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($formatDays($cancelRequest['total_days'] ?? 0) . ' ' . $t('leave.days'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        </div>
                        <form class="decision-row" method="post" action="/leave/requests/<?= htmlspecialchars($cancelRequest['id'], ENT_QUOTES, 'UTF-8') ?>/cancel">
                            <?= $csrf() ?>
                            <button class="button compact reject" type="submit" onclick="return confirm('<?= htmlspecialchars($t('leave.cancel_confirm'), ENT_QUOTES, 'UTF-8') ?>')">
                                <?= htmlspecialchars($t('leave.cancel'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-inline"><?= htmlspecialchars($t('leave.cancel_requests_empty'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="leave-layout">
    <div class="leave-sidebar">
    <form class="leave-form leave-module-accent is-request" method="post" action="/leave/requests" data-leave-request-form>
        <?= $csrf() ?>
        <div class="leave-person-card">
            <span class="module-code">LV</span>
            <div>
                <p><?= htmlspecialchars($t('leave.requester'), ENT_QUOTES, 'UTF-8') ?></p>
                <h2><?= htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h2>
                <small><?= htmlspecialchars(($user['department'] ?? '') . ' / ' . ($user['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
        </div>
        <div class="leave-balance-grid" aria-label="<?= htmlspecialchars($t('leave.balance_title'), ENT_QUOTES, 'UTF-8') ?>">
            <div class="leave-balance-entitlement" data-leave-entitlement-rules>
                <button
                    class="leave-balance-trigger"
                    type="button"
                    data-leave-entitlement-rules-trigger
                    aria-expanded="false"
                    aria-controls="leave-entitlement-policy"
                    aria-label="<?= htmlspecialchars($t('leave.balance.allowance') . ': ' . $formatDays($leaveBalance['allowance_days']) . '. ' . $t('leave.entitlement.policy_title'), ENT_QUOTES, 'UTF-8') ?>"
                >
                    <span><?= htmlspecialchars($t('leave.balance.allowance'), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars($formatDays($leaveBalance['allowance_days']), ENT_QUOTES, 'UTF-8') ?></strong>
                </button>
                <aside class="leave-entitlement-popover" id="leave-entitlement-policy" role="tooltip">
                    <p><?= htmlspecialchars($t('leave.entitlement.policy_title'), ENT_QUOTES, 'UTF-8') ?></p>
                    <ul>
                        <?php foreach ($entitlementBands as $band): ?>
                            <li>
                                <span><?= htmlspecialchars($t((string) ($band['label_key'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= htmlspecialchars($t('leave.entitlement.days_value', ['days' => (int) ($band['days'] ?? 0)]), ENT_QUOTES, 'UTF-8') ?></strong>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($ageMinimumEntitlement !== []): ?>
                            <li class="is-exception">
                                <span><?= htmlspecialchars($t((string) ($ageMinimumEntitlement['label_key'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= htmlspecialchars($t('leave.entitlement.minimum_days_value', ['days' => (int) ($ageMinimumEntitlement['days'] ?? 0)]), ENT_QUOTES, 'UTF-8') ?></strong>
                            </li>
                        <?php endif; ?>
                    </ul>
                </aside>
            </div>
            <div>
                <span><?= htmlspecialchars($t('leave.balance.used'), ENT_QUOTES, 'UTF-8') ?></span>
                <strong><?= htmlspecialchars($formatDays($leaveBalance['used_days']), ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div>
                <span><?= htmlspecialchars($t('leave.balance.pending'), ENT_QUOTES, 'UTF-8') ?></span>
                <strong><?= htmlspecialchars($formatDays($leaveBalance['pending_days']), ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div>
                <span><?= htmlspecialchars($t('leave.balance.remaining'), ENT_QUOTES, 'UTF-8') ?></span>
                <strong><?= htmlspecialchars($formatDays($leaveBalance['remaining_days']), ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>
        <?php if (($leaveBalance['opening_total_days'] ?? 0) > 0 || ($leaveBalance['opening_used_days'] ?? 0) > 0): ?>
            <div class="leave-opening-balance">
                <span><?= htmlspecialchars($t('leave.opening_balance.title'), ENT_QUOTES, 'UTF-8') ?></span>
                <strong>
                    <?= htmlspecialchars($t('leave.opening_balance.body', [
                        'total' => $formatDays($leaveBalance['opening_total_days'] ?? 0),
                        'used' => $formatDays($leaveBalance['opening_used_days'] ?? 0),
                        'remaining' => $formatDays($leaveBalance['opening_remaining_days'] ?? 0),
                    ]), ENT_QUOTES, 'UTF-8') ?>
                </strong>
                <?php if (($leaveBalance['opening_snapshot_date'] ?? '') !== '' || ($leaveBalance['opening_source'] ?? '') !== ''): ?>
                    <small><?= htmlspecialchars(trim(($leaveBalance['opening_source'] ?? '') . ' / ' . ($leaveBalance['opening_snapshot_date'] ?? ''), ' /'), ENT_QUOTES, 'UTF-8') ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="section-title">
            <h2><?= htmlspecialchars($t('leave.request_form'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <?php if ($upcomingEntitlement): ?>
            <div class="leave-entitlement-notice">
                <span><?= htmlspecialchars($t('leave.entitlement.upcoming_title'), ENT_QUOTES, 'UTF-8') ?></span>
                <strong>
                    <?= htmlspecialchars($t('leave.entitlement.upcoming_body', [
                        'days_until' => $upcomingEntitlement['days_until'],
                        'date' => $upcomingEntitlement['date'],
                        'earned_days' => $upcomingEntitlement['earned_days'],
                    ]), ENT_QUOTES, 'UTF-8') ?>
                </strong>
            </div>
        <?php endif; ?>
        <label>
            <span><?= htmlspecialchars($t('leave.type'), ENT_QUOTES, 'UTF-8') ?></span>
            <select name="type_key">
                <?php foreach ($leaveTypeOptions as $typeOption): ?>
                    <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t($typeOption), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= htmlspecialchars($t('leave.day_part'), ENT_QUOTES, 'UTF-8') ?></span>
            <select name="day_part" data-leave-day-part>
                <?php foreach ($dayPartOptions as $dayPartOption): ?>
                    <option value="<?= htmlspecialchars($dayPartOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t('leave.day_part.' . $dayPartOption), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-pair">
            <label>
                <span><?= htmlspecialchars($t('leave.starts_on'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="date" name="starts_on" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" data-leave-starts-on required>
            </label>
            <label>
                <span><?= htmlspecialchars($t('leave.ends_on'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="date" name="ends_on" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" data-leave-ends-on required>
            </label>
        </div>
        <div class="policy-summary">
            <span><?= htmlspecialchars($t('leave.department_policy'), ENT_QUOTES, 'UTF-8') ?></span>
            <strong>
                <?= htmlspecialchars($departmentPolicy['manager_approval_count'] === 2 ? $t('leave.manager_count.two') : $t('leave.manager_count.one'), ENT_QUOTES, 'UTF-8') ?>
            </strong>
            <small>
                <?= htmlspecialchars(($departmentPolicy['manager_1_email'] ?: '-') . ($departmentPolicy['manager_2_email'] ? ' / ' . $departmentPolicy['manager_2_email'] : '') . ' / ' . ($departmentPolicy['hr_email'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>
            </small>
        </div>
        <label class="is-hidden">
            <span><?= htmlspecialchars($t('leave.manager_count'), ENT_QUOTES, 'UTF-8') ?></span>
            <input type="hidden" name="manager_count" value="<?= htmlspecialchars((string) $departmentPolicy['manager_approval_count'], ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label>
            <span><?= htmlspecialchars($t('leave.note'), ENT_QUOTES, 'UTF-8') ?></span>
            <textarea name="note" rows="4"></textarea>
        </label>
        <button class="button primary" type="submit"><?= htmlspecialchars($t('leave.create'), ENT_QUOTES, 'UTF-8') ?></button>
    </form>

    <?php if (!empty($requesterEditableRequests) || !empty($requesterCancellableRequests)): ?>
        <section class="requester-panel leave-module-accent is-self-service" aria-label="<?= htmlspecialchars($t('leave.my_requests'), ENT_QUOTES, 'UTF-8') ?>">
            <div class="section-title">
                <h2><?= htmlspecialchars($t('leave.my_requests'), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <?php if (!empty($requesterEditableRequests)): ?>
                <div class="requester-group is-editable">
                    <div class="requester-group-title">
                        <strong><?= htmlspecialchars($t('leave.editable_requests'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars($t('leave.editable_requests_count', ['count' => count($requesterEditableRequests)]), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php foreach ($requesterEditableRequests as $editableRequest): ?>
                        <article class="requester-request">
                            <header>
                                <strong><?= htmlspecialchars((string) ($editableRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="status-pill state-<?= htmlspecialchars((string) ($editableRequest['display_status'] ?? $editableRequest['status']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t((string) ($editableRequest['status_key'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                            </header>
                            <form class="requester-request-form" method="post" action="/leave/requests/<?= htmlspecialchars((string) ($editableRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>/requester-update" data-leave-edit-form>
                                <?= $csrf() ?>
                                <label>
                                    <span><?= htmlspecialchars($t('leave.type'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <select name="type_key">
                                        <?php foreach ($leaveTypeOptions as $typeOption): ?>
                                            <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($editableRequest['type_key'] ?? '') === $typeOption ? 'selected' : '' ?>><?= htmlspecialchars($t($typeOption), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= htmlspecialchars($t('leave.day_part'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <select name="day_part" data-leave-day-part>
                                        <?php foreach ($dayPartOptions as $dayPartOption): ?>
                                            <option value="<?= htmlspecialchars($dayPartOption, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($editableRequest['day_part'] ?? 'full') === $dayPartOption ? 'selected' : '' ?>><?= htmlspecialchars($t('leave.day_part.' . $dayPartOption), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="form-pair">
                                    <label>
                                        <span><?= htmlspecialchars($t('leave.starts_on'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <input type="date" name="starts_on" value="<?= htmlspecialchars((string) ($editableRequest['starts_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-leave-starts-on required>
                                    </label>
                                    <label>
                                        <span><?= htmlspecialchars($t('leave.ends_on'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <input type="date" name="ends_on" value="<?= htmlspecialchars((string) ($editableRequest['ends_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-leave-ends-on required>
                                    </label>
                                </div>
                                <label>
                                    <span><?= htmlspecialchars($t('leave.note'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <textarea name="note" rows="3"><?= htmlspecialchars((string) ($editableRequest['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                </label>
                                <div class="requester-card-actions">
                                    <button class="button compact primary" type="submit"><?= htmlspecialchars($t('leave.update_request'), ENT_QUOTES, 'UTF-8') ?></button>
                                </div>
                            </form>
                            <form class="requester-card-actions" method="post" action="/leave/requests/<?= htmlspecialchars((string) ($editableRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>/requester-cancel">
                                <?= $csrf() ?>
                                <button class="button compact reject" type="submit" onclick="return confirm('<?= htmlspecialchars($t('leave.cancel_my_request_confirm'), ENT_QUOTES, 'UTF-8') ?>')"><?= htmlspecialchars($t('leave.cancel_my_request'), ENT_QUOTES, 'UTF-8') ?></button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($requesterCancellableRequests)): ?>
                <div class="requester-group is-cancellable">
                    <div class="requester-group-title">
                        <strong><?= htmlspecialchars($t('leave.cancellable_requests'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars($t('leave.cancellable_requests_count', ['count' => count($requesterCancellableRequests)]), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php foreach ($requesterCancellableRequests as $cancellableRequest): ?>
                        <article class="requester-request">
                            <header>
                                <strong><?= htmlspecialchars((string) ($cancellableRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="status-pill state-<?= htmlspecialchars((string) ($cancellableRequest['display_status'] ?? $cancellableRequest['status']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t((string) ($cancellableRequest['status_key'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                            </header>
                            <div class="leave-meta requester-request-meta">
                                <span><?= htmlspecialchars($t('leave.popover.date_range'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($formatDateRange($cancellableRequest['starts_on'] ?? '', $cancellableRequest['ends_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                                <span><?= htmlspecialchars($t('leave.day_part'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($t((string) ($cancellableRequest['day_part_key'] ?? 'leave.day_part.full')), ENT_QUOTES, 'UTF-8') ?></strong></span>
                                <span><?= htmlspecialchars($t('leave.popover.total_days'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($formatDays($cancellableRequest['total_days'] ?? 0) . ' ' . $t('leave.days'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                            </div>
                            <form class="requester-card-actions" method="post" action="/leave/requests/<?= htmlspecialchars((string) ($cancellableRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>/request-cancellation">
                                <?= $csrf() ?>
                                <button class="button compact reject" type="submit" onclick="return confirm('<?= htmlspecialchars($t('leave.request_cancellation_confirm'), ENT_QUOTES, 'UTF-8') ?>')"><?= htmlspecialchars($t('leave.request_cancellation'), ENT_QUOTES, 'UTF-8') ?></button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if (!empty($requesterHistoryRequests)): ?>
        <section class="requester-panel" aria-label="<?= htmlspecialchars($t('leave.history.my_title'), ENT_QUOTES, 'UTF-8') ?>">
            <div class="section-title">
                <h2><?= htmlspecialchars($t('leave.history.my_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <span><?= htmlspecialchars($t('leave.history.my_count', ['count' => count($requesterHistoryRequests)]), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="requester-history-list">
                <?php foreach (array_slice($requesterHistoryRequests, 0, 8) as $historyRequest): ?>
                    <article class="requester-history-card">
                        <header>
                            <strong><?= htmlspecialchars((string) ($historyRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="status-pill state-<?= htmlspecialchars((string) ($historyRequest['display_status'] ?? $historyRequest['status']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t((string) ($historyRequest['status_key'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                        </header>
                        <small><?= htmlspecialchars($formatDateRange($historyRequest['starts_on'] ?? '', $historyRequest['ends_on'] ?? '') . ' / ' . $formatDays($historyRequest['total_days'] ?? 0) . ' ' . $t('leave.days'), ENT_QUOTES, 'UTF-8') ?></small>
                        <?php if (!empty($historyRequest['history'])): ?>
                            <ol>
                                <?php foreach (array_slice((array) $historyRequest['history'], -4) as $historyEntry): ?>
                                    <?php
                                        $historyStage = (string) ($historyEntry['stage_key'] ?? '');
                                        $historySource = (string) ($historyEntry['source'] ?? '');
                                        $historyMeta = array_filter([
                                            (string) ($historyEntry['actor'] ?? ''),
                                            $historySource !== '' ? $t('leave.source.' . $historySource) : '',
                                            (string) ($historyEntry['at'] ?? ''),
                                        ]);
                                        $historyLabel = $t((string) ($historyEntry['label_key'] ?? ''));
                                        if ($historyStage !== '') {
                                            $historyLabel = $t($historyStage) . ' / ' . $historyLabel;
                                        }
                                    ?>
                                    <li>
                                        <strong><?= htmlspecialchars($historyLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if ($historyMeta !== []): ?>
                                            <span><?= htmlspecialchars(implode(' / ', $historyMeta), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <?php if ((string) ($historyEntry['note'] ?? '') !== ''): ?>
                                            <em><?= htmlspecialchars((string) ($historyEntry['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></em>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    </div>

    <section class="calendar-panel leave-module-accent is-calendar">
    <header class="calendar-header">
        <div>
            <p class="eyebrow"><?= htmlspecialchars($t('leave.calendar'), ENT_QUOTES, 'UTF-8') ?></p>
            <h2><?= htmlspecialchars($calendar['title'], ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="calendar-controls">
            <a class="button ghost" href="<?= htmlspecialchars($calendarHref($calendar['view'], $previousDate), ENT_QUOTES, 'UTF-8') ?>">&lsaquo;</a>
            <a class="button ghost" href="<?= htmlspecialchars($calendarHref($calendar['view'], date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t('leave.today'), ENT_QUOTES, 'UTF-8') ?></a>
            <a class="button ghost" href="<?= htmlspecialchars($calendarHref($calendar['view'], $nextDate), ENT_QUOTES, 'UTF-8') ?>">&rsaquo;</a>
        </div>
    </header>

    <?php if ($calendar['view'] !== 'day'): ?>
        <div class="calendar-grid is-<?= htmlspecialchars($calendar['view'], ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ($calendar['days'] as $day): ?>
                <div class="calendar-day <?= $day['is_outside_month'] ? 'is-muted' : '' ?>">
                    <header>
                        <span><?= htmlspecialchars($day['weekday'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars($day['day'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </header>
                    <div class="calendar-events">
                        <?php foreach ($day['events'] as $event): ?>
                            <?php $eventDayPartKey = (string) ($event['day_part_key'] ?? 'leave.day_part.full'); ?>
                            <button class="calendar-event is-<?= htmlspecialchars($event['calendar_state'], ENT_QUOTES, 'UTF-8') ?> status-<?= htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8') ?>"<?= $calendarPopoverAttrs($event) ?>>
                                <small><?= htmlspecialchars($event['id'], ENT_QUOTES, 'UTF-8') ?></small>
                                <span class="calendar-event-title"><?= htmlspecialchars($event['requester'], ENT_QUOTES, 'UTF-8') ?></span>
                                <em>
                                    <?= htmlspecialchars($t($event['status_key']) . ($eventDayPartKey !== 'leave.day_part.full' ? ' / ' . $t($eventDayPartKey) : ''), ENT_QUOTES, 'UTF-8') ?>
                                </em>
                                <?php if ($calendar['view'] === 'month' && !empty($event['entitlement_hint'])): ?>
                                    <b>
                                        <?= htmlspecialchars($t('leave.entitlement.calendar_inline', [
                                            'earned_days' => $event['entitlement_hint']['earned_days'],
                                            'date' => $event['entitlement_hint']['date'],
                                        ]), ENT_QUOTES, 'UTF-8') ?>
                                    </b>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="day-agenda">
            <?php foreach ($calendar['days'][0]['events'] as $event): ?>
                <?php $eventDayPartKey = (string) ($event['day_part_key'] ?? 'leave.day_part.full'); ?>
                <button class="agenda-row status-<?= htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8') ?>"<?= $calendarPopoverAttrs($event) ?>>
                    <span class="module-code">LV</span>
                    <strong><?= htmlspecialchars($event['id'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars($event['requester'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($eventDayPartKey !== 'leave.day_part.full'): ?>
                        <span><?= htmlspecialchars($t($eventDayPartKey), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <span class="status-pill state-<?= htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t($event['status_key']), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endforeach; ?>
            <?php if (count($calendar['days'][0]['events']) === 0): ?>
                <div class="empty-inline"><?= htmlspecialchars($t('leave.no_events'), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </section>
</section>
