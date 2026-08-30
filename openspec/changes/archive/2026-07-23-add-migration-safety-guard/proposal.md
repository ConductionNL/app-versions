# Proposal: add-migration-safety-guard

## Summary
Make downgrade safety server-enforced and informed: the install path refuses downgrades without an explicit `allowDowngrade` acknowledgement (today the only guard is a client-side dialog), reports **which database migrations the target version lacks** so the admin sees the schema risk concretely, and records a **last-known-good** version per app after every successful finalize — surfaced as a one-click rollback target.

## Motivation
The deepdive research (deepdive-2026-07-23-app-versions) is unambiguous: irreversible schema migrations are the reason Nextcloud core wontfixed app downgrades, and **no analogue tool solves it** — WP Rollback swaps files and tells admins to keep backups. App Versions handles files honestly (backup/restore, outcome taxonomy) but its downgrade guard is a Vue dialog: any API/CLI/script consumer can downgrade with zero friction, and the dialog itself can only warn generically ("may have migrations") because nothing inspects the target.

Three concrete deficits:
1. **Client-only enforcement.** `installVersion` happily downgrades when called directly — the safe-mode toggle and confirmation dialog live entirely in `App.vue`.
2. **Blind warnings.** The archive of the target version contains its `lib/Migration/Version*` files; comparing them against the installed set tells the admin exactly which migrations the target lacks — turning "there might be schema drift" into "2.3.0 lacks Version2040Date… (added in 2.4.0); its schema changes will remain".
3. **No known-safe target.** Rolling back after a broken update means guessing which version was last good. The app already knows: the last version that passed finalize on this instance. Recording it makes rollback one decision, not an investigation (demanded since 2016 — nextcloud/updater#29 asked for exactly this).

## Scope
- Server-side downgrade guard in `InstallerService::installAppVersion`: target < installed (version_compare) without `allowDowngrade: true` → structured 409, category `downgrade_guard` (new failure category + hint)
- Migration diff during install preparation (both installers, after extraction, before file swap — and in dry-run): list `lib/Migration/Version*.php` present in the installed copy but absent from the target archive; included in the dry-run/guard response and in the downgrade dialog
- Last-known-good: `lkg.{appId}` app-config JSON (`version`, `recordedAt`, `sourceId`) written by `InstallFinalizer` on success only; exposed on the app card; "Roll back to last known good" action routes through the normal install flow (inherits the downgrade guard + migration diff)
- UI: downgrade dialog upgraded to show the migration diff; safe-mode toggle keeps its role but the server is now authoritative

## Non-goals
- No schema rollback (impossible to do generically — explicitly out of scope, stated in UI copy)
- No automatic post-install health checks (future work)
- No DB/data backup (pre-install backup hooks are a separate backlog feature)

## Impact
- New capability spec: `migration-safety`
- Touches `lib/Service/InstallerService.php`, both installer services, `lib/Service/Installer/{FailureClassifier,InstallFinalizer}.php`, `src/App.vue`
- CLI `--allow-downgrade` (add-occ-cli-commands) maps to the same guard
