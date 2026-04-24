# Release-installer integration tests

Closes one of the three partial items flagged in `docs/adr-audit.md` after
the template + ADR cleanup PR: `SelectedReleaseInstallerService` has no
PHPUnit coverage.

## Problem

`SelectedReleaseInstallerService` is the class that does the real work —
downloads the release archive, verifies the code-signing certificate,
extracts the tarball, runs migrations, re-registers bootstrap. Every
method depends on a Nextcloud internal (`OC\Archive\TAR`,
`OC\DB\MigrationService`, `\OC_App::*`, filesystem writes) or on the
live app store. A `createMock()`-style unit test cannot exercise it
meaningfully; the only honest coverage is integration.

ADR-008 lists "PHPUnit coverage per service / controller" as a mandatory
rule. The earlier PR landed 12 tests for `ApiController` and 3 for
`InstallerService`, but the installer's depth was deliberately deferred
— integration-test territory.

## Why now

Hydra's quality-recheck gate is lenient today because no tests exist —
regressions will only surface in manual QA. Once integration coverage
lands, a reviewer can run `composer test:integration` and see a red line
on real regressions (e.g., signature verification drift, archive
extraction layout changes).

## Scope

- New `tests/phpunit-integration.xml` configured to boot the Nextcloud
  test harness.
- New `tests/integration/Service/SelectedReleaseInstallerIntegrationTest.php`
  with dry-run scenarios (no live filesystem writes, no store calls).
- Compose-level scripts: `composer test:integration`, wired into
  `composer check:strict`.
- `code-quality.yml` wiring so CI runs the integration suite alongside
  the unit tests.
- `docs/accessibility.md` untouched — this change is pure backend testing.

## Not in scope

- `InstallerService` happy-path integration tests that require the real
  Nextcloud app store (flaky on CI with no egress). A separate Newman
  pass already covers the 5 read-side OCS endpoints.
- Any change to production behaviour.

## Acceptance

1. `composer test:integration` runs at least 3 scenarios against
   `SelectedReleaseInstallerService::installFromSelectedRelease` in
   dry-run mode.
2. Scenarios assert on the shape of the returned `debug` log (stages
   present, no write-side events in dry-run).
3. CI red when any scenario breaks; green today.
