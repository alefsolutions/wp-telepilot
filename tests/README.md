# Automated Tests

Tests use a disposable WordPress database, never a live installation. All HTTP is blocked unless a test supplies a Telegram/OpenAI fixture.

1. Run `composer install` with PHP 8.0 or newer, mysqli, zip, curl and mbstring.
2. Run `php scripts/install-test-wordpress.php 6.6.2`.
3. Create a database named `telepilot_tests` on an isolated MySQL/MariaDB server.
4. Set `TELEPILOT_TEST_DB_HOST`, `TELEPILOT_TEST_DB_USER`, and `TELEPILOT_TEST_DB_PASSWORD`. Default host is `127.0.0.1:3308`.
5. Run `composer test`. This installs and destroys test tables. No production credentials are needed.

`composer test:regressions` runs stabilization reproductions. Initially these deliberately fail on the defects being repaired; they are release blockers, not skipped tests.

`composer package` checks consistent version metadata and builds an allowlisted ZIP under ignored `dist/`. Development tools, docs and Markdown files are not packaged.

CI covers minimum PHP/WordPress and the current stable WordPress on PHP 8.5. Releases require the full suite. Existing tags/releases are never replaced; beta publishing requires explicit workflow opt-in. Live staging transport/provider and visual checks remain separate from offline PHPUnit tests.

## Queue Migration Checks

The suite reconstructs the historical NOT NULL `update_id` column and runs the real upgrade from v3, beta.1 and an already-current version. Run on actual MySQL 8 and MariaDB, not a database-version shim. CI includes both engines. Permission tests create and remove a uniquely named database user without ALTER rights; the disposable test administrator needs CREATE USER and GRANT privileges. No production users or credentials are involved.

Coverage includes preserved records and unique IDs, multiple NULL continuations, unexpected schemas, throttled/manual retries, interrupted migration recovery, two-process repair locking, safe enqueue failures, provider blocking, cancellation, and mocked read requests through final Telegram delivery. `--filter QueueSchemaTest` runs just the schema regressions.

## Admin UI Checks

Use the same disposable database configuration as PHPUnit. `php scripts/render-admin-fixture.php` renders the actual settings markup with local WordPress styles, synthetic audit/timing rows, and a long custom role label. It creates test data; never run it against a live database.

With Playwright installed under `.test-env/node_modules` and Edge available on Windows (Chromium elsewhere), run `node scripts/check-admin-ui.cjs`. The browser blocks all network requests and intercepts form submissions. Checks cover all six tabs at eight widths from 320 to 1920 pixels, consistent button styles and wrapping, 44px action targets, keyboard focus, disabled states, scrollable tables, and separation of provider testing from settings saving. Screenshots are written only under ignored `.test-env/` for visual review. This does not replace testing on actual mobile browsers or the live WordPress admin shell.

Repeat fixture generation with `TELEPILOT_TEST_QUEUE_REPAIR_FAILURE=1` to check the repair warning, safe reference ID and nonce-protected retry control at the same viewport sizes. Clear that environment variable before generating the healthy-state fixture.
