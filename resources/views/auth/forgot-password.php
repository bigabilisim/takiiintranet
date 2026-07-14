<section class="login-surface">
    <div class="login-panel">
        <div class="login-heading">
            <span class="eyebrow"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
            <h1><?= htmlspecialchars($t('auth.forgot_title'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars($t('auth.forgot_summary'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <?php if ($flashSuccess): ?>
            <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form class="form-stack" method="post" action="/forgot-password">
            <?= $csrf() ?>
            <label>
                <span><?= htmlspecialchars($t('auth.email'), ENT_QUOTES, 'UTF-8') ?></span>
                <input type="email" name="email" autocomplete="email" required>
            </label>
            <button class="button primary" type="submit"><?= htmlspecialchars($t('auth.reset_request'), ENT_QUOTES, 'UTF-8') ?></button>
            <a class="auth-link" href="/login"><?= htmlspecialchars($t('auth.back_to_login'), ENT_QUOTES, 'UTF-8') ?></a>
        </form>
    </div>
    <div class="login-status auth-help-panel">
        <div>
            <span>1</span>
            <p><?= htmlspecialchars($t('auth.reset_step.email'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div>
            <span>2</span>
            <p><?= htmlspecialchars($t('auth.reset_step.link'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div>
            <span>3</span>
            <p><?= htmlspecialchars($t('auth.reset_step.password'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
</section>
