## ADDED Requirements

### Requirement: Trusted-allowlist management API [MVP]

The system MUST provide admin-only, password-confirmed endpoints to curate the trusted-source allowlist by adding and removing forge-qualified patterns, delegating persistence to `TrustedSourceList::setPatterns()`. The system MUST reject over-broad patterns that would trust an entire forge or everything.

#### Scenario: Curated add persists

- **GIVEN** an admin calls `POST /api/trusted-sources` with `{forge: "codeberg", owner: "Conduction", repo: "openregister"}` and a confirmed password
- **THEN** the system MUST construct the pattern `codeberg:Conduction/openregister`
- **AND** persist it into the allowlist via `TrustedSourceList::setPatterns()`
- **AND** a subsequent `GET /api/sources` MUST return `codeberg:Conduction/openregister` in `trustedPatterns`

#### Scenario: Curated add of an owner wildcard

- **GIVEN** an admin calls `POST /api/trusted-sources` with `{forge: "github", owner: "ConductionNL"}` (no `repo`) and a confirmed password
- **THEN** the system MUST construct the pattern `github:ConductionNL/*`
- **AND** persist it into the allowlist

#### Scenario: Over-broad glob rejected

- **GIVEN** an admin calls `POST /api/trusted-sources` with a payload that would yield `*`, `*/*`, `{forge}:*`, an empty owner, or an owner of exactly `*`
- **THEN** the system MUST NOT modify the allowlist
- **AND** the system MUST return HTTP 400 (or 422) with a clear message stating a concrete owner is required

#### Scenario: Unknown forge or bad charset rejected

- **GIVEN** an admin calls `POST /api/trusted-sources` with an unknown `forge`, or an `owner`/`repo` that does not match the safe charset `[A-Za-z0-9_.\-]+`
- **THEN** the system MUST NOT modify the allowlist
- **AND** the system MUST return HTTP 400 with a message naming the rejected forge or invalid characters

#### Scenario: Remove a pattern

- **GIVEN** the allowlist contains `codeberg:Conduction/openregister`
- **WHEN** an admin calls `DELETE /api/trusted-sources?pattern=codeberg%3AConduction%2Fopenregister` (pattern as a query parameter) and a confirmed password
- **THEN** the system MUST remove exactly that pattern and persist the result
- **AND** a subsequent `GET /api/sources` MUST NOT return that pattern in `trustedPatterns`

#### Scenario: Non-admin forbidden

- **GIVEN** a non-admin user calls `POST /api/trusted-sources` or `DELETE /api/trusted-sources?pattern=…`
- **THEN** the system MUST return HTTP 403 Forbidden
- **AND** the allowlist MUST remain unchanged

### Requirement: Source binding UI surfacing [MVP]

The admin UI MUST surface source binding so an admin can view an app's current binding and bind a forge repository to it, for both github and codeberg forges, using the existing `GET /api/source/{appId}/binding` and `POST /api/source/{appId}/bind` endpoints.

#### Scenario: Bind a Codeberg repo via the UI

- **GIVEN** an admin opens the Sources tab and selects an installed app
- **WHEN** the admin chooses forge `codeberg`, enters owner `Conduction` and repo `openregister`, and submits
- **THEN** the UI MUST call `POST /api/source/{appId}/bind` with the forge-qualified binding
- **AND** on success the version list for that app MUST load from the bound Codeberg source

#### Scenario: Current binding displayed

- **GIVEN** an app is bound to `github:ConductionNL/openregister`
- **WHEN** the admin opens the Sources tab for that app
- **THEN** the UI MUST display the current binding (forge, owner, repo) from `GET /api/source/{appId}/binding`

#### Scenario: Allowlist surfaced for both forges

- **GIVEN** the allowlist contains `github:ConductionNL/*` and `codeberg:Conduction/*`
- **WHEN** the admin opens the Trusted sources tab
- **THEN** the UI MUST list both forge-qualified patterns

## MODIFIED Requirements

### Requirement: Source management API [MVP]

The system MUST provide HTTP endpoints for listing registered sources and the trusted-source allowlist, binding a source to an app, and **curating the trusted-source allowlist** (adding and removing forge-qualified patterns). Allowlist write operations MUST be admin-only and password-confirmed and MUST reject over-broad patterns.

#### Scenario: List sources

- **GIVEN** an admin calls `GET /api/sources`
- **THEN** the response MUST contain the registered source ids (including both github and codeberg forges) and the trusted-source patterns
- **AND** the response MUST NOT contain any secrets

#### Scenario: Bind a source

- **GIVEN** an admin calls `POST /api/source/openregister/bind` with body `{forge: "github", kind: "github-release", owner: "ConductionNL", repo: "openregister"}`
- **THEN** the system MUST validate the source against the trusted-source allowlist
- **AND** persist the binding in `app_versions.source.openregister`
- **AND** future version queries for `openregister` MUST go to that forge source

#### Scenario: Bind rejects untrusted source

- **GIVEN** an admin calls `POST /api/source/foo/bind` with `owner: "untrusted"`
- **THEN** the system MUST return HTTP 403
- **AND** the binding MUST NOT be written

#### Scenario: Allowlist write delegates to TrustedSourceList

- **GIVEN** an admin curates the allowlist via `POST /api/trusted-sources` or `DELETE /api/trusted-sources?pattern=…`
- **THEN** the system MUST persist the change through `TrustedSourceList::setPatterns()`
- **AND** the change MUST survive a Nextcloud restart (config-backed via `IAppConfig`)
