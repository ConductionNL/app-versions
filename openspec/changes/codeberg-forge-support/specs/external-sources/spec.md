## ADDED Requirements

### Requirement: Forge abstraction [MVP]

The system MUST model each supported release forge as a `Forge` configuration entry carrying `id`, `apiBaseUrl`, `webBaseUrl`, `authScheme` (`Bearer` or `token`), `exposesScopeHeader` (bool), and `tokenCreateUrl`, exposed through a `ForgeRegistry`. The generic release driver and the token validator MUST read forge behaviour from this configuration rather than hard-coding GitHub.

#### Scenario: Known forges registered

- **GIVEN** the `ForgeRegistry`
- **WHEN** `get('github')` and `get('codeberg')` are called
- **THEN** `github` MUST resolve to apiBaseUrl `https://api.github.com`, authScheme `Bearer`, exposesScopeHeader `true`, tokenCreateUrl `https://github.com/settings/tokens`
- **AND** `codeberg` MUST resolve to apiBaseUrl `https://codeberg.org/api/v1`, authScheme `token`, exposesScopeHeader `false`, tokenCreateUrl `https://codeberg.org/user/settings/applications`

#### Scenario: Unknown forge rejected

- **GIVEN** the `ForgeRegistry`
- **WHEN** `get('gitlab')` is called
- **THEN** the registry MUST throw, so unknown forges cannot reach an outbound call

### Requirement: Codeberg releases as a source [MVP]

The system MUST support querying public Codeberg (Forgejo) releases as a source. For a source id of the form `codeberg:{owner}/{repo}`, the system queries `GET https://codeberg.org/api/v1/repos/{owner}/{repo}/releases` and normalizes the Forgejo response (`tag_name`, `assets[].name`, `assets[].browser_download_url`) into the same shape as GitHub and App Store responses.

#### Scenario: Public Codeberg releases listed via Forgejo API

- **GIVEN** an admin has bound app `openregister` to source `codeberg:Conduction/openregister`
- **WHEN** the version picker loads
- **THEN** the system MUST fetch releases from `https://codeberg.org/api/v1/repos/Conduction/openregister/releases`
- **AND** version strings MUST be derived from the release `tag_name` (stripping a leading `v` if present)
- **AND** the response MUST be sorted newest-first
- **AND** for a public repo the request MUST succeed unauthenticated

#### Scenario: Codeberg release resolved to a download asset

- **GIVEN** a Codeberg release `v2.5.0` with a single `*.tar.gz` asset
- **WHEN** the install resolves the release
- **THEN** the system MUST return the asset's `browser_download_url`, asset name, optional `.sha256` sibling URL, and normalized version
- **AND** asset selection MUST enforce the same unambiguous-match rule as GitHub (fail on multiple matches with "set explicit assetPattern")

#### Scenario: Codeberg auth header uses token scheme

- **GIVEN** a matching access token resolves for `codeberg:Conduction/private-build`
- **WHEN** the release listing runs
- **THEN** the request MUST carry `Authorization: token <token>` (not `Bearer`)

## MODIFIED Requirements

### Requirement: GitHub releases as a source [MVP]

The system MUST support querying public releases from any registered forge through a single generic driver (`ForgeReleaseSource`). For a source id of the form `{forge}:{owner}/{repo}`, the system queries `GET {forge.apiBaseUrl}/repos/{owner}/{repo}/releases` and normalizes the response into the same shape as App Store responses. For `forge = github` the behaviour MUST be identical to the prior GitHub-only driver (`https://api.github.com/...`, `Authorization: Bearer`). The installer kind MUST remain `INSTALLER_EXTERNAL`.

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
- **AND** the API response to the frontend MUST include a clear message ("GitHub rate limit exceeded — try again later, or configure a PAT")
- **AND** the system MUST NOT crash or expose stack traces

#### Scenario: Repository not found

- **GIVEN** the forge API responds with 404 for the repo
- **WHEN** the version picker loads
- **THEN** the system MUST return an empty version list with a "Repository not found" message

#### Scenario: GitHub behaviour preserved under generic driver

- **GIVEN** the driver is now `ForgeReleaseSource` parameterized by the `github` forge
- **WHEN** a `github:owner/repo` binding lists versions
- **THEN** the request URL, headers (`Authorization: Bearer`, `X-GitHub-Api-Version`), parsing, dedupe and sort MUST be unchanged from the prior GitHub-only behaviour

### Requirement: Trusted-source allowlist [MVP]

The system MUST reject external installs from sources not in the configured allowlist. Allowlist patterns are **forge-qualified** globs of the form `{forge}:owner/repo` (e.g. `github:ConductionNL/*`, `codeberg:Conduction/*`), stored in `app_versions.trusted_sources` as a JSON array. Matching is performed against the binding's `{forge}:owner/repo` id. A stored pattern with **no** forge prefix (legacy bare `owner/repo`) MUST be treated as `github:owner/repo`. When unset, the default is `["github:ConductionNL/*", "codeberg:Conduction/*"]`. The existing fnmatch and owner/repo path-traversal protections MUST be preserved.

#### Scenario: Forge-qualified source in allowlist

- **GIVEN** trusted_sources is `["github:ConductionNL/*", "codeberg:Conduction/*"]`
- **WHEN** an admin tries to install from `github:ConductionNL/openregister`
- **THEN** `TrustedSourceList::assertAllowed` MUST succeed
- **WHEN** an admin tries to install from `codeberg:Conduction/openregister`
- **THEN** `TrustedSourceList::assertAllowed` MUST succeed

#### Scenario: Untrusted Codeberg source rejected

- **GIVEN** trusted_sources is `["github:ConductionNL/*", "codeberg:Conduction/*"]`
- **WHEN** an admin tries to install from `codeberg:randomuser/randomapp`
- **THEN** `TrustedSourceList::assertAllowed` MUST throw `UntrustedSourceException`
- **AND** the system MUST return HTTP 403 with a message naming the rejected source
- **AND** no download or filesystem change MUST happen

#### Scenario: Legacy bare pattern treated as github

- **GIVEN** trusted_sources is the legacy bare `["ConductionNL/*"]`
- **WHEN** an admin tries to install from `github:ConductionNL/openregister`
- **THEN** the pattern MUST be interpreted as `github:ConductionNL/*` and the source MUST be allowed
- **AND** `codeberg:ConductionNL/openregister` MUST NOT match the bare github-only pattern

#### Scenario: Cross-forge isolation

- **GIVEN** trusted_sources is `["github:Conduction/*"]`
- **WHEN** an admin tries to install from `codeberg:Conduction/openregister`
- **THEN** `TrustedSourceList::assertAllowed` MUST throw `UntrustedSourceException` (a github pattern does not authorize a codeberg source)

#### Scenario: Unset allowlist falls back to default

- **GIVEN** `app_versions.trusted_sources` has never been set
- **WHEN** the system reads the allowlist
- **THEN** the default `["github:ConductionNL/*", "codeberg:Conduction/*"]` MUST be used

### Requirement: Source management API [MVP]

The system MUST provide HTTP endpoints for listing registered sources, the trusted-source allowlist, and binding a source to an app. A source binding MUST carry a `forge` discriminator (`github` | `codeberg`, default `github`); its canonical id MUST be `{forge}:owner/repo`. Persisted legacy bindings without a `forge` field MUST load as `forge = github`, and the `github:owner/repo` id MUST stay identical to its prior value. `listAvailable()` MUST include both a GitHub and a Codeberg entry. `parseSourceId()` MUST parse `github:owner/repo` and `codeberg:owner/repo` and reject unknown forge prefixes.

#### Scenario: List sources includes Codeberg

- **GIVEN** an admin calls `GET /api/sources`
- **THEN** the response MUST contain registered source ids including a GitHub entry and a Codeberg entry, plus the forge-qualified trusted-source globs
- **AND** the response MUST NOT contain any secrets

#### Scenario: Bind a Codeberg source

- **GIVEN** an admin calls `POST /api/source/openregister/bind` with body `{kind: "github-release", forge: "codeberg", owner: "Conduction", repo: "openregister"}`
- **THEN** the system MUST validate the source id `codeberg:Conduction/openregister` against the forge-qualified allowlist
- **AND** persist the binding (including `forge`) in `app_versions.source.openregister`
- **AND** future version queries for `openregister` MUST go to the Codeberg forge via `ForgeReleaseSource`

#### Scenario: Parse a codeberg source id

- **GIVEN** the source id `codeberg:Conduction/openregister`
- **WHEN** `SourceRegistry::parseSourceId` runs
- **THEN** it MUST return a binding with `forge = codeberg`, owner `Conduction`, repo `openregister`
- **AND** an id with an unknown forge prefix (e.g. `gitlab:o/r`) MUST throw `InvalidArgumentException`

#### Scenario: Legacy github binding still works (backward compat)

- **GIVEN** a persisted binding `{kind: "github-release", owner: "ConductionNL", repo: "openregister"}` with no `forge` field
- **WHEN** the binding is loaded via `SourceBinding::fromArray`
- **THEN** `getForge()` MUST return `github`
- **AND** `getId()` MUST return `github:ConductionNL/openregister` (unchanged)
- **AND** version listing MUST resolve to the github forge exactly as before

#### Scenario: Bind rejects untrusted source

- **GIVEN** an admin calls `POST /api/source/foo/bind` with `forge: "codeberg", owner: "untrusted"`
- **THEN** the system MUST return HTTP 403
- **AND** the binding MUST NOT be written
