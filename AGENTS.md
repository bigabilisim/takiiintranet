# MyTakii Engineering Contract

This repository is a PHP 8.3 modular monolith. Every code change must preserve
the ownership and dependency boundaries recorded in `config/architecture.php`.
`docs/architecture-tree.md` is the human-readable map of the same system.

## Before Changing Code

1. Read `docs/architecture-tree.md` and the affected entries in
   `config/architecture.php`.
2. Identify the owning module, its state documents, routes, dependencies, and
   regression tests.
3. Keep HTTP handling in controllers, business state changes in stores/services,
   rendering in views, and composition in `bootstrap/app.php`.
4. When adding or moving a controller, module class, view, route, state document,
   JavaScript entry point, or regression test, update `config/architecture.php`
   in the same change.

## Required Verification

Run the complete release gate from the repository root:

```bash
composer verify:release
```

The gate must pass without skipped required checks. MariaDB integration tests
must use a dedicated test database and can be included with:

```bash
RELEASE_VERIFY_MARIADB=1 composer verify:release
```

Never point destructive integration tests at the production database.

## Production Release

1. Update the version and release note in `app/Core/ReleaseNoteStore.php`.
2. Commit the complete change and create the matching version tag.
3. Run `composer verify:release:record` on the clean tagged commit.
4. Run `composer release:assert` immediately before packaging or upload.
5. Take a production data backup before schema or data migrations.
6. Deploy immutable versioned application directories. Never overwrite the
   production `storage` directory or `.env`.
7. Switch the public entry point only after the package is complete.
8. Verify login, dashboard, the changed module, PWA files, security headers,
   server response time, and production error logs.

If any gate, migration, smoke test, or live check fails, stop the release and
keep the previous public entry point active. Do not send release notification
emails; the current product-owner preference is in-app/version-page tracking.
