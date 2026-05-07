<section class="login-surface">
    <div class="login-panel">
        <div class="login-heading">
            <span class="eyebrow"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
            <h1><?= htmlspecialchars($t('auth.login'), ENT_QUOTES, 'UTF-8') ?></h1>
        </div>

        <?php if ($flashError): ?>
            <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form class="form-stack" method="post" action="/login">
            <?= $csrf() ?>
            <label>
                <span><?= htmlspecialchars($t('auth.email'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="email" name="email" value="admin@example.com" autocomplete="email" required>
            </label>
            <label>
                <span><?= htmlspecialchars($t('auth.password'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button class="button primary" type="submit"><?= htmlspecialchars($t('auth.sign_in'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
    </div>
    <div class="login-status">
        <div>
            <span>99.98%</span>
            <p><?= htmlspecialchars($t('auth.metric.uptime'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div>
            <span><?= htmlspecialchars((string) count($availableLocales), ENT_QUOTES, 'UTF-8') ?></span>
            <p><?= htmlspecialchars($t('auth.metric.locales'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div>
            <span>8</span>
            <p><?= htmlspecialchars($t('auth.metric.flows'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
</section>
