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

## Admin UI Checks

Use the same disposable database configuration as PHPUnit. `php scripts/render-admin-fixture.php` renders the actual settings markup with local WordPress styles, synthetic audit/timing rows, and a long custom role label. It creates test data; never run it against a live database.

With Playwright installed under `.test-env/node_modules` and Edge available on Windows (Chromium elsewhere), run `node scripts/check-admin-ui.cjs`. The browser blocks all network requests and intercepts form submissions. Checks cover all six tabs at eight widths from 320 to 1920 pixels, consistent button styles and wrapping, 44px action targets, keyboard focus, disabled states, scrollable tables, and separation of provider testing from settings saving. Screenshots are written only under ignored `.test-env/` for visual review. This does not replace testing on actual mobile browsers or the live WordPress admin shell.
