# Architecture

App Versions is a thin admin-only tool. It has **no domain model** of its own —
no OpenRegister schemas, no database tables, no persistent state. Every call
is a live query against the Nextcloud app store.

## Components

```
┌───────────────────────────────┐
│         src/App.vue           │   Vue 3 single-page UI (admin route)
│   (picker + install panel)    │
└──────────────┬────────────────┘
               │ OCS JSON over /api/…
┌──────────────▼────────────────┐
│    lib/Controller/            │
│    ApiController.php          │   Thin HTTP layer; DI'd via constructor
│    PageController.php         │
└──────────────┬────────────────┘
               │
┌──────────────▼────────────────┐   Discovery + install orchestration.
│    lib/Service/               │   Talks to the Nextcloud app store via
│    InstallerService           │   IClientService (no raw Guzzle).
│    SelectedReleaseInstaller   │   Runs migrations + repair steps after
└──────────────┬────────────────┘   extracting the release archive.
               │
┌──────────────▼────────────────┐
│    Nextcloud OCP / OC         │   IAppManager, IClientService, ServerVersion,
│                               │   IGroupManager, IUserSession
└───────────────────────────────┘
```

## Request flow — install a specific version

1. UI posts to `POST /api/app/{appId}/versions/{version}/install`.
2. `ApiController::installVersion` verifies admin, pulls `targetVersion` /
   `debug` from the body, delegates to `InstallerService::installAppVersion`.
3. `InstallerService` resolves the download URL against the current update
   channel, verifies the release signature, then hands off to
   `SelectedReleaseInstallerService`.
4. `SelectedReleaseInstallerService` downloads the archive, extracts it into
   the apps directory, runs the app's migrations and repair steps.
5. If `debug` was set, step 4 runs in dry-run mode: every action is recorded
   but nothing is written. The dry-run trace is returned to the UI.

## Authentication & authorization

- All endpoints live under OCS (`/ocs/v2.php/apps/app_versions/api/...`),
  registered in [`appinfo/routes.php`](../appinfo/routes.php) per ADR-016.
- Controller methods carry `#[NoAdminRequired]` (auth attribute required by the
  hydra route-auth gate) plus an explicit `isAdmin()` check inside the body
  — the attribute declares posture, the body enforces it. See ADR-005 for the
  attribute-to-body contract.
- Install endpoints additionally require `#[PasswordConfirmationRequired]` so
  a stolen session cannot silently swap app versions.

## Dependency injection

`ApiController` takes every collaborator through its constructor:
`InstallerService`, `IGroupManager`, `IUserSession`, `ServerVersion`. No
`\OC::$server` lookups inside methods. The constructor signature is the
dependency surface — a grep lists every collaborator in one place (ADR-003).

## What this app does **not** do

- No OpenRegister entities / schemas (ADR-001 N/A).
- No `/api/health` or `/api/metrics` endpoints — stateless utility (ADR-006
  N/A).
- No dashboard widgets or sidebar tabs (ADR-018 / ADR-019 N/A).
- No translations to languages beyond English + Dutch (ADR-007 minimum).
