# Proposal: add-version-pinning

## Summary
Give "pin" real semantics: an admin can pin an installed app to its current version; App Versions then refuses to overwrite that version through its own install path without an explicit override, detects when anything **else** (Nextcloud's own updater, occ, another admin) changes the version (drift), and responds with an admin notification plus a one-click re-pin (reinstall the pinned version). The UI is explicit that a pin is **monitored, not enforced against the Nextcloud updater** — NC core offers no veto hook for app updates.

## Motivation
`docs/intro.md` headlines "**Rollback or pin**" and the audit promise covers "every install, downgrade, or pin". Installing a specific version is fully specced; pin semantics exist nowhere in specs or code. Today a "pinned" version is silently undone by the next click on NC's regular Apps page update button — exactly the situation (broken update overwrites a deliberate rollback) the app exists to prevent.

**Honesty constraint (investigated against NC server source):** Nextcloud core exposes no cancellable pre-update hook. `OCP\App\Events\AppUpdateEvent` (since 27.0.0, dispatched in `OC\App\AppManager` after an update completes) is the only app-update event, and it is post-hoc and non-cancellable; there is no per-app update-hold config in core. So "block the NC updater" is not implementable without core patches. This change therefore specs the achievable behavior honestly:

1. **Pin tracking** — a persisted pin record (version, who, when, optional reason), surfaced as a badge in the app list and version picker.
2. **Self-enforcement** — App Versions' own install endpoint is the one surface we control: installs over a pin fail with 409 unless the admin explicitly overrides (which re-pins to the new version or unpins).
3. **Drift detection** — an `AppUpdateEvent` listener catches updates the moment any NC path performs them, and a daily reconciliation job compares every pin against `IAppManager` versions as a safety net (events missed while the app was disabled, manual file swaps).
4. **Drift response** — NC notification to admins + a UI banner offering **Re-pin** (reinstall the pinned version through the existing install flow) or **Accept** (move/clear the pin).

This is the apt-mark-hold / Renovate-pin pattern translated to what NC actually allows: hold what you control, watch what you don't, and make divergence loud and one-click reversible.

## Scope
- Pin record persisted as `IAppConfig` value `pin.{appId}` (JSON: `version`, `pinnedBy`, `pinnedAt`, optional `reason`) — same pattern as `source.{appId}`; no new table
- `lib/Service/Pin/PinStore.php` (+ immutable `Pin` value object mirroring `SourceBinding`)
- API: `GET /api/pins`, `PUT /api/app/{appId}/pin` (password confirmation), `DELETE /api/app/{appId}/pin` (password confirmation)
- Install-path guard in `ApiController::installVersion` / installer services: 409 on pinned apps without `overridePin`
- `lib/Listener/AppUpdatedListener.php` on `OCP\App\Events\AppUpdateEvent` + `lib/Cron/PinReconcileJob.php` (daily `TimedJob`)
- Drift notifications via `OCP\Notification\IManager` + `lib/Notification/Notifier.php`
- UI: pin/unpin actions, pinned badge in app list and version picker, drift banner with Re-pin / Accept actions, "monitored, not enforced" explanation copy
- Delta on the `audit-trail` capability: `pin`, `unpin`, `pin_drift` audit operations

## Out of scope
- Blocking or intercepting Nextcloud's own updater — impossible without core changes; explicitly documented instead (revisit if NC ever ships a cancellable pre-update event)
- Auto-re-pin (automatically reinstalling the pinned version on drift without admin action) — too aggressive for v1; reinstalling code without a human in the loop is a different risk class
- Pin expiry / scheduled unpin — future work
- Pinning apps that are not installed

## Dependencies
- `add-version-audit-trail` — this change extends its operation vocabulary (`pin`, `unpin`, `pin_drift`) via the included `specs/audit-trail/spec.md` delta. If pinning lands first, the audit delta tasks are deferred until the audit capability exists.
