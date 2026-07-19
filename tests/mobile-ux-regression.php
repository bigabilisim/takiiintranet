<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

function mobileUxAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $layout = (string) file_get_contents($projectRoot . '/resources/views/layout.php');
    $personnel = (string) file_get_contents($projectRoot . '/resources/views/personnel/index.php');
    $shift = (string) file_get_contents($projectRoot . '/resources/views/shift/index.php');
    $versions = (string) file_get_contents($projectRoot . '/resources/views/admin/versions.php');
    $css = (string) file_get_contents($projectRoot . '/public/assets/app.css');
    $javascript = (string) file_get_contents($projectRoot . '/public/assets/app.js');

    foreach (['data-mobile-drawer', 'data-mobile-menu-toggle', 'data-mobile-menu-close', 'data-mobile-menu-overlay', 'mobile-appbar', 'mobile-bottom-nav'] as $marker) {
        mobileUxAssert(str_contains($layout, $marker), 'Layout is missing mobile navigation marker: ' . $marker . '.');
    }

    foreach (['leave', 'messages', 'personnel'] as $module) {
        mobileUxAssert(str_contains($layout, "'" . $module . "'"), 'Mobile quick navigation is missing ' . $module . '.');
    }

    mobileUxAssert(str_contains($layout, 'aria-current="page"'), 'Active navigation does not expose aria-current.');
    mobileUxAssert(str_contains($css, 'body.mobile-menu-open .app-shell.is-authenticated .sidebar'), 'Mobile drawer open state is missing.');
    mobileUxAssert(str_contains($css, 'min-height: calc(var(--mobile-nav-height) + env(safe-area-inset-bottom))'), 'Bottom navigation does not respect the device safe area.');
    mobileUxAssert(str_contains($css, 'grid-template-columns: repeat(7, minmax(112px, 1fr))'), 'Full leave calendar is not kept in a seven-column mobile grid.');
    mobileUxAssert(str_contains($css, 'grid-template-columns: repeat(7, minmax(76px, 1fr))'), 'Dashboard calendar is not kept in a seven-column mobile grid.');
    mobileUxAssert(
        preg_match('/\.calendar-grid\.is-month,\s*\.calendar-grid\.is-week\s*\{\s*grid-template-columns:\s*1fr;/s', $css) !== 1,
        'Leave calendar still collapses into a single, excessively tall mobile column.'
    );
    mobileUxAssert(str_contains($css, 'font-size: 16px;'), 'Mobile form controls do not prevent iOS focus zoom.');
    mobileUxAssert(str_contains($css, 'max-height: min(82dvh, 720px)'), 'Calendar details are not rendered as a bounded mobile sheet.');

    mobileUxAssert(str_contains($personnel, 'personnel-summary-person'), 'Personnel cards do not have a mobile identity header.');
    mobileUxAssert(substr_count($personnel, 'personnel-mobile-label') >= 5, 'Personnel card fields are missing mobile labels.');
    mobileUxAssert(substr_count($shift, 'data-mobile-collapsible') === 5, 'Shift management sections are not mobile collapsible.');
    mobileUxAssert(str_contains($versions, 'data-version-list'), 'Version history does not expose progressive loading.');
    mobileUxAssert(str_contains($versions, 'data-version-card'), 'Version records are not collapsible.');

    foreach (['mobile-menu-open', "toggleAttribute('inert'", "event.key === 'Escape'", 'querySelectorAll(\'[data-module-nav="messages"][data-badge-template]\')', 'data-mobile-collapsible', 'data-version-load-more'] as $behavior) {
        mobileUxAssert(str_contains($javascript, $behavior), 'Mobile JavaScript behavior is missing: ' . $behavior . '.');
    }

    foreach (['tr-TR', 'en-US', 'de-DE', 'ja-JP'] as $locale) {
        $translations = require $projectRoot . '/resources/lang/' . $locale . '.php';

        foreach (['nav.mobile_primary', 'nav.open_menu', 'nav.close_menu', 'nav.more'] as $key) {
            mobileUxAssert(isset($translations[$key]) && trim((string) $translations[$key]) !== '', $locale . ' is missing ' . $key . '.');
        }
    }

    echo "Mobile UX regression test passed: drawer, thumb navigation, calendars, forms and personnel cards verified.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Mobile UX regression test failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
