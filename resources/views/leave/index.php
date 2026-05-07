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
$calendarPopoverAttrs = function (array $event) use ($t): string {
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
        'data-label-total-days' => $t('leave.popover.total_days'),
        'data-label-status' => $t('leave.popover.status'),
        'data-request-id' => (string) ($event['id'] ?? ''),
        'data-requester' => (string) ($event['requester'] ?? ''),
        'data-department' => (string) ($event['department'] ?? ''),
        'data-type' => $t((string) ($event['type_key'] ?? 'leave.type.annual')),
        'data-date-range' => (string) ($event['starts_on'] ?? '') . ' - ' . (string) ($event['ends_on'] ?? ''),
        'data-total-days' => (string) ($event['total_days'] ?? '') . ' ' . $t('leave.days'),
        'data-status' => $t((string) ($event['status_key'] ?? '')),
    ];

    $html = '';

    foreach ($attributes as $name => $value) {
        $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
    }

    return $html;
};
?>

<section class="page-header">
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

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="leave-layout">
    <form class="leave-form" method="post" action="/leave/requests">
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
            <div>
                <span><?= htmlspecialchars($t('leave.balance.allowance'), ENT_QUOTES, 'UTF-8') ?></span>
                <strong><?= htmlspecialchars($formatDays($leaveBalance['allowance_days']), ENT_QUOTES, 'UTF-8') ?></strong>
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
        <?php if (!empty($leaveBalance['ledger'])): ?>
            <div class="leave-ledger">
                <div class="leave-ledger-head">
                    <span><?= htmlspecialchars($t('leave.entitlement.ledger_title'), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars($t('leave.entitlement.current_days', ['days' => $formatDays($leaveBalance['current_entitlement_days'] ?? 0)]), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="leave-ledger-list">
                    <?php foreach ($leaveBalance['ledger'] as $entry): ?>
                        <div class="leave-ledger-row">
                            <span><?= htmlspecialchars($t('leave.entitlement.ledger_year', ['year' => $entry['service_year']]), ENT_QUOTES, 'UTF-8') ?></span>
                            <strong><?= htmlspecialchars($formatDays($entry['days']) . ' ' . $t('leave.days'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars((string) $entry['date'] . ' / ' . $t((string) $entry['rule_key']), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
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
                <option value="leave.type.annual"><?= htmlspecialchars($t('leave.type.annual'), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="leave.type.excuse"><?= htmlspecialchars($t('leave.type.excuse'), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="leave.type.remote"><?= htmlspecialchars($t('leave.type.remote'), ENT_QUOTES, 'UTF-8') ?></option>
            </select>
        </label>
        <div class="form-pair">
            <label>
                <span><?= htmlspecialchars($t('leave.starts_on'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="date" name="starts_on" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
            </label>
            <label>
                <span><?= htmlspecialchars($t('leave.ends_on'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="date" name="ends_on" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
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

    <section class="calendar-panel">
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
                            <button class="calendar-event is-<?= htmlspecialchars($event['calendar_state'], ENT_QUOTES, 'UTF-8') ?> status-<?= htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8') ?>"<?= $calendarPopoverAttrs($event) ?>>
                                <small><?= htmlspecialchars($event['id'], ENT_QUOTES, 'UTF-8') ?></small>
                                <span class="calendar-event-title"><?= htmlspecialchars($event['requester'], ENT_QUOTES, 'UTF-8') ?></span>
                                <em><?= htmlspecialchars($t($event['status_key']), ENT_QUOTES, 'UTF-8') ?></em>
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
                <button class="agenda-row status-<?= htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8') ?>"<?= $calendarPopoverAttrs($event) ?>>
                    <span class="module-code">LV</span>
                    <strong><?= htmlspecialchars($event['id'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars($event['requester'], ENT_QUOTES, 'UTF-8') ?></span>
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
