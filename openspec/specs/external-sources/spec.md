---
status: implemented
---

# External Sources Specification

**Status**: proposed
**Standards**: GitHub REST API v2022-11-28 (releases endpoint), Nextcloud OCP\App\IAppManager
**Feature tier**: MVP

## Purpose

External sources allow App Versions to install Nextcloud apps from origins outside the Nextcloud App Store — most importantly GitHub releases — while keeping the App Store install path with its full code-signing chain unchanged. The trade-off (no Nextcloud-issued certificate) is made visible through a trusted-source allowlist, archive-content integrity checks, and clear UI labelling.

## ADDED Requirements

### Requirement: Source abstraction [MVP]

The system MUST expose every install origin (App Store, GitHub releases, future others) through a single `SourceInterface` so the version picker and installer are not hard-coded to one source.

#### Scenario: Multiple sources registered

- **GIVEN** the app has a `SourceRegistry` with `appstore` and `github` sources registered
- **WHEN** an admin opens the version picker for an app
- **THEN** the registry MUST be able to list versions from any registered source by id
- **AND** swapping the source MUST NOT require changes to the controller layer

### Requirement: GitHub releases as a source [MVP]

The system MUST support querying public GitHub releases as a source. For a source id of the form `github:{owner}/{repo}`, the system queries `GET https://api.github.com/repos/{owner}/{repo}/releases` and normalizes the response into the same shape as App Store responses.

#### Scenario: Public releases available

- **GIVEN** an admin has bound app `openregister` to source `github:ConductionNL/openregister`
- **WHEN** the version picker loads
- **THEN** the system MUST fetch releases from `https://api.github.com/repos/ConductionNL/openregister/releases`
- **AND** version strings MUST be derived from the release `tag_name` (stripping a leading `v` if present)
- **AND** the response MUST be sorted newest-first

#### Scenario: GitHub API rate-limited

- **GIVEN** the GitHub API responds with status 403 and `X-RateLimit-Remaining: 0`
- **WHEN** the system tries to list versions
- **THEN** the system MUST log the rate-limit reset time
- **AND** the API response to the frontend MUST include a clear message ("GitHub rate limit exceeded — try again at HH:MM, or configure a PAT in proposal 2")
- **AND** the system MUST NOT crash or expose stack traces

#### Scenario: Repository not found

- **GIVEN** the GitHub API responds with 404 for the repo
- **WHEN** the version picker loads
- **THEN** the system MUST return an empty version list with a "Repository not found" message

### Requirement: Trusted-source allowlist [MVP]

The system MUST reject external installs from sources not in the configured allowlist. The allowlist is stored in `app_versions.trusted_sources` as a JSON array of `owner/repo` glob patterns. When unset, the default is `["ConductionNL/*"]`.

#### Scenario: Source in allowlist

- **GIVEN** trusted_sources is `["ConductionNL/*"]`
- **WHEN** an admin tries to install from `github:ConductionNL/openregister`
- **THEN** `TrustedSourceList::assertAllowed` MUST succeed
- **AND** the install MUST proceed

#### Scenario: Source not in allowlist

- **GIVEN** trusted_sources is `["ConductionNL/*"]`
- **WHEN** an admin tries to install from `github:randomuser/randomapp`
- **THEN** `TrustedSourceList::assertAllowed` MUST throw `UntrustedSourceException`
- **AND** the system MUST return HTTP 403 with a message naming the rejected source
- **AND** no download or filesystem change MUST happen

#### Scenario: Unset allowlist falls back to default

- **GIVEN** `app_versions.trusted_sources` has never been set
- **WHEN** the system reads the allowlist
- **THEN** the default `["ConductionNL/*"]` MUST be used

#### Scenario: Glob matching

- **GIVEN** trusted_sources is `["ConductionNL/*", "myorg/myapp"]`
- **THEN** `github:ConductionNL/anything` MUST match
- **AND** `github:ConductionNL` (no repo) MUST NOT match
- **AND** `github:myorg/myapp` MUST match
- **AND** `github:myorg/otherapp` MUST NOT match

### Requirement: External install integrity checks [MVP]

The system MUST verify the integrity of an externally-sourced artifact before install through non-cryptographic content checks plus an optional cryptographic checksum.

#### Scenario: appId match enforced

- **GIVEN** the admin requests install of `openregister` from a GitHub release
- **WHEN** the downloaded archive is extracted and contains `appinfo/info.xml` declaring `<id>otherapp</id>`
- **THEN** the install MUST fail with a clear message ("Downloaded archive declares appId 'otherapp', expected 'openregister'")
- **AND** the existing app MUST be untouched (no backup performed if appId check fails before backup, or backup restored if it failed after)

#### Scenario: Version match enforced

- **GIVEN** the admin requests install of `openregister@2.5.0` from GitHub release `v2.5.0`
- **WHEN** the extracted `appinfo/info.xml` declares `<version>2.4.0</version>`
- **THEN** the install MUST fail with "Downloaded archive declares version '2.4.0', expected '2.5.0'"

#### Scenario: SHA-256 verification when provided

- **GIVEN** the GitHub release has both `openregister-2.5.0.tar.gz` and `openregister-2.5.0.tar.gz.sha256` assets
- **WHEN** the system downloads the archive
- **THEN** the system MUST also fetch the `.sha256` file
- **AND** compare against the SHA-256 of the downloaded archive
- **AND** fail the install if the hashes do not match

#### Scenario: Missing SHA-256 produces warning, not failure

- **GIVEN** the GitHub release has only `openregister-2.5.0.tar.gz` (no `.sha256` sibling)
- **WHEN** the install proceeds
- **THEN** the install MUST succeed if other checks pass
- **AND** the install response payload MUST include `integrityWarning: "No SHA-256 checksum available for this artifact."`

#### Scenario: Asset selection unambiguous

- **GIVEN** a GitHub release exposes two `.tar.gz` assets ("openregister-2.5.0.tar.gz" and "openregister-2.5.0-debug.tar.gz")
- **WHEN** the install runs without a configured `assetPattern`
- **THEN** the install MUST fail with "Multiple matching assets, set explicit assetPattern"
- **AND** no download MUST happen

### Requirement: Source management API [MVP]

The system MUST provide HTTP endpoints for listing registered sources, the trusted-source allowlist, and binding a source to an app.

#### Scenario: List sources

- **GIVEN** an admin calls `GET /api/sources`
- **THEN** the response MUST contain the registered source ids and the trusted-source globs
- **AND** the response MUST NOT contain any secrets

#### Scenario: Bind a source

- **GIVEN** an admin calls `POST /api/source/openregister/bind` with body `{kind: "github-release", owner: "ConductionNL", repo: "openregister"}`
- **THEN** the system MUST validate the source against the trusted-source allowlist
- **AND** persist the binding in `app_versions.source.openregister`
- **AND** future version queries for `openregister` MUST go to that GitHub source

#### Scenario: Bind rejects untrusted source

- **GIVEN** an admin calls `POST /api/source/foo/bind` with `owner: "untrusted"`
- **THEN** the system MUST return HTTP 403
- **AND** the binding MUST NOT be written

## User Stories

1. As a Conduction admin, I want to install Conduction apps directly from GitHub so I can roll back to a version that the App Store has already removed.
2. As a Conduction admin, I want a guarantee that I cannot accidentally install code from a third-party GitHub repo without explicitly allowlisting it first.
3. As a developer, I want to test a pre-release of an app from GitHub before it ships to the App Store.

## Acceptance Criteria

- [ ] `SourceRegistry` resolves both `appstore` and `github:owner/repo` source ids
- [ ] `GithubReleaseSource` lists versions from public GitHub repos
- [ ] `TrustedSourceList` defaults to `["ConductionNL/*"]` and rejects out-of-list sources before download
- [ ] External installs verify appId and version against the extracted `appinfo/info.xml`
- [ ] SHA-256 verification runs when `.sha256` sibling is present, and surfaces a warning when missing
- [ ] Source binding survives Nextcloud restart (persisted via `IConfig::setAppValue`)
- [ ] App Store install path (`SelectedReleaseInstallerService`) is unchanged
- [ ] All `composer check:strict` passes; PHPUnit suite passes

## Notes

- This spec governs the install **mechanism**. The discovery / search UI for finding apps to install lives in proposal 3 (`add-app-discovery-search`).
- Private-repo support and PAT management live in proposal 2 (`add-github-pat-management`). For this proposal, only public GitHub releases are supported.
- Future hardening work: auto-pin observed SHA-256 to the binding so a maintainer rewriting the GitHub release cannot ship altered bytes silently. Tracked as a TODO; not in this proposal's MVP.
