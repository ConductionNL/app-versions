# Tasks — release-installer integration tests

## Task 1: Add integration test bootstrap

- [ ] Create `tests/phpunit-integration.xml` mirroring `tests/phpunit.xml`
  with `testsuite name="integration"` rooted at `tests/integration/`.
- [ ] Reuse `tests/bootstrap.php` — no separate bootstrap.
- [ ] Add `composer test:integration` script calling
  `./vendor/bin/phpunit -c tests/phpunit-integration.xml`.
- [ ] Add `test:integration` to the `composer check:strict` composite
  script so `composer check:strict` fails when integration tests fail.

## Task 2: Record release fixture

- [ ] Under `tests/integration/fixtures/`, capture a pinned release
  archive of `notifications` (choose a specific version, e.g., `3.10.1`):
  - `release.tar.gz` — the archive bytes
  - `release.sig` — the base64 signature
  - `release.crt` — the PEM certificate
  - `release.json` — the release-metadata array the installer expects
- [ ] Add `tests/integration/fixtures/README.md` documenting the pinned
  version + refresh procedure.

## Task 3: Write `SelectedReleaseInstallerIntegrationTest`

- [ ] Create `tests/integration/Service/SelectedReleaseInstallerIntegrationTest.php`.
- [ ] Mock `IClientService` so `$client->get($downloadUrl, ['sink' => ...])`
  writes the fixture bytes to the sink path.
- [ ] Inject the other 9 services from the real NC test container.
- [ ] Add scenario 1: valid signed release → `dryRun: true` → assert
  `status === 'dry-run'` and debug log has all 7 expected stages.
- [ ] Add scenario 2: tampered signature → expect `Exception` with
  message `Release signature verification failed.`; debug log missing
  `signature-verified`.
- [ ] Add scenario 3: cert with non-matching CN → expect `Exception`
  about cert issued to wrong CN.

## Task 4: Wire CI

- [ ] Update `.github/workflows/code-quality.yml` to pass
  `enable-phpunit-integration: true` to the reusable workflow.
- [ ] If the reusable `ConductionNL/.github/.github/workflows/quality.yml`
  doesn't accept that input: add `.github/workflows/phpunit-integration.yml`
  as a dedicated job running `composer test:integration` on the same
  matrix as `code-quality.yml`.

## Task 5: Docs

- [ ] Add a section to `tests/integration/README.md` covering the
  PHPUnit integration suite (alongside the existing Newman section).
- [ ] Update `docs/adr-audit.md` — flip ADR-008
  `SelectedReleaseInstallerService` row from ⚠️ to ✅.
- [ ] Remove the follow-up from `docs/adr-audit.md`'s follow-ups list.
