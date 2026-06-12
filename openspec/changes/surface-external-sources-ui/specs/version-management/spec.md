## ADDED Requirements

### Requirement: Tabbed admin UI in the settings context [MVP]

The admin UI MUST present a tab/section switcher with at least the sections Apps, Sources, Tokens, and Trusted sources, with the existing apps → versions → install view as the default tab. The UI MUST render cleanly inside the Nextcloud admin Settings panel (Settings → Administration), without the full app-shell chrome.

#### Scenario: Tabs rendered in admin settings

- **GIVEN** an admin opens Settings → Administration → App Versions
- **WHEN** the panel loads
- **THEN** the UI MUST display a tab/section switcher with Apps, Sources, Tokens, and Trusted sources
- **AND** the Apps tab (the existing apps → versions → install view) MUST be selected by default

#### Scenario: Switching tabs

- **GIVEN** the admin is on the Apps tab
- **WHEN** the admin selects the Sources, Tokens, or Trusted sources tab
- **THEN** the corresponding panel (`SourcesPanel`, `TokensPanel`, or `TrustedSourcesPanel`) MUST be shown
- **AND** the previously shown panel MUST be hidden

#### Scenario: Settings-context shell adaptation

- **GIVEN** the app is mounted inside the admin Settings section (per `move-to-admin-settings`)
- **WHEN** the UI renders
- **THEN** the UI MUST NOT render the full app-shell chrome (`NcContent`/`NcAppContent` navigation rail)
- **AND** MUST use a settings-appropriate container so the SPA fits within the settings panel

#### Scenario: Existing apps/versions flow preserved

- **GIVEN** an admin is on the default Apps tab
- **WHEN** the admin selects an app and views its versions
- **THEN** the existing version list and install flow MUST behave as before this change

#### Scenario: Non-admin user is blocked

- **GIVEN** a non-admin user reaches the panel
- **WHEN** it loads
- **THEN** the UI MUST show a "Forbidden" state
- **AND** the write endpoints MUST return HTTP 403
