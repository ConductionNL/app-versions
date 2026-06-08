## Why

When an app install fails, App Versions collapses every error into a single HTTP 500 with a flat `getMessage()` string, and the rich stage-by-stage breadcrumb trail is only emitted when `debug=1`. Worse, the frontend then overwrites whatever useful backend message did arrive with the bland OCS `"OCS request failed"` text, so admins are left with no actionable information. A real-world failure (installing Pipelinq 0.3.2-beta.1) exposed three concrete gaps: a filesystem permission error that should have been caught before download, a finalize-phase failure that left files swapped and the backup already deleted while still reporting "previous version intact", and a generic 500 that told the admin nothing about what to do next.

## What Changes

- **Structured failure payloads (always-on).** Every install failure now carries `stage`, `category`, `hint`, and an outcome `installStatus`, regardless of the debug toggle. Categories are mapped to actionable, translatable messages and appropriate HTTP status codes (e.g. permission/pre-flight → 409/422, not a blanket 500). 500 is reserved for genuinely unexpected errors.
- **Pre-flight environment detection.** Before any download, `installAppVersion()` checks that the app folder's parent is writable by the web-server user and fails fast with category `preflight_permission` when it is not. The installed-apps list is also enriched so each app card carries a `manageable` flag and a `warning` reason (e.g. bind-mounted dev checkout, owner mismatch, `.git` present), surfacing the problem proactively before the admin tries to install.
- **Finalize-phase recovery and honest outcomes.** Both installers are reordered so the backup is retained until `finalize()` succeeds; on a finalize-phase throw, the previous files are restored from backup. A new outcome taxonomy distinguishes `installed`, `reverted` (pre-finalize failure, fully safe), and `installed-but-broken` (finalize failed and clean restore could not be guaranteed). The DB-rollback limitation is surfaced honestly in the outcome.
- **Frontend bug fix.** `src/App.vue` no longer clobbers the structured backend `message`/`hint` with the OCS `metaMessage`; it prefers the structured payload and renders `stage`, `category`, and `hint` in the existing result card.
- Out of scope (documented): apps that catch and swallow their own init/boot exceptions (the Pipelinq case) are fundamentally undetectable by App Versions and explicitly accepted as out of reach.

## Capabilities

### New Capabilities

(none — all behavior lives in the existing `version-management` capability)

### Modified Capabilities

- `version-management`: tightens the existing "Install Specific Version" failure scenario to require structured `stage`/`category`/`hint`; adds a new "Pre-flight Environment Checks" requirement (proactive card warnings + fail-fast guard); adds install-outcome taxonomy (`installed`/`reverted`/`installed-but-broken`) with finalize-phase backup retention and restore.

## Impact

- **Backend:** `lib/Service/InstallerService.php` (failure payload shape, pre-flight guard, `getInstalledApps()` enrichment), `lib/Service/SelectedReleaseInstallerService.php` and `lib/Service/ExternalReleaseInstallerService.php` (backup-retain-until-finalize reorder + restore), `lib/Service/Installer/InstallFinalizer.php` (finalize-phase error surface). `lib/Controller/ApiController.php` already whitelists 409/422 via `toHttpStatus()`; no route changes.
- **Frontend:** `src/App.vue` (prefer structured payload over `metaMessage`, render stage/category/hint, app-card warning badge).
- **APIs:** `POST /api/app/{appId}/versions/{version}/install` and `GET` installed-apps response gain new fields (additive, non-breaking). Admin-only (adr-007) unchanged; all new strings translatable (adr-005).
- **Dependencies / data:** none. No database, no OpenRegister schemas — adr-016 (mandatory seed data) does not apply.