<section class="empty-state">
    <span class="module-code">LV</span>
    <h1><?= htmlspecialchars($t($result['message']), ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($result['request']): ?>
        <p class="mail-result-meta">
            <?= htmlspecialchars($result['request']['id'], ENT_QUOTES, 'UTF-8') ?>
            &middot; <?= htmlspecialchars($result['request']['requester'], ENT_QUOTES, 'UTF-8') ?>
            &middot; <?= htmlspecialchars($t('leave.status.' . $result['request']['status']), ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>
    <a class="button ghost" href="/module/leave"><?= htmlspecialchars($t('leave.back_to_leave'), ENT_QUOTES, 'UTF-8') ?></a>
</section>
