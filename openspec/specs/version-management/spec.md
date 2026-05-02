---
status: implemented
---

# Version Management Specification

**Status**: implemented
**Standards**: Nextcloud App Store API v1, GitHub REST API v2022-11-28, OCP\App\IAppManager
**Feature tier**: MVP

## Purpose

Version management is the core capability of App Versions. It allows Nextcloud administrators to view all available versions of any installed app, select a specific version (older or newer), and install it — replacing the currently installed version. This enables rollback after broken updates, testing compatibility with specific versions, and controlled upgrades.

Each app may be bound to a single source (App Store or external such as GitHub releases) that is authoritative for its version listings. See the companion [external-sources spec](../external-sources/spec.md) for how external sources are validated and installed.

## Requirements

### Requirement: List Installed Apps [MVP]

The system MUST display all currently installed Nextcloud apps with their name, current version, description, and icon. Core/always-enabled apps SHOULD be visually distinguished but still listed.

#### Scenario: Admin views installed apps

- GIVEN an admin user opens App Versions
- WHEN the app list loads
- THEN all installed apps MUST be displayed as selectable cards
- AND each card MUST show: app name, current version, icon, and summary
- AND apps MUST be sorted alphabetically
- AND the App Versions app itself MUST be excluded from the list

#### Scenario: Non-admin user is blocked

- GIVEN a non-admin user navigates to App Versions
- WHEN the page loads
- THEN the system MUST show a "Forbidden" message
- AND no app data MUST be returned from the API

---

### Requirement: Fetch Available Versions [MVP]

The system MUST query the **bound source** for an app to retrieve all available releases. If no source is bound, the App Store is queried by default. Versions MUST be filtered by compatibility with the current Nextcloud version and update channel.

#### Scenario: Bound source is queried first

- GIVEN an admin selects app `openregister` (bound to `github:ConductionNL/openregister`)
- WHEN the version list loads
- THEN the system MUST fetch from `https://api.github.com/repos/ConductionNL/openregister/releases`
- AND the App Store MUST NOT be queried as a fallback in this request

#### Scenario: Unbound app falls through to App Store

- GIVEN an admin selects app `someapp` (no binding present)
- WHEN the version list loads
- THEN the system MUST fetch from the Nextcloud App Store endpoints

#### Scenario: View versions for an app

- GIVEN an admin selects app "OpenRegister" from the list
- WHEN the version list loads
- THEN the system MUST show all available versions from the bound source
- AND the currently installed version MUST be highlighted
- AND versions incompatible with the current Nextcloud version MUST be marked as incompatible
- AND each version MUST show: version number, release date, minimum NC version, maximum NC version

#### Scenario: App store API is unreachable

- GIVEN the Nextcloud App Store API is down or unreachable
- WHEN an admin tries to fetch versions
- THEN the system MUST show an error message "Could not fetch versions from the app store"
- AND the system MUST NOT crash or show a blank page

#### Scenario: Respect update channel

- GIVEN the Nextcloud instance is on the "stable" update channel
- WHEN fetching versions
- THEN beta/nightly releases SHOULD be filtered out or marked as non-stable

---

### Requirement: Source binding [MVP]

When an app is installed via the version manager, the source it was installed from MUST be persisted as a binding under app config key `source.{appId}`. Future version queries for that app MUST default to the bound source. Apps installed via Nextcloud's normal app-install flow (outside App Versions) have no binding and default to App Store.

#### Scenario: Install from App Store leaves no GitHub binding

- GIVEN an admin installs `someapp@1.2.0` from the App Store via App Versions
- WHEN the install completes
- THEN `app_versions.source.someapp` MUST either be unset or set to `{kind: "appstore"}`
- AND future version queries for `someapp` MUST hit the App Store

#### Scenario: Install from GitHub binds to that source

- GIVEN an admin installs `openregister@2.5.0` from `github:ConductionNL/openregister`
- WHEN the install completes
- THEN `app_versions.source.openregister` MUST be set to `{kind: "github-release", owner: "ConductionNL", repo: "openregister", assetPattern: "*.tar.gz", boundAt: ISO-8601-timestamp}`
- AND the next call to `GET /api/app/openregister/versions` MUST query the GitHub source, not the App Store

#### Scenario: Re-binding overwrites previous binding

- GIVEN `app_versions.source.openregister` is currently bound to `github:ConductionNL/openregister`
- WHEN the admin installs `openregister@2.5.0` from the App Store via the source-picker
- THEN `app_versions.source.openregister` MUST be updated to `{kind: "appstore"}`
- AND the next version query MUST hit the App Store

---

### Requirement: Explicit source override [MVP]

The version-list and install endpoints MUST accept an optional `source` parameter that overrides the bound source for that single request without changing the binding.

#### Scenario: One-off query without binding change

- GIVEN `openregister` is bound to `github:ConductionNL/openregister`
- WHEN the admin calls `GET /api/app/openregister/versions?source=appstore`
- THEN the response MUST contain App Store versions
- AND `app_versions.source.openregister` MUST remain unchanged

---

### Requirement: Install Specific Version [MVP]

The system MUST allow an admin to install any available version of an app, replacing the currently installed version. This operation MUST require password confirmation for security.

#### Scenario: Install an older version (rollback)

- GIVEN OpenRegister is currently at version 2.5.0
- WHEN the admin selects version 2.3.0 and confirms their password
- THEN the system MUST download version 2.3.0 from the app store
- AND replace the current app files with the downloaded version
- AND show a success message with the new version number
- AND the app MUST remain enabled after the version change

#### Scenario: Install a newer version (upgrade)

- GIVEN OpenRegister is currently at version 2.3.0 and version 2.5.0 is available
- WHEN the admin selects version 2.5.0 and confirms
- THEN the system MUST download and install version 2.5.0
- AND any database migrations for the new version MUST be triggered

#### Scenario: Installation fails

- GIVEN a download or extraction error occurs during installation
- WHEN the admin attempts to install a version
- THEN the system MUST show a clear error message
- AND the previous version MUST remain intact (no partial installs)
- AND the error MUST include actionable information (e.g., "Insufficient disk space" or "Download failed: HTTP 404")

#### Scenario: Password confirmation required

- GIVEN an admin clicks "Install" for a specific version
- WHEN the install action is triggered
- THEN the system MUST require password re-confirmation before proceeding
- AND the install MUST NOT proceed without valid password confirmation

---

### Requirement: Debug Mode [MVP]

The system SHOULD provide a debug mode that returns detailed installation logs for troubleshooting.

#### Scenario: Enable debug output

- GIVEN an admin enables the "Debug" toggle before installing
- WHEN the installation completes (success or failure)
- THEN the response MUST include detailed logs: download URL, file sizes, extraction steps, any warnings

## User Stories

1. As a Nextcloud admin, I want to roll back an app to a previous version so that I can recover from a broken update.
2. As a Nextcloud admin, I want to see all available versions of an installed app so that I can choose which version to install.
3. As a Nextcloud admin, I want to test a newer version of an app before it's auto-updated so that I can verify compatibility.
4. As a developer, I want to install a specific version of an app so that I can reproduce a bug reported on that version.
5. As a sysadmin, I want password confirmation before version changes so that unauthorized users can't modify app versions.

## Acceptance Criteria

- [ ] App list shows all installed apps with current version
- [ ] Non-admin users see "Forbidden" and cannot access any API
- [ ] Version list shows all releases from app store with compatibility info
- [ ] Currently installed version is highlighted in the list
- [ ] Installing an older version works (rollback)
- [ ] Installing a newer version works (upgrade)
- [ ] Password confirmation is required before install
- [ ] Graceful error handling when app store is unreachable
- [ ] Graceful error handling when download/install fails
- [ ] Debug mode returns detailed logs
- [ ] App Versions itself is excluded from the manageable apps list

## Notes

- The app uses the Nextcloud App Store API at `https://apps.nextcloud.com/api/v1/apps/{appId}/releases`
- Version compatibility is determined by comparing the release's `minNextcloudVersion`/`maxNextcloudVersion` against `OCP\ServerVersion`
- The `SelectedReleaseInstallerService` handles the actual download + extraction, monkey-patching the Nextcloud installer to target a specific version
- This app does NOT handle database migration rollbacks — rolling back to an older version may leave newer DB migrations in place
