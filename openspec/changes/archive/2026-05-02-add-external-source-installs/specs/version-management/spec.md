---
status: proposed
---

# Version Management — Source Binding Delta

This delta extends the existing [version-management spec](../../../../specs/version-management/spec.md) with the concept of **bound sources**: every installed app may be associated with a single source (App Store or external) that is authoritative for its versions.

## ADDED Requirements

### Requirement: Source binding [MVP]

When an app is installed via the version manager, the source it was installed from MUST be persisted as a binding. Future version queries for that app MUST default to the bound source. Apps installed via Nextcloud's normal app-install flow (outside App Versions) have no binding and default to App Store.

#### Scenario: Install from App Store leaves no GitHub binding

- **GIVEN** an admin installs `someapp@1.2.0` from the App Store via App Versions
- **WHEN** the install completes
- **THEN** `app_versions.source.someapp` MUST either be unset or set to `{kind: "appstore"}`
- **AND** future version queries for `someapp` MUST hit the App Store

#### Scenario: Install from GitHub binds to that source

- **GIVEN** an admin installs `openregister@2.5.0` from `github:ConductionNL/openregister`
- **WHEN** the install completes
- **THEN** `app_versions.source.openregister` MUST be set to `{kind: "github-release", owner: "ConductionNL", repo: "openregister", assetPattern: "*.tar.gz", boundAt: ISO-8601-timestamp}`
- **AND** the next call to `GET /api/app/openregister/versions` MUST query the GitHub source, not the App Store

#### Scenario: Re-binding overwrites previous binding

- **GIVEN** `app_versions.source.openregister` is currently bound to `github:ConductionNL/openregister`
- **WHEN** the admin installs `openregister@2.5.0` from the App Store via the source-picker
- **THEN** `app_versions.source.openregister` MUST be updated to `{kind: "appstore"}`
- **AND** the next version query MUST hit the App Store

### Requirement: Explicit source override [MVP]

The version-list and install endpoints MUST accept an optional `source` parameter that overrides the bound source for that single request without changing the binding.

#### Scenario: One-off query without binding change

- **GIVEN** `openregister` is bound to `github:ConductionNL/openregister`
- **WHEN** the admin calls `GET /api/app/openregister/versions?source=appstore`
- **THEN** the response MUST contain App Store versions
- **AND** `app_versions.source.openregister` MUST remain unchanged

## MODIFIED Requirements

### Requirement: Fetch Available Versions [MVP]

(Replaces the same-named requirement in the canonical spec.) The system MUST query the **bound source** for an app to retrieve all available releases. If no source is bound, the App Store is queried (preserving current behaviour).

#### Scenario: Bound source is queried first

- **GIVEN** an admin selects app `openregister` (bound to `github:ConductionNL/openregister`)
- **WHEN** the version list loads
- **THEN** the system MUST fetch from `https://api.github.com/repos/ConductionNL/openregister/releases`
- **AND** the App Store MUST NOT be queried as a fallback in this request

#### Scenario: Unbound app falls through to App Store

- **GIVEN** an admin selects app `someapp` (no binding present)
- **WHEN** the version list loads
- **THEN** the system MUST fetch from the Nextcloud App Store endpoints (current behaviour)
