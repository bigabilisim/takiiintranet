<section class="page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($t('versions.eyebrow'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('versions.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: #5d6470">
        <?= htmlspecialchars($t('versions.summary'), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<section class="versions-layout">
    <div class="admin-panel version-mail-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('versions.mail_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <span><?= htmlspecialchars($t('versions.mail_hint'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <label>
            <span><?= htmlspecialchars($t('versions.mail_subject'), ENT_QUOTES, 'UTF-8') ?></span>
            <input type="text" value="<?= htmlspecialchars((string) ($mailDigest['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly>
        </label>
        <label>
            <span><?= htmlspecialchars($t('versions.mail_body'), ENT_QUOTES, 'UTF-8') ?></span>
            <textarea rows="18" readonly><?= htmlspecialchars((string) ($mailDigest['body'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>
    </div>

    <div class="version-list">
        <?php foreach ($releases as $release): ?>
            <article class="version-card">
                <header>
                    <div>
                        <span class="module-code">VR</span>
                        <strong><?= htmlspecialchars((string) ($release['version'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <time><?= htmlspecialchars((string) ($release['released_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></time>
                </header>
                <h2><?= htmlspecialchars((string) ($release['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                <ul>
                    <?php foreach ((array) ($release['changes'] ?? []) as $change): ?>
                        <li><?= htmlspecialchars((string) $change, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endforeach; ?>
    </div>
</section>
