<?php
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$currentPath = is_string($requestPath) && $requestPath !== '' ? rtrim($requestPath, '/') : '/';
$currentPath = $currentPath !== '' ? $currentPath : '/';
$isCurrentPath = static function (string ...$paths) use ($currentPath): bool {
    foreach ($paths as $path) {
        $normalizedPath = $path === '/' ? '/' : rtrim($path, '/');

        if ($normalizedPath === '/') {
            if ($currentPath === '/') {
                return true;
            }

            continue;
        }

        if ($currentPath === $normalizedPath || str_starts_with($currentPath, $normalizedPath . '/')) {
            return true;
        }
    }

    return false;
};
$accessibleModules = [];

if ($user) {
    foreach ($modules as $module) {
        if (empty($module['hidden_in_menu']) && $auth->can($module['permission'])) {
            $accessibleModules[(string) $module['slug']] = $module;
        }
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#050509">
    <meta name="csrf-token" content="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <?php $pageTitle = isset($title) ? $t((string) $title) : $appName; ?>
    <title><?= htmlspecialchars($pageTitle . ' | ' . $appName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="<?= htmlspecialchars($asset('takii-icon-64.png'), ENT_QUOTES, 'UTF-8') ?>" type="image/png">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($asset('takii-apple-touch-icon.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($asset('app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php if (!empty($grapesjsAssets)): ?>
        <link rel="stylesheet" href="/vendor/grapesjs/0.23.2/grapes.min.css">
    <?php endif; ?>
</head>
<body>
    <div class="app-shell <?= $user ? 'is-authenticated' : 'is-guest' ?>">
        <aside
            class="sidebar"
            id="primary-navigation"
            aria-label="<?= htmlspecialchars($t('nav.primary'), ENT_QUOTES, 'UTF-8') ?>"
            data-mobile-drawer
        >
            <div class="sidebar-heading">
                <a class="brand" href="/" aria-label="MyTakii Intranet">
                    <img
                        class="brand-logo"
                        src="<?= htmlspecialchars($asset('takii-logo-borderless.png'), ENT_QUOTES, 'UTF-8') ?>"
                        width="84"
                        height="84"
                        alt=""
                    >
                    <span class="brand-copy">
                        <strong class="brand-name">MyTakii</strong>
                        <span class="brand-product">Intranet</span>
                        <span class="brand-stripes" aria-hidden="true">
                            <i></i>
                            <i></i>
                            <i></i>
                        </span>
                    </span>
                </a>
                <?php if ($user): ?>
                    <button
                        class="mobile-icon-button mobile-drawer-close"
                        type="button"
                        data-mobile-menu-close
                        aria-label="<?= htmlspecialchars($t('nav.close_menu'), ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars($t('nav.close_menu'), ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <span aria-hidden="true">X</span>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($user): ?>
                <div class="mobile-sidebar-user">
                    <small><?= htmlspecialchars($user['department'], ENT_QUOTES, 'UTF-8') ?></small>
                    <strong><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <nav class="nav-list">
                    <a class="nav-item<?= $isCurrentPath('/') ? ' is-active' : '' ?>" href="/"<?= $isCurrentPath('/') ? ' aria-current="page"' : '' ?>>
                        <span class="nav-code">DB</span>
                        <?= htmlspecialchars($t('nav.dashboard'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?php if ($auth->can('admin.company.manage')): ?>
                        <a class="nav-item<?= $isCurrentPath('/admin/access') ? ' is-active' : '' ?>" href="/admin/access"<?= $isCurrentPath('/admin/access') ? ' aria-current="page"' : '' ?>>
                            <span class="nav-code">AC</span>
                            <?= htmlspecialchars($t('nav.access'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <a class="nav-item<?= $isCurrentPath('/admin/versions') ? ' is-active' : '' ?>" href="/admin/versions"<?= $isCurrentPath('/admin/versions') ? ' aria-current="page"' : '' ?>>
                            <span class="nav-code">VR</span>
                            <?= htmlspecialchars($t('nav.versions'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($auth->can('leave.request.manage.hr')): ?>
                        <a class="nav-item<?= $isCurrentPath('/leave/book-signatures') ? ' is-active' : '' ?>" href="/leave/book-signatures"<?= $isCurrentPath('/leave/book-signatures') ? ' aria-current="page"' : '' ?>>
                            <span class="nav-code">LB</span>
                            <?= htmlspecialchars($t('nav.leave_book_signatures'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($auth->can('leave.policy.manage')): ?>
                        <a class="nav-item<?= $isCurrentPath('/leave/policies') ? ' is-active' : '' ?>" href="/leave/policies"<?= $isCurrentPath('/leave/policies') ? ' aria-current="page"' : '' ?>>
                            <span class="nav-code">LA</span>
                            <?= htmlspecialchars($t('nav.leave_policies'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endif; ?>
                    <?php foreach ($accessibleModules as $moduleSlug => $module): ?>
                        <?php
                            $moduleBadgeCount = (int) ($moduleBadges[$moduleSlug] ?? 0);
                            $moduleBadgeTemplate = $moduleSlug === 'messages'
                                ? $t('messages.unread_badge', ['count' => '__count__'])
                                : '';
                            $modulePath = '/module/' . $moduleSlug;
                            $moduleIsActive = $isCurrentPath($modulePath);
                        ?>
                        <a
                            class="nav-item<?= $moduleIsActive ? ' is-active' : '' ?>"
                            href="<?= htmlspecialchars($modulePath, ENT_QUOTES, 'UTF-8') ?>"
                            data-module-nav="<?= htmlspecialchars($moduleSlug, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $moduleBadgeTemplate !== '' ? 'data-badge-template="' . htmlspecialchars($moduleBadgeTemplate, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                            <?= $moduleIsActive ? 'aria-current="page"' : '' ?>
                        >
                            <span class="nav-code"><?= htmlspecialchars($module['code'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span><?= htmlspecialchars($t($module['title_key']), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($moduleBadgeCount > 0): ?>
                                <strong
                                    class="nav-badge"
                                    data-module-badge="<?= htmlspecialchars($moduleSlug, ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="<?= htmlspecialchars($t('messages.unread_badge', ['count' => $moduleBadgeCount]), ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <?= htmlspecialchars((string) $moduleBadgeCount, ENT_QUOTES, 'UTF-8') ?>
                                </strong>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <div class="locale-switcher" aria-label="<?= htmlspecialchars($t('nav.language'), ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($availableLocales as $locale => $label): ?>
                    <a
                        class="<?= $locale === $currentLocale ? 'is-active' : '' ?>"
                        href="?lang=<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <?= htmlspecialchars(strtoupper(substr($locale, 0, 2)), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>
        <?php if ($user): ?>
            <div
                class="mobile-menu-overlay"
                data-mobile-menu-overlay
                aria-hidden="true"
            ></div>
        <?php endif; ?>

        <main class="main-panel">
            <?php if ($user): ?>
                <header class="mobile-appbar">
                    <button
                        class="mobile-icon-button"
                        type="button"
                        data-mobile-menu-toggle
                        aria-controls="primary-navigation"
                        aria-expanded="false"
                        aria-label="<?= htmlspecialchars($t('nav.open_menu'), ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars($t('nav.open_menu'), ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <span class="mobile-menu-icon" aria-hidden="true"><i></i><i></i><i></i></span>
                    </button>
                    <a class="mobile-wordmark" href="/" aria-label="MyTakii Intranet">
                        <img src="<?= htmlspecialchars($asset('takii-logo-borderless.png'), ENT_QUOTES, 'UTF-8') ?>" width="36" height="36" alt="">
                        <strong>MyTakii</strong>
                    </a>
                    <?php if (isset($accessibleModules['messages'])): ?>
                        <?php $mobileMessageCount = (int) ($moduleBadges['messages'] ?? 0); ?>
                        <a
                            class="mobile-icon-button mobile-message-button<?= $isCurrentPath('/module/messages') ? ' is-active' : '' ?>"
                            href="/module/messages"
                            data-module-nav="messages"
                            data-badge-template="<?= htmlspecialchars($t('messages.unread_badge', ['count' => '__count__']), ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="<?= htmlspecialchars($t('messages.title'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <span aria-hidden="true">MS</span>
                            <?php if ($mobileMessageCount > 0): ?>
                                <strong class="nav-badge" data-module-badge="messages" aria-label="<?= htmlspecialchars($t('messages.unread_badge', ['count' => $mobileMessageCount]), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $mobileMessageCount, ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php endif; ?>
                        </a>
                    <?php else: ?>
                        <span class="mobile-appbar-spacer" aria-hidden="true"></span>
                    <?php endif; ?>
                </header>
                <header class="topbar">
                    <div>
                        <p><?= htmlspecialchars($user['department'], ENT_QUOTES, 'UTF-8') ?></p>
                        <strong><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="topbar-actions">
                        <div class="pwa-control">
                            <span data-pwa-status><?= htmlspecialchars($t('pwa.status.loading'), ENT_QUOTES, 'UTF-8') ?></span>
                            <button
                                class="button ghost"
                                type="button"
                                data-pwa-enable
                                data-enabled-label="<?= htmlspecialchars($t('pwa.enabled'), ENT_QUOTES, 'UTF-8') ?>"
                                data-disabled-label="<?= htmlspecialchars($t('pwa.enable'), ENT_QUOTES, 'UTF-8') ?>"
                                data-ready-text="<?= htmlspecialchars($t('pwa.status.ready'), ENT_QUOTES, 'UTF-8') ?>"
                                data-idle-text="<?= htmlspecialchars($t('pwa.status.idle'), ENT_QUOTES, 'UTF-8') ?>"
                                data-unsupported-text="<?= htmlspecialchars($t('pwa.status.unsupported'), ENT_QUOTES, 'UTF-8') ?>"
                                data-denied-text="<?= htmlspecialchars($t('pwa.status.denied'), ENT_QUOTES, 'UTF-8') ?>"
                                data-error-text="<?= htmlspecialchars($t('pwa.status.error'), ENT_QUOTES, 'UTF-8') ?>"
                            ><?= htmlspecialchars($t('pwa.enable'), ENT_QUOTES, 'UTF-8') ?></button>
                            <button
                                class="button ghost"
                                type="button"
                                hidden
                                data-pwa-test
                                data-sending-text="<?= htmlspecialchars($t('pwa.status.sending'), ENT_QUOTES, 'UTF-8') ?>"
                                data-sent-text="<?= htmlspecialchars($t('pwa.status.sent'), ENT_QUOTES, 'UTF-8') ?>"
                            ><?= htmlspecialchars($t('pwa.test'), ENT_QUOTES, 'UTF-8') ?></button>
                        </div>
                        <form method="post" action="/logout">
                            <?= $csrf() ?>
                            <button class="button ghost" type="submit"><?= htmlspecialchars($t('nav.logout'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                    </div>
                </header>
            <?php endif; ?>

            <?php if ($user && !empty($leaveBookSignatureAlerts)): ?>
                <section class="account-alerts" aria-label="<?= htmlspecialchars($t('leave.signature_alert.title'), ENT_QUOTES, 'UTF-8') ?>">
                    <?php foreach ($leaveBookSignatureAlerts as $signatureAlert): ?>
                        <?php
                            $signatureStartsOn = (string) ($signatureAlert['starts_on'] ?? '');
                            $signatureEndsOn = (string) ($signatureAlert['ends_on'] ?? '');
                            $signatureDateRange = $signatureEndsOn === '' || $signatureStartsOn === $signatureEndsOn
                                ? $signatureStartsOn
                                : $signatureStartsOn . ' - ' . $signatureEndsOn;
                            $signatureDayPartKey = (string) ($signatureAlert['day_part_key'] ?? 'leave.day_part.full');

                            if ($signatureDayPartKey !== 'leave.day_part.full') {
                                $signatureDateRange .= ' / ' . $t($signatureDayPartKey);
                            }
                        ?>
                        <article class="account-alert signature-alert" role="alert">
                            <span class="module-code">LV</span>
                            <div>
                                <strong><?= htmlspecialchars($t('leave.signature_alert.title'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <p>
                                    <?= htmlspecialchars($t('leave.signature_alert.body', [
                                        'request_id' => (string) ($signatureAlert['id'] ?? ''),
                                        'date_range' => $signatureDateRange,
                                        'days' => (string) ($signatureAlert['total_days_label'] ?? $signatureAlert['total_days'] ?? ''),
                                    ]), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <?php if (!empty($signatureAlert['due_at'])): ?>
                                    <small><?= htmlspecialchars($t('leave.signature_alert.due', ['date' => (string) $signatureAlert['due_at']]), ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?= $content ?>
        </main>

        <?php if ($user): ?>
            <nav class="mobile-bottom-nav" aria-label="<?= htmlspecialchars($t('nav.mobile_primary'), ENT_QUOTES, 'UTF-8') ?>">
                <a class="mobile-bottom-link<?= $isCurrentPath('/') ? ' is-active' : '' ?>" href="/"<?= $isCurrentPath('/') ? ' aria-current="page"' : '' ?>>
                    <span class="mobile-bottom-code" aria-hidden="true">DB</span>
                    <span><?= htmlspecialchars($t('nav.dashboard'), ENT_QUOTES, 'UTF-8') ?></span>
                </a>
                <?php foreach (['leave', 'messages', 'personnel'] as $mobileModuleSlug): ?>
                    <?php if (!isset($accessibleModules[$mobileModuleSlug])): ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php
                        $mobileModule = $accessibleModules[$mobileModuleSlug];
                        $mobileModulePath = '/module/' . $mobileModuleSlug;
                        $mobileModuleIsActive = $mobileModuleSlug === 'leave'
                            ? $isCurrentPath($mobileModulePath, '/leave')
                            : $isCurrentPath($mobileModulePath);
                        $mobileModuleBadgeCount = (int) ($moduleBadges[$mobileModuleSlug] ?? 0);
                    ?>
                    <a
                        class="mobile-bottom-link<?= $mobileModuleIsActive ? ' is-active' : '' ?>"
                        href="<?= htmlspecialchars($mobileModulePath, ENT_QUOTES, 'UTF-8') ?>"
                        data-module-nav="<?= htmlspecialchars($mobileModuleSlug, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $mobileModuleSlug === 'messages' ? 'data-badge-template="' . htmlspecialchars($t('messages.unread_badge', ['count' => '__count__']), ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                        <?= $mobileModuleIsActive ? 'aria-current="page"' : '' ?>
                    >
                        <span class="mobile-bottom-code" aria-hidden="true"><?= htmlspecialchars($mobileModule['code'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($t($mobileModule['title_key']), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($mobileModuleBadgeCount > 0): ?>
                            <strong class="nav-badge" data-module-badge="<?= htmlspecialchars($mobileModuleSlug, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($t('messages.unread_badge', ['count' => $mobileModuleBadgeCount]), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $mobileModuleBadgeCount, ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <button
                    class="mobile-bottom-link"
                    type="button"
                    data-mobile-menu-toggle
                    aria-controls="primary-navigation"
                    aria-expanded="false"
                >
                    <span class="mobile-bottom-code" aria-hidden="true">MN</span>
                    <span><?= htmlspecialchars($t('nav.more'), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            </nav>
        <?php endif; ?>
    </div>
    <?php if (!empty($grapesjsAssets)): ?>
        <script src="/vendor/grapesjs/0.23.2/grapes.min.js" defer></script>
        <?php if (!empty($reportExporterAssets)): ?>
            <script src="/vendor/html2canvas/1.4.1/html2canvas.min.js" defer></script>
            <script src="/vendor/jspdf/4.2.1/jspdf.umd.min.js" defer></script>
        <?php endif; ?>
        <script src="<?= htmlspecialchars($asset('templates-editor.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <?php endif; ?>
    <script src="<?= htmlspecialchars($asset('pwa.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars($asset('app.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
</html>
