# Tasks: add-external-source-installs

## Task 1: Source abstraction + AppStore source refactor
- **Spec ref**: specs/external-sources/spec.md (Requirement: Source abstraction)
- **Status**: todo
- **Acceptance criteria**:
  - `SourceInterface` defined with `getId()`, `listVersions(string $appId)`, `getRelease(string $appId, string $version)`
  - `AppStoreSource` implements the interface and contains the existing garm3.nextcloud.com lookup logic
  - `SourceRegistry` injected into `InstallerService`; `getAppVersions` and `installAppVersion` delegate to a registry-resolved source

## Task 2: GithubReleaseSource implementation
- **Spec ref**: specs/external-sources/spec.md (Requirement: GitHub releases as a source)
- **Status**: todo
- **Acceptance criteria**:
  - `GithubReleaseSource` queries `GET /repos/{owner}/{repo}/releases` (public, unauthenticated for this proposal)
  - Returns versions sorted newest-first, deduplicated, normalized to `['version' => '1.2.3']` shape
  - On API failure (rate limit, 404, network) returns empty list with logged warning, never throws upward

## Task 3: TrustedSourceList
- **Spec ref**: specs/external-sources/spec.md (Requirement: Trusted-source allowlist)
- **Status**: todo
- **Acceptance criteria**:
  - `TrustedSourceList::assertAllowed(string $sourceId)` throws `UntrustedSourceException` when no glob in `app_versions.trusted_sources` matches
  - Default value when unset = `["ConductionNL/*"]`
  - Accepts source IDs in form `github:owner/repo`; matches against `owner/repo` portion only

## Task 4: ExternalReleaseInstallerService
- **Spec ref**: specs/external-sources/spec.md (Requirement: External install integrity checks)
- **Status**: todo
- **Acceptance criteria**:
  - Service skips Nextcloud certificate + signature verification
  - Verifies appId match against extracted `appinfo/info.xml`
  - Verifies requested version matches extracted `appinfo/info.xml` version
  - Verifies optional `.sha256` sibling asset; sets `integrityWarning` flag when missing
  - Reuses backup-on-failure flow (maintenance mode wrap, rename-based backup, restore on exception)
  - Reuses `installAppLastSteps` for migrations and config writes

## Task 5: Source binding storage
- **Spec ref**: specs/version-management/spec.md (MODIFIED: Source binding)
- **Status**: todo
- **Acceptance criteria**:
  - On successful external install, `app_versions.source.{appId}` is written with `{kind, owner, repo, assetPattern, boundAt}`
  - `InstallerService::getAppVersions` queries the bound source first, falls back to App Store when unbound
  - Re-installing the same app from a different source overwrites the binding

## Task 6: API endpoints
- **Spec ref**: specs/external-sources/spec.md (Requirement: Source management API)
- **Status**: todo
- **Acceptance criteria**:
  - `GET /api/sources` returns `{sources: [...], trusted: [...]}` with the registry's source list and the trusted-source globs
  - `POST /api/source/{appId}/bind` binds a source (validated against allowlist)
  - `GET /api/app/{appId}/versions?source=github:owner/repo` queries the named source instead of the bound one
  - `POST /api/app/{appId}/versions/{version}/install` accepts an optional `source` body param; defaults to bound source or App Store

## Task 7: Tests
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - Unit tests for `TrustedSourceList::assertAllowed` covering: default allowlist, explicit allowlist, glob matching, non-matching source rejection
  - Unit tests for `GithubReleaseSource` with HTTP mocked: success path, 404 repo, rate-limit response, malformed payload
  - Unit tests for `ExternalReleaseInstallerService` covering: appId mismatch, version mismatch, missing `.sha256` warning path, present `.sha256` mismatch failure, successful install
  - Integration test for `InstallerService` source delegation: unbound = App Store, bound github = Github source
  - All `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)

## Task 8: Browser verification
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - Install `app-versions` into the running Nextcloud container
  - Verify existing App Store version-pick flow still works (regression check)
  - Verify external install of a public ConductionNL release succeeds and writes source binding
  - Verify external install from a non-allowlisted source fails with clear error before download
  - Verify install of a tampered archive (wrong appId) fails and restores backup
