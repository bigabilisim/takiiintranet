<section class="login-surface">
    <div class="login-panel">
        <div class="login-heading">
            <span class="eyebrow"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
            <h1><?= htmlspecialchars($t('auth.reset_title'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars($t('auth.reset_summary'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <?php if ($flashSuccess): ?>
            <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($resetRecord === null): ?>
            <p class="alert"><?= htmlspecialchars($t('auth.password_reset.invalid'), ENT_QUOTES, 'UTF-8') ?></p>
            <a class="button primary auth-button-link" href="/forgot-password"><?= htmlspecialchars($t('auth.reset_request'), ENT_QUOTES, 'UTF-8') ?></a>
            <a class="auth-link" href="/login"><?= htmlspecialchars($t('auth.back_to_login'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php else: ?>
            <form class="form-stack" method="post" action="/password-reset">
                <?= $csrf() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <label>
                    <span><?= htmlspecialchars($t('auth.new_password'), ENT_QUOTES, 'UTF-8') ?></span>
                    <input type="password" name="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                </label>
                <label>
                    <span><?= htmlspecialchars($t('auth.new_password_confirmation'), ENT_QUOTES, 'UTF-8') ?></span>
                    <input type="password" name="password_confirmation" minlength="12" maxlength="4096" autocomplete="new-password" required>
                </label>
                <button class="button primary" type="submit"><?= htmlspecialchars($t('auth.reset_submit'), ENT_QUOTES, 'UTF-8') ?></button>
                <a class="auth-link" href="/login"><?= htmlspecialchars($t('auth.back_to_login'), ENT_QUOTES, 'UTF-8') ?></a>
            </form>
        <?php endif; ?>
    </div>
    <div class="login-status auth-help-panel">
        <div>
            <span>2h</span>
            <p><?= htmlspecialchars($t('auth.reset_validity'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div>
            <span>12+</span>
            <p><?= htmlspecialchars($t('auth.reset_minimum'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div>
            <span>OK</span>
            <p><?= htmlspecialchars($t('auth.reset_security'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
</section>
