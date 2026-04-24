# Delta — Install Specific Version (integration-test coverage)

Scopes to the existing `Install Specific Version` requirement. Adds a
single scenario that locks in integration-test coverage for the
dry-run path.

### Requirement: Install Specific Version [MVP]

**MODIFIED**: adds a new scenario below. No change to existing scenarios.

#### Scenario: Dry-run install is covered by integration tests

- GIVEN a recorded release fixture (tarball + signature + certificate)
  pinned under `tests/integration/fixtures/`
- WHEN the test injects a stubbed `IClientService` that returns the
  fixture bytes
- AND calls `SelectedReleaseInstallerService::installFromSelectedRelease($appId, $release, dryRun: true)`
- THEN the service MUST return `{ status: 'dry-run', dryRun: true }`
- AND the `debug` array MUST contain the stages `certificate-validated`,
  `downloaded`, `archive-extracted`, `info-xml`, `signature-verified`,
  `destination`, `dry-run-skip-filesystem`
- AND no filesystem writes MUST occur (the `*.appversion-backup`
  temporary path MUST be absent at end of test)
- AND a tampered signature MUST cause the service to throw an
  `Exception` with message "Release signature verification failed."
  before any filesystem write
