<?php
$signatureQueue = is_array($signatureQueue ?? null) ? $signatureQueue : [];
$formatDateRange = static function (string $startsOn, string $endsOn): string {
    return $startsOn === $endsOn || $endsOn === '' ? $startsOn : $startsOn . ' - ' . $endsOn;
};
$formatDays = static function (mixed $value): string {
    $number = is_numeric($value) ? (float) $value : 0.0;

    return rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
};
$mailStatusLabel = function (string $status) use ($t): string {
    $status = $status !== '' ? $status : 'not_sent';
    $key = 'leave.signature_queue.mail_status.' . $status;
    $label = $t($key);

    return $label === $key ? $status : $label;
};
?>

<section class="page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($t('leave.signature_queue.eyebrow'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('leave.signature_queue.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: #2f6f62">
        <?= htmlspecialchars($t('leave.signature_queue.count', ['count' => count($signatureQueue)]), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="approval-panel signature-queue-panel" aria-label="<?= htmlspecialchars($t('leave.signature_queue.title'), ENT_QUOTES, 'UTF-8') ?>">
    <div class="section-title">
        <h2><?= htmlspecialchars($t('leave.signature_queue.pending_title'), ENT_QUOTES, 'UTF-8') ?></h2>
        <span><?= htmlspecialchars($t('leave.signature_queue.summary'), ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <?php if ($signatureQueue !== []): ?>
        <div class="approval-list">
            <?php foreach ($signatureQueue as $signatureRequest): ?>
                <?php
                    $signature = is_array($signatureRequest['leave_book_signature'] ?? null) ? $signatureRequest['leave_book_signature'] : [];
                    $signatureState = (string) ($signatureRequest['signature_state'] ?? 'due');
                    $mailStatus = (string) ($signatureRequest['signature_mail_status'] ?? 'not_sent');
                    $mailMeta = array_filter([
                        $mailStatusLabel($mailStatus),
                        (string) ($signatureRequest['signature_mail_transport'] ?? ''),
                        (string) ($signatureRequest['signature_mail_sent_at'] ?? $signatureRequest['signature_mail_queued_at'] ?? ''),
                    ]);
                ?>
                <article class="approval-card signature-card is-<?= htmlspecialchars($signatureState, ENT_QUOTES, 'UTF-8') ?>">
                    <header>
                        <div>
                            <span class="module-code">LB</span>
                            <strong><?= htmlspecialchars((string) ($signatureRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="status-pill state-signature_<?= htmlspecialchars($signatureState, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($t((string) ($signatureRequest['signature_state_key'] ?? 'leave.signature_queue.state.due')), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <small><?= htmlspecialchars($t('leave.created_at', ['date' => (string) ($signatureRequest['created_at'] ?? '')]), ENT_QUOTES, 'UTF-8') ?></small>
                    </header>

                    <div class="leave-meta signature-meta">
                        <span><?= htmlspecialchars($t('leave.requester'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars((string) ($signatureRequest['requester'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span><?= htmlspecialchars($t('leave.popover.department'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars((string) ($signatureRequest['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span><?= htmlspecialchars($t('leave.popover.date_range'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($formatDateRange((string) ($signatureRequest['starts_on'] ?? ''), (string) ($signatureRequest['ends_on'] ?? '')), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span><?= htmlspecialchars($t('leave.day_part'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($t((string) ($signatureRequest['day_part_key'] ?? 'leave.day_part.full')), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span><?= htmlspecialchars($t('leave.popover.total_days'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars($formatDays($signatureRequest['total_days'] ?? 0) . ' ' . $t('leave.days'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span><?= htmlspecialchars($t('leave.signature_queue.notification_due'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars((string) ($signature['notification_due_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span><?= htmlspecialchars($t('leave.signature_queue.followup_due'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars((string) ($signature['due_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span><?= htmlspecialchars($t('leave.signature_queue.mail_status'), ENT_QUOTES, 'UTF-8') ?><strong><?= htmlspecialchars(implode(' / ', $mailMeta), ENT_QUOTES, 'UTF-8') ?></strong></span>
                    </div>

                    <?php if (!empty($signatureRequest['history'])): ?>
                        <details class="leave-history">
                            <summary><?= htmlspecialchars($t('leave.history.title'), ENT_QUOTES, 'UTF-8') ?></summary>
                            <ol>
                                <?php foreach ($signatureRequest['history'] as $historyEntry): ?>
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
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </details>
                    <?php endif; ?>

                    <form class="decision-row signature-action-row" method="post" action="/leave/book-signatures/<?= htmlspecialchars((string) ($signatureRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>/signed">
                        <?= $csrf() ?>
                        <small><?= htmlspecialchars($t('leave.signature_queue.action_hint'), ENT_QUOTES, 'UTF-8') ?></small>
                        <button class="button compact approve" type="submit" data-confirm-message="<?= htmlspecialchars($t('leave.signature_queue.mark_signed_confirm'), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($t('leave.signature_queue.mark_signed'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-inline"><?= htmlspecialchars($t('leave.signature_queue.empty'), ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</section>
