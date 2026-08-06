# Proposal: add-auto-update-policies

## Summary
Policy-driven automatic app updates: per app, an admin chooses none / patch / minor / all; a nightly background job installs qualifying newer versions from the app's bound source through the standard installer path, inside a configurable time window, honoring pins, and reporting every outcome as an admin notification. Global kill switch, default off.

## Motivation
Nextcloud has **no native app auto-update**. Admins who want it cron `occ app:update --all` — blind (always to latest, no patch-only restraint, no window, no report), which is exactly the update-anxiety loop the research documented: admins defer updates because a bad one is hard to undo, and broken updates arrive unnoticed. The demand thread (help.nextcloud.com/t/140709) and the existence of a community auto-updater app confirm the lane is real; Easy Updates Manager occupies it in WordPress with policy granularity as its core value.

App Versions is uniquely positioned to do this *safely* rather than blindly:
- updates run through its installers (signature/integrity verification, backup/restore, structured outcomes) instead of raw `occ`,
- **pins are respected** (a pinned app never auto-updates — composing with add-version-pinning),
- semver-level policies bound the blast radius (patch-only for production),
- every run leaves a notification (and an audit entry once add-version-audit-trail lands),
- rollback is one click away in the same UI when an auto-update does break something.

## Scope
- Policy record `policy.{appId}` via `IAppConfig` (JSON: `level` ∈ none|patch|minor|all, `setBy`, `setAt`) — same pattern as `source.{appId}`
- API: `GET /api/policies`, `PUT /api/app/{appId}/policy`, `DELETE /api/app/{appId}/policy` (password-confirmed writes)
- `lib/BackgroundJob/AutoUpdateJob.php` (`TimedJob`, daily): for each app with level ≠ none — skip pinned apps, list versions from bound source, select highest qualifying stable version per level, install via `InstallerService` (never downgrades, never crosses the level boundary), at most one attempt per (app, version)
- Global config: `auto_update_enabled` (default `false`), `auto_update_window` (default `01:00-05:00` server time); job no-ops outside the window or when disabled
- Notifications: per-app success and failure notifications via the existing Notifier; failure reuses the structured category/hint
- UI: policy selector on the app card + a settings row for the global switch/window

## Non-goals
- No auto-rollback on failed auto-update (the failure notification links to the picker; lkg lands via add-migration-safety-guard)
- No per-app windows or cron expressions; one global window
- No auto-update for apps without a resolvable bound/App Store source

## Impact
- New capability spec: `auto-update-policies`
- Touches `lib/BackgroundJob/` (new job), `lib/Controller/ApiController.php`, `lib/Service/` (policy store), `info.xml`, `src/App.vue`
- Depends on add-version-pinning's pin semantics for the skip rule (degrades to "no pins exist" if built first)
