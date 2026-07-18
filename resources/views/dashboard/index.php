<?php
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
        'data-request-id' => (string) ($event['id'] ?? ''),
        'data-requester' => (string) ($event['requester'] ?? ''),
        'data-department' => (string) ($event['department'] ?? ''),
        'data-type' => $t((string) ($event['type_key'] ?? 'leave.type.annual')),
        'data-date-range' => $formatDateRange($event['starts_on'] ?? '', $event['ends_on'] ?? ''),
        'data-day-part' => $t((string) ($event['day_part_key'] ?? 'leave.day_part.full')),
        'data-total-days' => $formatDays($event['total_days'] ?? 0) . ' ' . $t('leave.days'),
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
        <p class="eyebrow"><?= htmlspecialchars($t('dashboard.today'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('dashboard.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="signal-strip" aria-label="<?= htmlspecialchars($t('dashboard.signal'), ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($worldClocks as $clock): ?>
            <span><?= htmlspecialchars($t($clock['label_key']), ENT_QUOTES, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($clock['time'], ENT_QUOTES, 'UTF-8') ?></strong>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($canViewAnnouncements || ($canViewLeaveCalendar && is_array($leaveCalendar))): ?>
<section class="home-split <?= !($canViewAnnouncements && $canViewLeaveCalendar) ? 'is-single' : '' ?>">
    <?php if ($canViewAnnouncements): ?>
        <div class="home-panel news-home-panel">
            <div class="section-title">
                <h2><?= htmlspecialchars($t('dashboard.news_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <a href="/module/announcements"><?= htmlspecialchars($t('dashboard.view_all'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
            <div class="news-feed">
                <?php foreach ($newsItems as $item): ?>
                    <article class="news-item">
                        <header>
                            <span><?= htmlspecialchars($t($item['type_key']), ENT_QUOTES, 'UTF-8') ?></span>
                            <time><?= htmlspecialchars($item['date'], ENT_QUOTES, 'UTF-8') ?></time>
                        </header>
                        <h3><?= htmlspecialchars($t($item['title_key']), ENT_QUOTES, 'UTF-8') ?></h3>
                        <p><?= htmlspecialchars($t($item['summary_key']), ENT_QUOTES, 'UTF-8') ?></p>
                        <small><?= htmlspecialchars($t($item['audience_key']), ENT_QUOTES, 'UTF-8') ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canViewLeaveCalendar && is_array($leaveCalendar)): ?>
        <div class="home-panel leave-home-panel">
            <div class="section-title">
                <h2><?= htmlspecialchars($t('dashboard.leave_calendar_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <a href="/module/leave"><?= htmlspecialchars($t('dashboard.view_full_calendar'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
            <div class="compact-calendar-title">
                <strong><?= htmlspecialchars($calendarTitle($leaveCalendar), ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars($t('dashboard.leave_status_hint'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="compact-calendar-grid">
                <?php foreach ($leaveCalendar['days'] as $day): ?>
                    <div class="compact-calendar-day <?= $day['is_outside_month'] ? 'is-muted' : '' ?>">
                        <span><?= htmlspecialchars($day['day'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php foreach (array_slice($day['events'], 0, 2) as $event): ?>
                            <?php $eventDayPartKey = (string) ($event['day_part_key'] ?? 'leave.day_part.full'); ?>
                            <button class="compact-leave-event is-<?= htmlspecialchars($event['calendar_state'], ENT_QUOTES, 'UTF-8') ?> status-<?= htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8') ?>"<?= $calendarPopoverAttrs($event) ?>>
                                <?= htmlspecialchars($event['requester'], ENT_QUOTES, 'UTF-8') ?>
                                <small>
                                    <?= htmlspecialchars($t($event['status_key']) . ($eventDayPartKey !== 'leave.day_part.full' ? ' / ' . $t($eventDayPartKey) : ''), ENT_QUOTES, 'UTF-8') ?>
                                </small>
                            </button>
                        <?php endforeach; ?>
                        <?php if (count($day['events']) > 2): ?>
                            <em>+<?= htmlspecialchars((string) (count($day['events']) - 2), ENT_QUOTES, 'UTF-8') ?></em>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="weather-panel">
    <div class="section-title">
        <h2><?= htmlspecialchars($t('weather.title'), ENT_QUOTES, 'UTF-8') ?></h2>
        <span><?= htmlspecialchars($t('weather.subtitle'), ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="weather-grid">
        <?php foreach ($weeklyWeather as $forecast): ?>
            <article class="weather-card">
                <header>
                    <div>
                        <strong><?= htmlspecialchars($t($forecast['name_key']), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars($forecast['source'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </header>
                <?php if (count($forecast['days']) > 0): ?>
                    <div class="weather-days">
                        <?php foreach ($forecast['days'] as $day): ?>
                            <div class="weather-day">
                                <span><?= htmlspecialchars($formatDate($day['date'], 'day_month'), ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= htmlspecialchars((string) $day['max'], ENT_QUOTES, 'UTF-8') ?>° / <?= htmlspecialchars((string) $day['min'], ENT_QUOTES, 'UTF-8') ?>°</strong>
                                <small><?= htmlspecialchars($t($day['condition_key']), ENT_QUOTES, 'UTF-8') ?></small>
                                <em><?= htmlspecialchars($t('weather.rain', ['chance' => $day['rain']]), ENT_QUOTES, 'UTF-8') ?></em>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-inline"><?= htmlspecialchars($t('weather.unavailable'), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
