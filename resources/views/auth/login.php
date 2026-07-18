<section class="login-surface login-surface-compact">
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
                <span><?= htmlspecialchars($t('auth.login_identifier'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="text" name="email" autocomplete="username" required>
            </label>
            <label>
                <span><?= htmlspecialchars($t('auth.password'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button class="button primary" type="submit"><?= htmlspecialchars($t('auth.sign_in'), ENT_QUOTES, 'UTF-8') ?></button>
            <a class="button ghost auth-button-link" href="/forgot-password"><?= htmlspecialchars($t('auth.forgot_password'), ENT_QUOTES, 'UTF-8') ?></a>
        </form>
    </div>
</section>
