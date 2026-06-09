## Why

The external-source machinery (forge source binding, trusted-source allowlist, PAT storage) is fully implemented in the backend but is **invisible in the UI** — `App.vue` only calls the four version-management endpoints, so admins cannot bind a GitHub or Codeberg repo to an app, manage the trusted allowlist, or manage access tokens without editing config by hand. This change surfaces that machinery in the admin Settings panel so the feature is actually usable.

## What Changes

- Add a tab/section switcher to the admin UI (Apps / Sources / Tokens / Trusted sources), keeping the existing apps → versions → install view as the default tab.
- New child component `SourcesPanel.vue`: show the current binding for the selected app and a form to bind a source (pick forge github|codeberg, owner, repo, optional assetPattern) via the existing `GET /api/source/{appId}/binding` and `POST /api/source/{appId}/bind`.
- New child component `TokensPanel.vue`: list redacted PATs, add/edit/delete, and a per-forge "create a token" deeplink, wired to the existing PAT CRUD endpoints (`GET/POST /api/pats`, `PATCH/DELETE /api/pats/{id}`, `GET /api/pats/deeplink`).
- New child component `TrustedSourcesPanel.vue`: list current forge-qualified allowlist patterns and curated add/remove with an explicit "I trust this source" confirmation.
- **Backend addition**: admin-only, password-confirmed trusted-allowlist write endpoints on `ApiController` (`POST /api/trusted-sources` / `DELETE /api/trusted-sources/{pattern}`) that wrap `TrustedSourceList::setPatterns()` via a new `InstallerService` method, with curated construction of `{forge}:owner/repo` or `{forge}:owner/*` and **rejection of over-broad globs** (`*`, `*/*`, `{forge}:*`, empty/`*`-only owner).
- Adapt the Vue shell: replace/adjust `NcContent`/`NcAppContent` so the SPA renders cleanly inside Settings → Administration.
- All new forges (github, codeberg) selectable in every form; all new strings translatable.
- Discovery/search UI is explicitly **out of scope** (a later change); `app-discovery` is not modified.

This change **depends on** two already-defined but not-yet-implemented changes, referenced as prerequisites:
- `move-to-admin-settings` (#1): the app now lives in a Nextcloud admin Settings section. This UI renders inside that panel.
- `codeberg-forge-support` (#2): generic forge abstraction, Codeberg as forge #2, `forge` field on SourceBinding/Pat, forge-qualified allowlist (e.g. `github:ConductionNL/*`, `codeberg:Conduction/*`), forge-aware token validation. Specs here assume both have landed.

## Capabilities

### New Capabilities

(none — no new capability spec; all work extends existing capabilities)

### Modified Capabilities

- `external-sources`: NEW trusted-allowlist management API (curated add/remove, over-broad-glob rejection); UI surfacing of source binding + allowlist for both forges. The existing "Source management API" requirement is modified to also write the allowlist.
- `pat-management`: UI surfacing of token CRUD + per-forge deeplink (no backend change beyond what #2 added).
- `version-management`: the admin UI gains tabs/sections (Apps / Sources / Tokens / Trusted sources) and the settings-context shell adaptation.

## Impact

- **Frontend**: `src/App.vue` (tab structure, shell adaptation), new `src/components/SourcesPanel.vue`, `src/components/TokensPanel.vue`, `src/components/TrustedSourcesPanel.vue`; `templates/index.php` / `src/main.ts` mount unchanged but shell container revised.
- **Backend**: `lib/Controller/ApiController.php` (two new endpoints), `lib/Service/InstallerService.php` (new allowlist-write delegate with validation), wrapping existing `lib/Service/Source/TrustedSourceList.php::setPatterns()`.
- **APIs**: new `POST /api/trusted-sources`, `DELETE /api/trusted-sources/{pattern}`; reuse of existing `GET /api/sources` (returns `trustedPatterns`), `GET/POST /api/source/{appId}/(bind|binding)`, PAT endpoints, `GET /api/pats/deeplink`.
- **No** OpenRegister schemas, **no** DB migration, **no** seed data (ADR-016 N/A).
- **Dependencies**: requires `move-to-admin-settings` and `codeberg-forge-support` applied first.
- **Auth**: write endpoints are admin-only + `#[PasswordConfirmationRequired(strict: false)]` (adr-007 trust boundary).
