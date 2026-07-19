<?php
$templates = is_array($templates ?? null) ? $templates : [];
$enabledTemplates = is_array($enabledTemplates ?? null) ? $enabledTemplates : [];
$personnel = is_array($personnel ?? null) ? $personnel : [];
$weekendDutyPersonnel = is_array($weekendDutyPersonnel ?? null) ? $weekendDutyPersonnel : [];
$weekendPlans = is_array($weekendPlans ?? null) ? $weekendPlans : [];
$holidays = is_array($holidays ?? null) ? $holidays : [];
$dayLabels = is_array($dayLabels ?? null) ? $dayLabels : [];
$canManageShift = !empty($canManageShift);
$dayOrder = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
$nextMonth = (new DateTimeImmutable('first day of next month'))->format('Y-m');
$calendarDates = [];
$calendarDay = new DateTimeImmutable($nextMonth . '-01');

while ($calendarDay->format('Y-m') === $nextMonth) {
    $calendarDates[] = $calendarDay;
    $calendarDay = $calendarDay->modify('+1 day');
}
$holidayGroups = [];

foreach ($holidays as $holiday) {
    $holidayDate = (string) ($holiday['date'] ?? '');
    $holidayYear = substr($holidayDate, 0, 4);

    if ($holidayYear !== '') {
        $holidayGroups[$holidayYear][] = $holiday;
    }
}
$holidayName = static function (array $holiday) use ($t): string {
    $labels = [];

    foreach (is_array($holiday['name_keys'] ?? null) ? $holiday['name_keys'] : [] as $nameKey) {
        $labels[] = $t((string) $nameKey);
    }

    if ($labels === [] && (string) ($holiday['name'] ?? '') !== '') {
        $labels[] = (string) $holiday['name'];
    }

    return implode(' / ', $labels);
};
$dateText = static function (string $date): string {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed instanceof DateTimeImmutable ? $parsed->format('d.m.Y') : $date;
};
$formatHours = static function (mixed $value): string {
    $number = is_numeric($value) ? (float) $value : 0.0;

    if (abs($number - round($number)) < 0.001) {
        return (string) (int) round($number);
    }

    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
};
$dayText = static function (array $days) use ($dayLabels, $t): string {
    $labels = [];

    foreach ($days as $day) {
        $labelKey = (string) ($dayLabels[$day] ?? '');
        $labels[] = $labelKey !== '' ? $t($labelKey) : (string) $day;
    }

    return implode(', ', $labels);
};
$patternFor = static function (array $days): string {
    $days = array_values($days);

    if ($days === ['mon', 'tue', 'wed', 'thu', 'fri']) {
        return 'weekdays';
    }

    if ($days === ['sat', 'sun']) {
        return 'weekend';
    }

    if ($days === ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']) {
        return 'all';
    }

    return 'custom';
};
?>

<section class="page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($t('shift.eyebrow'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('shift.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: #6f5b2f">
        <?= htmlspecialchars($t('shift.summary', ['count' => count($templates)]), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="shift-layout">
    <div class="shift-main">
        <details class="shift-panel shift-collapsible" open data-mobile-collapsible data-mobile-default-open>
            <summary class="shift-panel-header">
                <div>
                    <strong><?= htmlspecialchars($t('shift.create_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars($t('shift.create_hint'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </summary>

            <?php if ($canManageShift): ?>
                <form class="shift-form" method="post" action="/shift/templates" data-shift-template-form>
                    <?= $csrf() ?>

                    <div class="shift-form-grid">
                        <label>
                            <span><?= htmlspecialchars($t('shift.field.name'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="name" maxlength="120" placeholder="<?= htmlspecialchars($t('shift.placeholder.name'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('shift.field.day_pattern'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="day_pattern" data-shift-day-pattern>
                                <option value="weekdays" selected><?= htmlspecialchars($t('shift.pattern.weekdays'), ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="weekend"><?= htmlspecialchars($t('shift.pattern.weekend'), ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="all"><?= htmlspecialchars($t('shift.pattern.all'), ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="custom"><?= htmlspecialchars($t('shift.pattern.custom'), ENT_QUOTES, 'UTF-8') ?></option>
                            </select>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('shift.field.starts_at'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="time" name="starts_at" value="08:00" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('shift.field.ends_at'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="time" name="ends_at" value="17:00" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('shift.field.break_minutes'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="number" name="break_minutes" min="0" max="240" step="5" value="60">
                        </label>
                        <label class="shift-toggle">
                            <input type="checkbox" name="is_enabled" value="1" checked>
                            <span><?= htmlspecialchars($t('shift.field.enabled'), ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    </div>

                    <div class="shift-day-grid">
                        <?php foreach ($dayOrder as $day): ?>
                            <label>
                                <input type="checkbox" name="days[]" value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>" data-shift-day-checkbox <?= in_array($day, ['mon', 'tue', 'wed', 'thu', 'fri'], true) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($t((string) ($dayLabels[$day] ?? $day)), ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="shift-actions">
                        <button class="button compact" type="submit"><?= htmlspecialchars($t('shift.save_template'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </form>
            <?php else: ?>
                <p class="personnel-readonly"><?= htmlspecialchars($t('shift.readonly_notice'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </details>

        <details class="shift-panel shift-collapsible" open data-mobile-collapsible>
            <summary class="shift-panel-header">
                <div>
                    <strong><?= htmlspecialchars($t('shift.templates_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars($t('shift.templates_hint'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </summary>

            <div class="shift-template-grid">
                <?php foreach ($templates as $template): ?>
                    <?php
                        $templateKey = (string) ($template['key'] ?? '');
                        $templateDays = is_array($template['days'] ?? null) ? $template['days'] : [];
                        $templatePattern = $patternFor($templateDays);
                    ?>
                    <article class="shift-template-card <?= !empty($template['is_enabled']) ? 'is-enabled' : 'is-disabled' ?>">
                        <header>
                            <div>
                                <strong><?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars($dayText($templateDays), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <em><?= htmlspecialchars($t(!empty($template['is_enabled']) ? 'shift.status.enabled' : 'shift.status.disabled'), ENT_QUOTES, 'UTF-8') ?></em>
                        </header>
                        <dl class="shift-meta">
                            <div>
                                <dt><?= htmlspecialchars($t('shift.field.hours'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd><?= htmlspecialchars((string) ($template['starts_at'] ?? '') . ' - ' . (string) ($template['ends_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt><?= htmlspecialchars($t('shift.field.weekly_hours'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd><?= htmlspecialchars($formatHours($template['weekly_hours'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt><?= htmlspecialchars($t('shift.field.assigned_count'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd><?= htmlspecialchars((string) ($template['assigned_count'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        </dl>

                        <?php if ($canManageShift): ?>
                            <details class="shift-template-edit">
                                <summary><?= htmlspecialchars($t('admin.edit'), ENT_QUOTES, 'UTF-8') ?></summary>
                                <form class="shift-form" method="post" action="/shift/templates" data-shift-template-form>
                                    <?= $csrf() ?>
                                    <input type="hidden" name="shift_key" value="<?= htmlspecialchars($templateKey, ENT_QUOTES, 'UTF-8') ?>">

                                    <div class="shift-form-grid">
                                        <label>
                                            <span><?= htmlspecialchars($t('shift.field.name'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <input type="text" name="name" maxlength="120" value="<?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                        </label>
                                        <label>
                                            <span><?= htmlspecialchars($t('shift.field.day_pattern'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <select name="day_pattern" data-shift-day-pattern>
                                                <?php foreach (['weekdays', 'weekend', 'all', 'custom'] as $pattern): ?>
                                                    <option value="<?= htmlspecialchars($pattern, ENT_QUOTES, 'UTF-8') ?>" <?= $templatePattern === $pattern ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($t('shift.pattern.' . $pattern), ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span><?= htmlspecialchars($t('shift.field.starts_at'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <input type="time" name="starts_at" value="<?= htmlspecialchars((string) ($template['starts_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                        </label>
                                        <label>
                                            <span><?= htmlspecialchars($t('shift.field.ends_at'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <input type="time" name="ends_at" value="<?= htmlspecialchars((string) ($template['ends_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                        </label>
                                        <label>
                                            <span><?= htmlspecialchars($t('shift.field.break_minutes'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <input type="number" name="break_minutes" min="0" max="240" step="5" value="<?= htmlspecialchars((string) ($template['break_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                        </label>
                                        <label class="shift-toggle">
                                            <input type="checkbox" name="is_enabled" value="1" <?= !empty($template['is_enabled']) ? 'checked' : '' ?>>
                                            <span><?= htmlspecialchars($t('shift.field.enabled'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </label>
                                    </div>

                                    <div class="shift-day-grid">
                                        <?php foreach ($dayOrder as $day): ?>
                                            <label>
                                                <input type="checkbox" name="days[]" value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>" data-shift-day-checkbox <?= in_array($day, $templateDays, true) ? 'checked' : '' ?>>
                                                <span><?= htmlspecialchars($t((string) ($dayLabels[$day] ?? $day)), ENT_QUOTES, 'UTF-8') ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="shift-actions">
                                        <button class="button compact" type="submit"><?= htmlspecialchars($t('admin.save'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </div>
                                </form>

                                <form method="post" action="/shift/templates/delete" class="shift-delete-form">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="shift_key" value="<?= htmlspecialchars($templateKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="button compact danger" type="submit" <?= ((int) ($template['assigned_count'] ?? 0)) > 0 ? 'disabled' : '' ?>>
                                        <?= htmlspecialchars($t('admin.delete'), ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </details>

        <details class="shift-panel shift-collapsible" open data-mobile-collapsible>
            <summary class="shift-panel-header">
                <div>
                    <strong><?= htmlspecialchars($t('shift.weekend_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars($t('shift.weekend_hint'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </summary>

            <?php if ($canManageShift): ?>
                <form class="shift-form" method="post" action="/shift/weekend-plans">
                    <?= $csrf() ?>

                    <div class="shift-weekend-plan-grid">
                        <label>
                            <span><?= htmlspecialchars($t('shift.field.month'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="month" name="month" value="<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('shift.field.personnel'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="profile_key" required>
                                <option value=""><?= htmlspecialchars($t('admin.not_specified'), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php foreach ($weekendDutyPersonnel as $profile): ?>
                                    <?php $profileKey = (string) ($profile['profile_key'] ?? ($profile['email'] ?? '')); ?>
                                    <option value="<?= htmlspecialchars($profileKey, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string) ($profile['name'] ?? $profileKey), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('shift.assign_select'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="shift_key" required>
                                <option value=""><?= htmlspecialchars($t('admin.not_specified'), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?= htmlspecialchars((string) ($template['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="shift-date-actions" role="group" aria-label="<?= htmlspecialchars($t('shift.field.working_dates'), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="button" class="button compact ghost" data-shift-date-pattern="weekdays"><?= htmlspecialchars($t('shift.pattern.weekdays'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button type="button" class="button compact ghost" data-shift-date-pattern="all"><?= htmlspecialchars($t('shift.pattern.all'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button type="button" class="button compact ghost" data-shift-date-pattern="clear"><?= htmlspecialchars($t('shift.pattern.clear'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>

                    <div
                        class="shift-date-grid"
                        data-shift-date-calendar
                        data-locale="<?= htmlspecialchars(str_replace('_', '-', (string) ($locale ?? 'tr-TR')), ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <?php foreach ($calendarDates as $index => $date): ?>
                            <?php
                                $dayKey = $dayOrder[((int) $date->format('N')) - 1] ?? 'mon';
                                $isWeekday = (int) $date->format('N') < 6;
                            ?>
                            <label <?= $index === 0 ? 'style="grid-column-start: ' . (int) $date->format('N') . '"' : '' ?>>
                                <input type="checkbox" name="working_dates[]" value="<?= htmlspecialchars($date->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" <?= $isWeekday ? 'checked' : '' ?>>
                                <span>
                                    <strong><?= htmlspecialchars($date->format('d'), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <small><?= htmlspecialchars($t((string) ($dayLabels[$dayKey] ?? $dayKey)), ENT_QUOTES, 'UTF-8') ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <label class="shift-note-field">
                        <span><?= htmlspecialchars($t('shift.field.note'), ENT_QUOTES, 'UTF-8') ?></span>
                        <textarea name="note" rows="2" maxlength="240" placeholder="<?= htmlspecialchars($t('shift.placeholder.weekend_note'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
                    </label>

                    <div class="shift-actions">
                        <button class="button compact" type="submit"><?= htmlspecialchars($t('shift.weekend_save'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </form>
                <?php if ($weekendDutyPersonnel === []): ?>
                    <p class="personnel-readonly"><?= htmlspecialchars($t('shift.weekend_scope_empty'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p class="personnel-readonly"><?= htmlspecialchars($t('shift.readonly_notice'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <div class="shift-weekend-list">
                <?php if ($weekendPlans === []): ?>
                    <p class="empty-state"><?= htmlspecialchars($t('shift.weekend_empty'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <?php foreach ($weekendPlans as $plan): ?>
                    <?php
                        $planKey = (string) ($plan['key'] ?? '');
                        $planDates = is_array($plan['working_dates'] ?? null) ? $plan['working_dates'] : [];
                    ?>
                    <article class="shift-weekend-card">
                        <div>
                            <strong><?= htmlspecialchars((string) ($plan['profile_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars(trim((string) ($plan['month'] ?? '') . ' / ' . (string) ($plan['department'] ?? ''), ' /'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <p>
                            <b><?= htmlspecialchars((string) ($plan['shift_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></b>
                            <small><?= htmlspecialchars(implode(', ', array_map($dateText, $planDates)), ENT_QUOTES, 'UTF-8') ?></small>
                        </p>
                        <?php if ((string) ($plan['note'] ?? '') !== ''): ?>
                            <em><?= htmlspecialchars((string) ($plan['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></em>
                        <?php endif; ?>
                        <?php if ($canManageShift): ?>
                            <form method="post" action="/shift/weekend-plans/delete">
                                <?= $csrf() ?>
                                <input type="hidden" name="plan_key" value="<?= htmlspecialchars($planKey, ENT_QUOTES, 'UTF-8') ?>">
                                <button class="button compact danger" type="submit"><?= htmlspecialchars($t('admin.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </details>

        <details class="shift-panel shift-collapsible" open data-mobile-collapsible>
            <summary class="shift-panel-header">
                <div>
                    <strong><?= htmlspecialchars($t('shift.holiday.title'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars($t('shift.holiday.summary', ['count' => count($holidays)]), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </summary>

            <?php if ($canManageShift): ?>
                <form class="shift-form" method="post" action="/shift/holidays">
                    <?= $csrf() ?>
                    <div class="shift-holiday-form-grid">
                        <label>
                            <span><?= htmlspecialchars($t('shift.holiday.field.date'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="date" name="date" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('shift.holiday.field.name'), ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" name="name" maxlength="120" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars($t('shift.holiday.field.duration'), ENT_QUOTES, 'UTF-8') ?></span>
                            <select name="day_part" required>
                                <option value="full"><?= htmlspecialchars($t('shift.holiday.duration.full'), ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="afternoon"><?= htmlspecialchars($t('shift.holiday.duration.afternoon'), ENT_QUOTES, 'UTF-8') ?></option>
                            </select>
                        </label>
                    </div>
                    <div class="shift-actions">
                        <button class="button compact" type="submit"><?= htmlspecialchars($t('shift.holiday.save'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="shift-holiday-groups">
                <?php foreach ($holidayGroups as $year => $yearHolidays): ?>
                    <details class="shift-holiday-year" <?= $year === date('Y') ? 'open' : '' ?>>
                        <summary>
                            <strong><?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($t('shift.holiday.year_count', ['count' => count($yearHolidays)]), ENT_QUOTES, 'UTF-8') ?></span>
                        </summary>
                        <div class="shift-holiday-list">
                            <?php foreach ($yearHolidays as $holiday): ?>
                                <?php $isOfficial = (string) ($holiday['source'] ?? '') === 'official'; ?>
                                <article class="shift-holiday-row">
                                    <time datetime="<?= htmlspecialchars((string) ($holiday['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($dateText((string) ($holiday['date'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                    </time>
                                    <span>
                                        <strong><?= htmlspecialchars($holidayName($holiday), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <small><?= htmlspecialchars($t('shift.holiday.duration.' . (string) ($holiday['day_part'] ?? 'full')), ENT_QUOTES, 'UTF-8') ?></small>
                                    </span>
                                    <em><?= htmlspecialchars($t($isOfficial ? 'shift.holiday.source.official' : 'shift.holiday.source.manual'), ENT_QUOTES, 'UTF-8') ?></em>
                                    <?php if ($canManageShift && !$isOfficial): ?>
                                        <form method="post" action="/shift/holidays/delete">
                                            <?= $csrf() ?>
                                            <input type="hidden" name="date" value="<?= htmlspecialchars((string) ($holiday['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="button compact danger" type="submit"><?= htmlspecialchars($t('admin.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </details>
    </div>

    <details class="shift-panel shift-assign-panel shift-collapsible" open data-mobile-collapsible>
        <summary class="shift-panel-header">
            <div>
                <strong><?= htmlspecialchars($t('shift.assign_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars($t('shift.assign_hint'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </summary>

        <form method="post" action="/shift/assign" class="shift-assign-form">
            <?= $csrf() ?>
            <label>
                <span><?= htmlspecialchars($t('shift.assign_select'), ENT_QUOTES, 'UTF-8') ?></span>
                <select name="shift_key" <?= !$canManageShift ? 'disabled' : '' ?>>
                    <option value=""><?= htmlspecialchars($t('shift.unassign'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($enabledTemplates as $template): ?>
                        <option value="<?= htmlspecialchars((string) ($template['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="shift-select-all">
                <input type="checkbox" name="all_personnel" value="1" data-shift-select-all <?= !$canManageShift ? 'disabled' : '' ?>>
                <span><?= htmlspecialchars($t('shift.assign_all'), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <div class="shift-personnel-list">
                <?php foreach ($personnel as $profile): ?>
                    <?php
                        $profileKey = (string) ($profile['profile_key'] ?? ($profile['email'] ?? ''));
                        $shiftLabel = (string) ($profile['shift_label'] ?? '');
                    ?>
                    <label class="shift-person-row">
                        <input type="checkbox" name="profile_keys[]" value="<?= htmlspecialchars($profileKey, ENT_QUOTES, 'UTF-8') ?>" data-shift-person-checkbox <?= !$canManageShift ? 'disabled' : '' ?>>
                        <span>
                            <strong><?= htmlspecialchars((string) ($profile['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars(trim((string) ($profile['department'] ?? '') . ' / ' . (string) ($profile['role'] ?? ''), ' /'), ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                        <em><?= htmlspecialchars($shiftLabel !== '' ? $shiftLabel : $t('shift.unassigned'), ENT_QUOTES, 'UTF-8') ?></em>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="shift-actions">
                <button class="button compact" type="submit" <?= !$canManageShift ? 'disabled' : '' ?>>
                    <?= htmlspecialchars($t('shift.assign_submit'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </details>
</section>
