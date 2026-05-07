<section class="page-header">
    <div>
        <p class="eyebrow">PR</p>
        <h1><?= htmlspecialchars($t('procurement.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: #b0643c">
        <?= htmlspecialchars($t('procurement.summary'), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="procurement-layout">
    <div class="procurement-form-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('procurement.create_title'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <?php if ($canCreate): ?>
            <form class="procurement-form" method="post" action="/procurement/requests">
                <?= $csrf() ?>
                <label>
                    <span><?= htmlspecialchars($t('procurement.item'), ENT_QUOTES, 'UTF-8') ?></span>
                    <input type="text" name="title" maxlength="140" required>
                </label>
                <label>
                    <span><?= htmlspecialchars($t('procurement.vendor'), ENT_QUOTES, 'UTF-8') ?></span>
                    <input type="text" name="vendor" maxlength="120" required>
                </label>
                <div class="form-pair">
                    <label>
                        <span><?= htmlspecialchars($t('procurement.category'), ENT_QUOTES, 'UTF-8') ?></span>
                        <select name="category" required>
                            <option value="Hardware"><?= htmlspecialchars($t('procurement.category.hardware'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="Software"><?= htmlspecialchars($t('procurement.category.software'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="Office"><?= htmlspecialchars($t('procurement.category.office'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="Service"><?= htmlspecialchars($t('procurement.category.service'), ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('procurement.amount'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="number" name="amount" min="1" step="0.01" required>
                    </label>
                </div>
                <label>
                    <span><?= htmlspecialchars($t('procurement.needed_on'), ENT_QUOTES, 'UTF-8') ?></span>
                    <input type="date" name="needed_on" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label>
                    <span><?= htmlspecialchars($t('procurement.reason'), ENT_QUOTES, 'UTF-8') ?></span>
                    <textarea name="reason" rows="5" maxlength="1200" required></textarea>
                </label>
                <button class="button primary" type="submit"><?= htmlspecialchars($t('procurement.create'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        <?php else: ?>
            <div class="empty-inline"><?= htmlspecialchars($t('procurement.no_create_permission'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div>

    <div class="procurement-list-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('procurement.requests'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="procurement-list">
            <?php foreach ($requests as $procurementRequest): ?>
                <article class="procurement-card">
                    <header>
                        <div>
                            <span class="module-code">PR</span>
                            <strong><?= htmlspecialchars($procurementRequest['id'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <span class="status-pill state-<?= htmlspecialchars($procurementRequest['status'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($t($procurementRequest['status_key']), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </header>
                    <h3><?= htmlspecialchars($procurementRequest['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <div class="procurement-meta">
                        <span><?= htmlspecialchars($procurementRequest['requester'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($procurementRequest['department'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($procurementRequest['vendor'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars($procurementRequest['amount_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <p><?= htmlspecialchars($procurementRequest['reason'], ENT_QUOTES, 'UTF-8') ?></p>
                    <small><?= htmlspecialchars($t('procurement.needed_at', ['date' => $procurementRequest['needed_on']]), ENT_QUOTES, 'UTF-8') ?></small>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
