<?php

declare(strict_types=1);

use App\Core\LocalizedDateFormatter;
use App\Core\Translator;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$localeFiles = [
    'tr-TR' => $projectRoot . '/resources/lang/tr-TR.php',
    'en-US' => $projectRoot . '/resources/lang/en-US.php',
    'de-DE' => $projectRoot . '/resources/lang/de-DE.php',
    'ja-JP' => $projectRoot . '/resources/lang/ja-JP.php',
];

function i18nAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function i18nPlaceholders(string $value): array
{
    preg_match_all('/{{\s*[A-Za-z_][A-Za-z0-9_]*\s*}}/', $value, $templateMatches);
    preg_match_all('/(?<!:):[A-Za-z_][A-Za-z0-9_]*/', $value, $namedMatches);
    $placeholders = array_values(array_unique(array_merge($templateMatches[0], $namedMatches[0])));
    sort($placeholders);

    return $placeholders;
}

try {
    $locales = [];

    foreach ($localeFiles as $locale => $path) {
        $translations = require $path;
        i18nAssert(is_array($translations), $locale . ' translation file did not return an array.');
        i18nAssert(count($translations) > 0, $locale . ' translation file is empty.');

        foreach ($translations as $key => $value) {
            i18nAssert(is_string($key) && $key !== '', $locale . ' contains an invalid key.');
            i18nAssert(is_string($value) && trim($value) !== '', $locale . ' has an empty value for ' . $key . '.');
            i18nAssert($value === trim($value), $locale . ' has surrounding whitespace for ' . $key . '.');
        }

        $locales[$locale] = $translations;
    }

    $referenceKeys = array_keys($locales['tr-TR']);
    sort($referenceKeys);

    foreach ($locales as $locale => $translations) {
        $keys = array_keys($translations);
        sort($keys);
        i18nAssert($keys === $referenceKeys, $locale . ' translation keys do not match tr-TR.');
    }

    foreach ($referenceKeys as $key) {
        $referencePlaceholders = i18nPlaceholders($locales['tr-TR'][$key]);

        foreach ($locales as $locale => $translations) {
            i18nAssert(
                i18nPlaceholders($translations[$key]) === $referencePlaceholders,
                $locale . ' placeholders do not match tr-TR for ' . $key . '.'
            );
        }
    }

    $dateExpectations = [
        'tr-TR' => ['month' => 'Temmuz 2026', 'day' => '13 Temmuz 2026', 'weekday' => 'Pzt'],
        'en-US' => ['month' => 'July 2026', 'day' => '13 July 2026', 'weekday' => 'Mon'],
        'de-DE' => ['month' => 'Juli 2026', 'day' => '13. Juli 2026', 'weekday' => 'Mo'],
        'ja-JP' => ['month' => '2026年7月', 'day' => '2026年7月13日', 'weekday' => '月'],
    ];

    foreach ($dateExpectations as $locale => $expected) {
        $dates = new LocalizedDateFormatter(new Translator($projectRoot . '/resources/lang', $locale, 'tr-TR'));
        i18nAssert($dates->format('2026-07-13', 'month_year') === $expected['month'], $locale . ' month and year format is incorrect.');
        i18nAssert($dates->format('2026-07-13') === $expected['day'], $locale . ' full date format is incorrect.');
        i18nAssert($dates->weekdayShort('2026-07-13') === $expected['weekday'], $locale . ' weekday format is incorrect.');
    }

    $bannedCopy = [
        'tr-TR' => ['Dönanım', 'şablonlarıni', 'Bu günde', 'çıkartın', 'arefesi', 'Log kayıtları', 'Admin havuzu', 'Import için', 'Personel import'],
        'en-US' => ['Waiting finance', 'Waiting manager', 'selected personnel', 'Admin pool', 'Passive', 'Report and mail templates', 'Test mail'],
        'de-DE' => ['Zurueck', 'gueltig', 'koennen', 'oeffnen', 'ungueltig', 'uebereinstimmen', 'Shift', 'Mailvorlage', 'Testmail', 'Outbox', 'Admin-Pool'],
        'ja-JP' => ['Shift', '人員', '管理プール', '会話内', '物理台帳', 'HR'],
    ];

    foreach ($bannedCopy as $locale => $phrases) {
        $copy = implode("\n", $locales[$locale]);

        foreach ($phrases as $phrase) {
            i18nAssert(!str_contains($copy, $phrase), $locale . ' still contains legacy copy: ' . $phrase);
        }
    }

    $mailerCopy = file_get_contents($projectRoot . '/app/Modules/Auth/PasswordResetMailer.php')
        . file_get_contents($projectRoot . '/app/Modules/Leave/LeaveStore.php')
        . file_get_contents($projectRoot . '/app/Modules/Templates/TemplateTestMailer.php');

    foreach (['sifre sifirlama', 'Kullanici adi', 'Izin talebiniz', 'Yillik izin', 'Gun bolumu', 'Ogleden once', 'Test maili'] as $legacyPhrase) {
        i18nAssert(!str_contains($mailerCopy, $legacyPhrase), 'Mailer copy still contains legacy text: ' . $legacyPhrase);
    }

    $resetView = (string) file_get_contents($projectRoot . '/resources/views/auth/reset-password.php');
    i18nAssert(str_contains($resetView, '<span>12+</span>'), 'Password reset helper does not show the 12-character minimum.');
    i18nAssert(!str_contains($resetView, '<span>6+</span>'), 'Password reset helper still shows the old 6-character minimum.');

    $offlineScript = (string) file_get_contents($projectRoot . '/public/assets/offline.js');

    foreach (array_keys($localeFiles) as $locale) {
        i18nAssert(str_contains($offlineScript, "'" . $locale . "'"), 'Offline PWA copy is missing ' . $locale . '.');
    }

    echo 'I18n regression test passed: ' . count($referenceKeys) . " keys across four locales, placeholders, mail copy, and offline PWA.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'I18n regression test failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
