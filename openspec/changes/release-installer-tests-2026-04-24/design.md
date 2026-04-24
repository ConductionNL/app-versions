# Design — release-installer integration tests

## Approach

Extend the existing PHPUnit setup with a second configuration file that
loads a fuller Nextcloud test bootstrap. Unit tests (`tests/phpunit.xml`)
stay fast + isolated; integration tests (`tests/phpunit-integration.xml`)
load the NC test harness before the test class runs.

The integration test exercises `installFromSelectedRelease` in
**dry-run mode** only. Dry-run returns a structured debug log describing
what the installer *would* do, without touching the filesystem or
running migrations. That debug log is the assertion surface.

## Fixture strategy

A dry-run install needs:
- An `$appId` that exists on the server (so `getAppVersion` /
  `getAppPath` don't throw).
- A `$release` array with a `download` URL, `signature`, `certificate`,
  `version`.

For the URL + signature we use a **recorded fixture** — a pinned release
of `notifications` (stable, always installed) captured once, stored
under `tests/integration/fixtures/`. The test patches `IClientService`
to return the fixture bytes, so there's no network dependency.

Rationale: `SelectedReleaseInstallerService` accepts `IClientService` via
constructor DI (post-cleanup), so swapping in a test double is a
one-liner. The certificate chain lives at `\OC::$SERVERROOT/resources/`
in the test NC install — real cryptographic verification runs against
real chain bytes.

## Scenarios

1. **Dry-run: valid signed release**
   - Arrange: real NC cert chain, recorded release + signature.
   - Act: `installFromSelectedRelease($appId, $release, dryRun: true)`.
   - Assert: return `['status' => 'dry-run', 'dryRun' => true]`; debug log
     contains `certificate-validated`, `downloaded`, `archive-extracted`,
     `info-xml`, `signature-verified`, `destination`,
     `dry-run-skip-filesystem`. No filesystem writes (backup dir absent).

2. **Dry-run: signature tampered**
   - Arrange: fixture with `signature` base64 altered by one byte.
   - Act / Assert: `Exception` thrown with message
     `Release signature verification failed.` Debug log contains
     `certificate-validated` + `archive-extracted` but not
     `signature-verified`.

3. **Dry-run: cert revoked**
   - Arrange: fixture with a revoked cert serial (use the NC-bundled
     test-revoked cert if one exists; otherwise skip this scenario and
     substitute a cert with a non-matching CN).
   - Act / Assert: `Exception` with the right message; debug log shape
     reflects where verification failed.

## Rejected alternatives

- **Full mock-based unit tests**: considered and rejected. Mocking `TAR`,
  `MigrationService`, and the NC filesystem stack devolves into testing
  the mocks themselves, not the installer.
- **Live app store calls**: considered and rejected. CI doesn't have
  reliable egress to `garm3.nextcloud.com`; flake cost > signal.

## CI wiring

`code-quality.yml` today calls the reusable `ConductionNL/.github`
quality workflow with `enable-phpunit: true`. That reusable workflow
already supports an integration phase — we enable it via a new input:

```yaml
jobs:
  quality:
    uses: ConductionNL/.github/.github/workflows/quality.yml@main
    with:
      ...
      enable-phpunit-integration: true
```

If the reusable workflow doesn't yet support the flag, this change
falls back to a dedicated `phpunit-integration.yml` in the app's own
workflows directory. Design preference: extend the reusable first, open
a follow-up on `ConductionNL/.github` if needed.

## Risk

- Fixture bitrot: if the recorded `notifications` release's tar layout
  or signature format changes upstream, the fixture stops matching.
  Mitigation: the fixture is version-pinned; a dependabot-style
  refresh bumps it deliberately. Document the refresh procedure in
  `tests/integration/README.md`.
- Test-harness boot time: `tests/phpunit-integration.xml` loads
  `tests/bootstrap.php`, which imports the full NC framework. ~5s
  per run. Acceptable.
