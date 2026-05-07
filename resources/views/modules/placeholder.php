<section class="page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($module['code'], ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t($module['title_key']), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: <?= htmlspecialchars($module['accent'], ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($t($module['summary_key']), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<section class="data-panel">
    <table>
        <thead>
            <tr>
                <th><?= htmlspecialchars($t('table.reference'), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars($t('table.owner'), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars($t('table.amount'), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars($t('table.status'), ENT_QUOTES, 'UTF-8') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($records as $record): ?>
                <tr>
                    <td><?= htmlspecialchars($record['id'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($record['owner'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($record['amount'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="status-pill"><?= htmlspecialchars($t($record['state_key']), ENT_QUOTES, 'UTF-8') ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

