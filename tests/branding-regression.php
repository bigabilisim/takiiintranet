<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

function brandingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $assets = [
        'takii-logo-borderless.png' => [600, 600, IMAGETYPE_PNG],
        'takii-logo.jpg' => [600, 600, IMAGETYPE_JPEG],
        'favicon-64.png' => [64, 64, IMAGETYPE_PNG],
        'apple-touch-icon.png' => [180, 180, IMAGETYPE_PNG],
        'icon-192.png' => [192, 192, IMAGETYPE_PNG],
        'icon-512.png' => [512, 512, IMAGETYPE_PNG],
        'icon-maskable-512.png' => [512, 512, IMAGETYPE_PNG],
        'takii-icon-64.png' => [64, 64, IMAGETYPE_PNG],
        'takii-apple-touch-icon.png' => [180, 180, IMAGETYPE_PNG],
        'takii-icon-192.png' => [192, 192, IMAGETYPE_PNG],
        'takii-icon-512.png' => [512, 512, IMAGETYPE_PNG],
        'takii-icon-maskable-512.png' => [512, 512, IMAGETYPE_PNG],
        'logo-horizontal.png' => [960, 330, IMAGETYPE_PNG],
    ];

    foreach ($assets as $name => [$width, $height, $type]) {
        $path = $projectRoot . '/public/assets/' . $name;
        brandingAssert(is_file($path) && filesize($path) > 0, $name . ' is missing or empty.');
        $image = getimagesize($path);
        brandingAssert(is_array($image), $name . ' is not a readable image.');
        brandingAssert($image[0] === $width && $image[1] === $height, $name . ' dimensions are incorrect.');
        brandingAssert($image[2] === $type, $name . ' format is incorrect.');
    }

    foreach (['takii-logo-borderless.png' => 'imagecreatefrompng', 'takii-logo.jpg' => 'imagecreatefromjpeg'] as $name => $loader) {
        $logo = $loader($projectRoot . '/public/assets/' . $name);
        brandingAssert($logo !== false, $name . ' could not be opened.');

        for ($coordinate = 0; $coordinate < 600; $coordinate++) {
            foreach ([[40, $coordinate], [559, $coordinate], [$coordinate, 40], [$coordinate, 559]] as [$x, $y]) {
                $rgb = imagecolorat($logo, $x, $y);
                brandingAssert(
                    (($rgb >> 16) & 255) >= 245 && (($rgb >> 8) & 255) >= 245 && ($rgb & 255) >= 245,
                    $name . ' still contains a dark outer frame.'
                );
            }
        }

        imagedestroy($logo);
    }

    foreach ([
        'favicon-64.png' => 'takii-icon-64.png',
        'apple-touch-icon.png' => 'takii-apple-touch-icon.png',
        'icon-192.png' => 'takii-icon-192.png',
        'icon-512.png' => 'takii-icon-512.png',
        'icon-maskable-512.png' => 'takii-icon-maskable-512.png',
    ] as $legacy => $current) {
        brandingAssert(
            hash_file('sha256', $projectRoot . '/public/assets/' . $legacy)
                === hash_file('sha256', $projectRoot . '/public/assets/' . $current),
            $legacy . ' is not synchronized with ' . $current . '.'
        );
    }

    $manifest = json_decode(
        (string) file_get_contents($projectRoot . '/public/manifest.webmanifest'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $manifestIcons = array_column((array) ($manifest['icons'] ?? []), 'src');

    foreach (['/assets/takii-icon-192.png', '/assets/takii-icon-512.png', '/assets/takii-icon-maskable-512.png'] as $icon) {
        brandingAssert(in_array($icon, $manifestIcons, true), 'Manifest is missing ' . $icon . '.');
    }

    foreach (['/assets/icon-192.png', '/assets/icon-512.png', '/assets/icon-maskable-512.png'] as $legacyIcon) {
        brandingAssert(!in_array($legacyIcon, $manifestIcons, true), 'Manifest still uses cached legacy path ' . $legacyIcon . '.');
    }

    brandingAssert(
        count(array_filter($manifestIcons, static fn (string $icon): bool => str_ends_with($icon, '.svg'))) === 0,
        'Manifest still exposes a legacy SVG application icon.'
    );

    $layout = (string) file_get_contents($projectRoot . '/resources/views/layout.php');
    $offline = (string) file_get_contents($projectRoot . '/public/offline.html');
    $serviceWorker = (string) file_get_contents($projectRoot . '/public/service-worker.js');
    $iconSvg = (string) file_get_contents($projectRoot . '/public/assets/icon.svg');
    $maskableSvg = (string) file_get_contents($projectRoot . '/public/assets/icon-maskable.svg');
    $horizontalSvg = (string) file_get_contents($projectRoot . '/public/assets/logo-horizontal.svg');

    brandingAssert(str_contains($layout, "asset('takii-logo-borderless.png')"), 'Application layout does not use the borderless TAKII logo.');
    brandingAssert(str_contains($layout, 'class="brand-name">MyTakii</strong>'), 'Application layout does not show the MyTakii brand name.');
    brandingAssert(str_contains($layout, "asset('takii-icon-64.png')"), 'Application layout does not use the current TAKII favicon.');
    brandingAssert(str_contains($layout, "asset('takii-apple-touch-icon.png')"), 'Application layout does not use the current Apple Touch icon.');
    brandingAssert(str_contains($offline, '/assets/takii-logo-borderless.png'), 'Offline page does not use the borderless TAKII logo.');
    brandingAssert(str_contains($offline, '/assets/takii-icon-64.png'), 'Offline page does not use the current TAKII favicon.');
    brandingAssert(str_contains($offline, 'class="brand-name">MyTakii</strong>'), 'Offline page does not show the MyTakii brand name.');
    brandingAssert(str_contains($serviceWorker, "mytakii-intranet-v51"), 'Service Worker cache version was not updated.');

    foreach ([$iconSvg, $maskableSvg, $horizontalSvg] as $svg) {
        brandingAssert(str_contains($svg, 'data:image/png;base64,'), 'A legacy SVG does not embed the borderless TAKII logo.');
        brandingAssert(!str_contains($svg, '__TAKII_BORDERLESS_BASE64__'), 'A legacy SVG still contains an unresolved logo placeholder.');
        brandingAssert(!str_contains($svg, 'stroke='), 'A legacy SVG still contains an outline stroke.');
    }

    foreach (['/assets/takii-logo-borderless.png', '/assets/takii-icon-64.png', '/assets/takii-icon-192.png', '/assets/takii-icon-512.png', '/assets/takii-icon-maskable-512.png', '/assets/takii-apple-touch-icon.png'] as $asset) {
        brandingAssert(str_contains($serviceWorker, "'" . $asset . "'"), 'Service Worker does not cache ' . $asset . '.');
    }

    echo "Branding regression test passed: visible logo, favicon and PWA icon set verified.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Branding regression test failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
