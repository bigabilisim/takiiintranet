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
        'takii-logo.jpg' => [600, 600, IMAGETYPE_JPEG],
        'favicon-64.png' => [64, 64, IMAGETYPE_PNG],
        'apple-touch-icon.png' => [180, 180, IMAGETYPE_PNG],
        'icon-192.png' => [192, 192, IMAGETYPE_PNG],
        'icon-512.png' => [512, 512, IMAGETYPE_PNG],
        'icon-maskable-512.png' => [512, 512, IMAGETYPE_PNG],
    ];

    foreach ($assets as $name => [$width, $height, $type]) {
        $path = $projectRoot . '/public/assets/' . $name;
        brandingAssert(is_file($path) && filesize($path) > 0, $name . ' is missing or empty.');
        $image = getimagesize($path);
        brandingAssert(is_array($image), $name . ' is not a readable image.');
        brandingAssert($image[0] === $width && $image[1] === $height, $name . ' dimensions are incorrect.');
        brandingAssert($image[2] === $type, $name . ' format is incorrect.');
    }

    $manifest = json_decode(
        (string) file_get_contents($projectRoot . '/public/manifest.webmanifest'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $manifestIcons = array_column((array) ($manifest['icons'] ?? []), 'src');

    foreach (['/assets/icon-192.png', '/assets/icon-512.png', '/assets/icon-maskable-512.png'] as $icon) {
        brandingAssert(in_array($icon, $manifestIcons, true), 'Manifest is missing ' . $icon . '.');
    }

    brandingAssert(
        count(array_filter($manifestIcons, static fn (string $icon): bool => str_ends_with($icon, '.svg'))) === 0,
        'Manifest still exposes a legacy SVG application icon.'
    );

    $layout = (string) file_get_contents($projectRoot . '/resources/views/layout.php');
    $offline = (string) file_get_contents($projectRoot . '/public/offline.html');
    $serviceWorker = (string) file_get_contents($projectRoot . '/public/service-worker.js');

    brandingAssert(str_contains($layout, "asset('takii-logo.jpg')"), 'Application layout does not use the TAKII logo.');
    brandingAssert(str_contains($layout, "asset('favicon-64.png')"), 'Application layout does not use the TAKII favicon.');
    brandingAssert(str_contains($offline, '/assets/takii-logo.jpg'), 'Offline page does not use the TAKII logo.');
    brandingAssert(str_contains($serviceWorker, "mytakii-intranet-v49"), 'Service Worker cache version was not updated.');

    foreach (['/assets/takii-logo.jpg', '/assets/favicon-64.png', '/assets/icon-192.png', '/assets/icon-512.png', '/assets/icon-maskable-512.png'] as $asset) {
        brandingAssert(str_contains($serviceWorker, "'" . $asset . "'"), 'Service Worker does not cache ' . $asset . '.');
    }

    echo "Branding regression test passed: visible logo, favicon and PWA icon set verified.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Branding regression test failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
