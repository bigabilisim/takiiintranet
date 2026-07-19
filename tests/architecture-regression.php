<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$architecture = require APP_ROOT . '/config/architecture.php';
$failures = [];

$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
};

$relativePhpFiles = static function (string $directory): array {
    $files = [];
    $rootLength = strlen(APP_ROOT) + 1;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), $rootLength));
    }

    sort($files);

    return $files;
};

$schemaVersion = $architecture['schema_version'] ?? null;

if (!is_int($schemaVersion) || $schemaVersion < 1) {
    $fail('config/architecture.php must contain a positive integer schema_version.');
}

$modules = $architecture['modules'] ?? null;

if (!is_array($modules) || $modules === []) {
    $fail('config/architecture.php must declare at least one module.');
    $modules = [];
}

$registered = [
    'controllers' => [],
    'domain_files' => [],
    'views' => [],
    'routes' => [],
];

foreach ($modules as $moduleName => $module) {
    if (!is_string($moduleName) || $moduleName === '' || !is_array($module)) {
        $fail('Every architecture module must have a non-empty name and array definition.');
        continue;
    }

    foreach (array_keys($registered) as $collection) {
        $items = $module[$collection] ?? null;

        if (!is_array($items)) {
            $fail(sprintf('Module %s must declare %s as an array.', $moduleName, $collection));
            continue;
        }

        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                $fail(sprintf('Module %s contains an invalid %s entry.', $moduleName, $collection));
                continue;
            }

            if (isset($registered[$collection][$item])) {
                $fail(sprintf(
                    '%s is owned by both %s and %s.',
                    $item,
                    $registered[$collection][$item],
                    $moduleName
                ));
                continue;
            }

            $registered[$collection][$item] = $moduleName;

            if ($collection !== 'routes' && !is_file(APP_ROOT . '/' . $item)) {
                $fail(sprintf('Registered %s file does not exist: %s.', $moduleName, $item));
            }
        }
    }
}

$expectedFileSets = [
    'controllers' => $relativePhpFiles('app/Controllers'),
    'domain_files' => $relativePhpFiles('app/Modules'),
    'views' => $relativePhpFiles('resources/views'),
];

foreach ($expectedFileSets as $collection => $actualFiles) {
    $registeredFiles = array_keys($registered[$collection]);
    sort($registeredFiles);
    $missing = array_values(array_diff($actualFiles, $registeredFiles));
    $unknown = array_values(array_diff($registeredFiles, $actualFiles));

    foreach ($missing as $file) {
        $fail(sprintf('Unregistered %s file: %s.', $collection, $file));
    }

    foreach ($unknown as $file) {
        $fail(sprintf('Architecture registers a missing %s file: %s.', $collection, $file));
    }
}

$bootstrapPath = APP_ROOT . '/bootstrap/app.php';
$bootstrap = file_get_contents($bootstrapPath);

if ($bootstrap === false) {
    $fail('Unable to read bootstrap/app.php.');
    $bootstrap = '';
}

preg_match_all(
    '/\$router->(get|post)\(\s*[\'\"]([^\'\"]+)[\'\"]/i',
    $bootstrap,
    $routeMatches,
    PREG_SET_ORDER
);

$actualRoutes = [];

foreach ($routeMatches as $routeMatch) {
    $route = strtoupper((string) $routeMatch[1]) . ' ' . (string) $routeMatch[2];

    if (isset($actualRoutes[$route])) {
        $fail('Duplicate route registration in bootstrap/app.php: ' . $route . '.');
    }

    $actualRoutes[$route] = true;
}

$registeredRoutes = array_fill_keys(array_keys($registered['routes']), true);

foreach (array_diff_key($actualRoutes, $registeredRoutes) as $route => $_unused) {
    $fail('Route is not assigned to an architecture module: ' . $route . '.');
}

foreach (array_diff_key($registeredRoutes, $actualRoutes) as $route => $_unused) {
    $fail('Architecture route is not registered in bootstrap/app.php: ' . $route . '.');
}

$stateDocuments = $architecture['state_documents'] ?? null;

if (!is_array($stateDocuments) || $stateDocuments === []) {
    $fail('Architecture must declare state_documents.');
    $stateDocuments = [];
}

foreach ($stateDocuments as $documentKey => $document) {
    if (!preg_match('/^[a-z][a-z0-9_]*$/', (string) $documentKey)) {
        $fail('Invalid state document key: ' . (string) $documentKey . '.');
    }

    $owner = is_array($document) ? (string) ($document['owner'] ?? '') : '';
    $legacyFile = is_array($document) ? (string) ($document['legacy_file'] ?? '') : '';

    if (!isset($modules[$owner])) {
        $fail(sprintf('State document %s has unknown owner %s.', $documentKey, $owner));
    }

    if (!str_starts_with($legacyFile, 'storage/') || !str_ends_with($legacyFile, '.json')) {
        $fail(sprintf('State document %s has invalid legacy_file %s.', $documentKey, $legacyFile));
    }
}

$stateSources = array_merge(
    $relativePhpFiles('app'),
    ['bootstrap/app.php']
);
$discoveredStateKeys = [];

foreach ($stateSources as $source) {
    $contents = file_get_contents(APP_ROOT . '/' . $source);

    if ($contents === false) {
        $fail('Unable to read state source: ' . $source . '.');
        continue;
    }

    preg_match_all(
        '/const\s+[A-Z0-9_]*STATE_KEY\s*=\s*[\'\"]([a-z][a-z0-9_]*)[\'\"]/',
        $contents,
        $constantMatches
    );
    preg_match_all(
        '/(?:\$this->stateStore|\$stateStore)->(?:beginWrite|read|write)\(\s*[\'\"]([a-z][a-z0-9_]*)[\'\"]/',
        $contents,
        $literalMatches
    );

    foreach (array_merge($constantMatches[1] ?? [], $literalMatches[1] ?? []) as $documentKey) {
        $discoveredStateKeys[(string) $documentKey] = true;
    }
}

foreach (array_diff_key($discoveredStateKeys, $stateDocuments) as $documentKey => $_unused) {
    $fail('State document is used by code but missing from architecture: ' . $documentKey . '.');
}

foreach (array_diff_key($stateDocuments, $discoveredStateKeys) as $documentKey => $_unused) {
    $fail('Architecture state document is not used by application code: ' . $documentKey . '.');
}

$migration = file_get_contents(APP_ROOT . '/scripts/migrate-state-to-mariadb.php');
$migrationDocuments = [];

if ($migration === false || preg_match('/\$documents\s*=\s*\[(.*?)\];/s', $migration, $documentBlock) !== 1) {
    $fail('Unable to inspect the state migration document registry.');
} else {
    preg_match_all('/[\'\"]([a-z][a-z0-9_]*)[\'\"]\s*=>\s*[\'\"]([^\'\"]+\.json)[\'\"]/', $documentBlock[1], $documentMatches, PREG_SET_ORDER);

    foreach ($documentMatches as $documentMatch) {
        $migrationDocuments[(string) $documentMatch[1]] = 'storage/' . basename((string) $documentMatch[2]);
    }

    foreach ($stateDocuments as $documentKey => $document) {
        $expectedLegacyFile = (string) ($document['legacy_file'] ?? '');

        if (!isset($migrationDocuments[$documentKey])) {
            $fail('State migration is missing document: ' . $documentKey . '.');
            continue;
        }

        if ($migrationDocuments[$documentKey] !== $expectedLegacyFile) {
            $fail(sprintf(
                'State migration file mismatch for %s: expected %s, found %s.',
                $documentKey,
                $expectedLegacyFile,
                $migrationDocuments[$documentKey]
            ));
        }
    }

    foreach (array_diff_key($migrationDocuments, $stateDocuments) as $documentKey => $_unused) {
        $fail('State migration contains an unowned document: ' . $documentKey . '.');
    }
}

$allowedDependencies = $architecture['allowed_module_dependencies'] ?? [];

if (!is_array($allowedDependencies)) {
    $fail('allowed_module_dependencies must be an array.');
    $allowedDependencies = [];
}

foreach ($allowedDependencies as $source => $namespaces) {
    if (!is_file(APP_ROOT . '/' . $source)) {
        $fail('Dependency rule references a missing file: ' . $source . '.');
    }

    if (!is_array($namespaces)) {
        $fail('Dependency rule must contain an array: ' . $source . '.');
    }
}

$dependencySources = array_merge(
    $relativePhpFiles('app/Controllers'),
    $relativePhpFiles('app/Core'),
    $relativePhpFiles('app/Modules')
);

foreach ($dependencySources as $source) {
    $contents = file_get_contents(APP_ROOT . '/' . $source);

    if ($contents === false) {
        continue;
    }

    if (!str_starts_with($source, 'app/Controllers/') && preg_match('/^use\s+App\\\\Controllers\\\\/m', $contents) === 1) {
        $fail('Lower layer may not depend on a controller: ' . $source . '.');
    }

    preg_match_all('/^use\s+App\\\\Modules\\\\([^\\\\;]+)\\\\/m', $contents, $dependencyMatches);
    $actualDependencies = array_values(array_unique(array_map('strval', $dependencyMatches[1] ?? [])));
    $allowed = array_values(array_unique(array_map('strval', (array) ($allowedDependencies[$source] ?? []))));

    if (preg_match('#^app/Modules/([^/]+)/#', $source, $moduleMatch) === 1) {
        $allowed[] = (string) $moduleMatch[1];
    }

    foreach (array_diff($actualDependencies, $allowed) as $namespace) {
        $fail(sprintf('Undeclared module dependency in %s: App\\Modules\\%s.', $source, $namespace));
    }

    foreach ((array) ($allowedDependencies[$source] ?? []) as $namespace) {
        if (!in_array((string) $namespace, $actualDependencies, true)) {
            $fail(sprintf('Stale module dependency rule in %s: %s.', $source, $namespace));
        }
    }
}

$forbiddenViewPatterns = [
    '/\bnew\s+PDO\b/i' => 'PDO construction',
    '/App\\\\Core\\\\Database/' => 'Database dependency',
    '/App\\\\Core\\\\StateStore/' => 'StateStore dependency',
    '/\bfile_put_contents\s*\(/i' => 'file write',
    '/\bfwrite\s*\(/i' => 'stream write',
];

foreach ($expectedFileSets['views'] as $viewFile) {
    $contents = file_get_contents(APP_ROOT . '/' . $viewFile);

    if ($contents === false) {
        continue;
    }

    foreach ($forbiddenViewPatterns as $pattern => $label) {
        if (preg_match($pattern, $contents) === 1) {
            $fail(sprintf('View %s contains forbidden %s.', $viewFile, $label));
        }
    }
}

$entrypoint = file_get_contents(APP_ROOT . '/public/index.php');

if ($entrypoint === false || !str_contains($entrypoint, "'/bootstrap/app.php'")) {
    $fail('public/index.php must delegate application composition to bootstrap/app.php.');
}

$releaseGate = $architecture['release_gate'] ?? [];
$registeredTests = array_map('strval', (array) ($releaseGate['required_tests'] ?? []));

foreach ((array) ($releaseGate['mariadb_tests'] ?? []) as $testDefinition) {
    $registeredTests[] = (string) ($testDefinition['file'] ?? '');
}

$registeredTests = array_values(array_unique(array_filter($registeredTests)));
sort($registeredTests);
$actualTests = $relativePhpFiles('tests');

foreach (array_diff($actualTests, $registeredTests) as $testFile) {
    $fail('Regression test is not part of the release gate: ' . $testFile . '.');
}

foreach (array_diff($registeredTests, $actualTests) as $testFile) {
    $fail('Release gate registers a missing regression test: ' . $testFile . '.');
}

$registeredJavaScript = array_map('strval', (array) ($releaseGate['javascript_files'] ?? []));
sort($registeredJavaScript);
$actualJavaScript = [];
$publicIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(APP_ROOT . '/public', FilesystemIterator::SKIP_DOTS)
);

foreach ($publicIterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'js') {
        continue;
    }

    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(APP_ROOT) + 1));

    if (!str_starts_with($relative, 'public/vendor/')) {
        $actualJavaScript[] = $relative;
    }
}

sort($actualJavaScript);

foreach (array_diff($actualJavaScript, $registeredJavaScript) as $javascriptFile) {
    $fail('First-party JavaScript is not part of the release gate: ' . $javascriptFile . '.');
}

foreach (array_diff($registeredJavaScript, $actualJavaScript) as $javascriptFile) {
    $fail('Release gate registers a missing JavaScript file: ' . $javascriptFile . '.');
}

if ($failures !== []) {
    fwrite(STDERR, "Architecture regression test failed:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }

    exit(1);
}

printf(
    "Architecture regression test passed: %d modules, %d routes, %d state documents, %d owned files.\n",
    count($modules),
    count($actualRoutes),
    count($stateDocuments),
    count($registered['controllers']) + count($registered['domain_files']) + count($registered['views'])
);
