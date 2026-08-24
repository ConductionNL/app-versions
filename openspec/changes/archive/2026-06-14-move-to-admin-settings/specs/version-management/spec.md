---
status: proposed
---

# Version Management — Delta Specification

**Status**: proposed
**Modifies**: openspec/specs/version-management/spec.md

## Purpose

This delta relocates the App Versions UI from a top-level navigation entry to a
Nextcloud admin Settings section, and updates the access-control requirement to
reflect that admin-only enforcement is now structural (framework-level) rather
than purely API-layer. UI placement/registration is folded into the
version-management capability rather than a separate spec.

## MODIFIED Requirements

### Requirement: List Installed Apps [MVP]

The system MUST display all currently installed Nextcloud apps with their name, current version, description, and icon. Core/always-enabled apps SHOULD be visually distinguished but still listed.

#### Scenario: Admin views installed apps

- GIVEN an admin user opens App Versions via Settings → Administration → App Versions
- WHEN the app list loads
- THEN all installed apps MUST be displayed as selectable cards
- AND each card MUST show: app name, current version, icon, and summary
- AND apps MUST be sorted alphabetically
- AND the App Versions app itself MUST be excluded from the list

#### Scenario: Non-admin user is blocked

- GIVEN a non-admin user
- WHEN they open Settings
- THEN the Administration section (and therefore App Versions) SHALL NOT be visible to them at the framework level
- AND there MUST be no top-level navigation entry or page route through which they could reach the UI
- AND if the user calls any App Versions API endpoint directly
- THEN the system MUST return a "Forbidden" response
- AND no app data MUST be returned from the API

## ADDED Requirements

### Requirement: Admin Settings Placement [MVP]

The App Versions UI MUST be surfaced exclusively as a section in the Nextcloud Administration settings panel, registered via `appinfo/info.xml` `<settings>` using an `OCP\Settings\IIconSection` (the sidebar section) and an `OCP\Settings\ISettings` (the form body). The previous top-level `<navigations>` entry MUST be removed, and the standalone page route (`PageController` / `FrontpageRoute`) MUST be removed so the only entry point is the admin settings section. The section name and any user-facing strings MUST be translatable.

#### Scenario: Admin sees App Versions in Administration settings

- GIVEN a Nextcloud administrator opens Settings
- WHEN the administrator navigates to the Administration area
- THEN an "App Versions" entry MUST appear in the Administration sidebar
- AND the entry MUST display the translated section name and the app icon

#### Scenario: Top-level navigation entry is absent

- GIVEN any logged-in Nextcloud user
- WHEN they view the top-level application navigation menu
- THEN no "App Versions" entry MUST appear in the menu

#### Scenario: No standalone page route remains

- GIVEN the move to admin settings is complete
- WHEN any user requests the former front-page route of the app
- THEN no `FrontpageRoute`/page controller MUST serve the UI
- AND the UI MUST be reachable only through Settings → Administration → App Versions

### Requirement: Settings Form Renders the Existing SPA [MVP]

The admin settings form MUST render the existing App Versions Vue SPA inside the settings panel, reusing the existing template and JS/CSS bundle without modification. The SPA MUST be fully functional (app list, version picker, install) within the embedded settings context.

#### Scenario: SPA loads and functions inside the settings panel

- GIVEN an administrator opens Settings → Administration → App Versions
- WHEN the settings panel loads
- THEN the App Versions Vue SPA MUST render within the settings page
- AND its JavaScript and CSS bundles MUST be loaded (via `Util::addScript` / `Util::addStyle`)
- AND the installed-apps list MUST populate and version management MUST be usable without leaving the settings panel
