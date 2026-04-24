---
status: in-progress
retrofit_extensions:
  - "Frontend Context Endpoints"
---

# Version Management Specification

**Status**: in-progress
**Standards**: Nextcloud App Store API v1, OCP\App\IAppManager
**Feature tier**: MVP

## Purpose

Version management is the core capability of App Versions. It allows Nextcloud administrators to view all available versions of any installed app, select a specific version (older or newer), and install it — replacing the currently installed version. This enables rollback after broken updates, testing compatibility with specific versions, and controlled upgrades.

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

The system MUST query the Nextcloud App Store API to retrieve all available releases for a selected app. Versions MUST be filtered by compatibility with the current Nextcloud version and update channel.

#### Scenario: View versions for an app

- GIVEN an admin selects app "OpenRegister" from the list
- WHEN the version list loads
- THEN the system MUST show all available versions from the app store
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

---

### Requirement: Frontend Context Endpoints [MVP]

The system MUST expose two thin read-only endpoints that the Vue UI calls at page-bootstrap time to contextualise what it renders. Both endpoints return a single scalar value wrapped in a JSON envelope; neither performs side effects. These are UI-support endpoints, not feature behaviours — the UI uses the values to decide which controls to render and how to label the version list, not to gate access (admin gating is enforced per-request on the feature endpoints themselves).

#### Scenario: UI reads caller admin status

- GIVEN any authenticated user (admin or not) loads the App Versions page
- WHEN the UI sends `GET /api/admin-check`
- THEN the system MUST return HTTP 200 with body `{"isAdmin": <bool>}`
- AND the boolean MUST reflect whether the caller is a member of the Nextcloud `admin` group
- AND the endpoint MUST NOT return 403 for non-admins — it is designed to be safely callable by anyone so the UI can branch on the result

#### Scenario: UI reads the server's update channel

- GIVEN an admin user has loaded the App Versions page
- WHEN the UI sends `GET /api/update-channel`
- THEN the system MUST return HTTP 200 with body `{"updateChannel": "<channel-id>"}`
- AND the channel id MUST be the value returned by `IServerVersion::getChannel()` (e.g. `stable`, `beta`, `daily`)

#### Scenario: Non-admin requests the update channel

- GIVEN a signed-in non-admin user
- WHEN they send `GET /api/update-channel`
- THEN the system MUST return HTTP 403 with body `{"message": "Forbidden"}`
- AND the server's channel MUST NOT be disclosed
- **Note**: the two endpoints differ deliberately on non-admin handling. `admin-check` stays 200 so the UI can branch; `update-channel` returns 403 because the channel is operationally sensitive and non-admins have no legitimate use for it.

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
