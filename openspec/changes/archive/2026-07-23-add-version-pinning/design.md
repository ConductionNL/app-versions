# Design: add-version-pinning

## What Nextcloud actually offers (investigated)

| Mechanism | Verdict |
| --- | --- |
| Cancellable pre-update hook for app updates | **Does not exist** in NC core. |
| `OCP\App\Events\AppUpdateEvent` (since 27.0.0) | Post-hoc, non-cancellable; dispatched in `OC\App\AppManager` (`dispatchTyped(new AppUpdateEvent($appId))`) after the update completed. Fired by both the web Apps page and `occ app:update`. Usable for **detection only**. |
| Per-app update-hold config in core | None. `appstoreenabled` is global; the updatenotification app has no per-app suppression API we can drive. |

Conclusion: pin = **self-enforcement + monitored drift**, never "the NC updater is blocked". The UI says so verbatim ("Pins are enforced inside App Versions and monitored elsewhere — Nextcloud's own updater can still update this app; you will be notified.").

## Architecture overview

```
PUT  /api/app/{appId}/pin    ──► PinStore::set(Pin)        appconfig pin.{appId} (JSON)
DELETE /api/app/{appId}/pin  ──► PinStore::clear(appId)
GET  /api/pins               ──► PinStore::all()  (joined with IAppManager versions → live drift status)

installVersion(appId, version)
     └── PinGuard: pinned && version != pin.version && !overridePin  ──► 409
                   overridePin=repin  ──► install, then PinStore::set(new version)
                   overridePin=unpin  ──► install, then PinStore::clear()

OCP\App\Events\AppUpdateEvent ──► AppUpdatedListener ─┐
PinReconcileJob (TimedJob, daily, all pins) ──────────┼──► DriftHandler
                                                      │      ├── notify admins (OCP\Notification)
                                                      │      ├── audit `pin_drift` (best-effort)
                                                      │      └── mark pin record `driftedTo` + `driftedAt`
```

## Pin record

`IAppConfig` key `pin.{appId}`, JSON — mirrors the proven `source.{appId}` / `SourceBinding` pattern (immutable value object, `fromArray`/`toArray`, validation in constructor):

```json
{
  "version": "2.3.0",
  "pinnedBy": "alice",
  "pinnedAt": "2026-06-11T12:00:00Z",
  "reason": "2.5.0 breaks LDAP sync",
  "driftedTo": null,
  "driftedAt": null
}
```

No DB table: pins are few (one per app maximum), config-shaped, and need to survive exactly as long as bindings do. `driftedTo`/`driftedAt` make drift state durable and idempotent (notify once per drifted version, not on every reconcile run).

Pinning is only allowed for the **currently installed** version: pin = "hold what is running". "Pin a different version" is just install-then-pin, and the install endpoint accepts `pin: true` to do both atomically (set the pin only after the install succeeded).

## Drift detection: listener + reconciler

- **Listener** (`AppUpdatedListener` on `AppUpdateEvent`): immediate detection for every update flowing through `AppManager` — web UI, occ, other tools using OCP. Compares `IAppManager::getAppVersion(appId, useCache: false)` to the pin. Updates App Versions performs itself with `overridePin` adjust the pin *before* finalize, so the listener sees no mismatch (no self-inflicted drift alarms).
- **Reconciler** (`PinReconcileJob`, daily `TimedJob`, registered via `<background-jobs>` in info.xml): walks all pins and compares against live versions. Catches what the listener can't: events emitted while app_versions was disabled, manual `tar -x` over the app dir, restored backups.
- **Idempotency**: drift is acted on only when `installedVersion != pin.version && installedVersion != pin.driftedTo`. Re-pin or Accept clears the drift markers.

## Drift response

1. **Notification**: `OCP\Notification\IManager` notification to members of the `admin` group ("`openregister` was updated to 2.5.0 but is pinned to 2.3.0"), with a "Review" link into the app. Requires a `Notifier` (`lib/Notification/Notifier.php`) registered via `IRegistrationContext::registerNotifierService` — this one IS a real registration API, unlike the nonexistent `registerJob`.
2. **Audit**: `pin_drift` entry (actor `system`, from=pinned version, to=observed version) — best-effort, via the audit-trail capability.
3. **UI banner** on the app's row/picker: **Re-pin 2.3.0** (runs the normal install flow for the pinned version — password confirmation included — then clears drift markers) or **Accept** (choice of "move pin to 2.5.0" or "remove pin").

Re-pin deliberately reuses the existing install path end-to-end (source resolution, allowlist, integrity checks, audit). No shortcut installer.

## Why 409 + explicit override instead of silently allowing

The pin's only hard guarantee is "App Versions itself will not betray it". A modal "this app is pinned — re-pin to the new version / unpin / cancel" turns every accidental overwrite into a conscious decision, and the override parameter keeps the API contract explicit and scriptable. `overridePin` accepts `repin` | `unpin` (not a bare boolean) so the post-install pin state is always intentional.

## Security

- Pin/unpin/override mutate what code runs on the instance → `PasswordConfirmationRequired` on PUT/DELETE pin and (already present) on install.
- All endpoints behind the existing admin gate.
- The listener/reconciler never installs anything autonomously — drift response is notify + offer, human confirms.

## Risks

| Risk | Mitigation |
| --- | --- |
| Admins believe pin blocks the NC updater | Explicit "monitored, not enforced" copy at pin time and on the badge tooltip; proposal/docs updated to the honest wording |
| Notification spam on repeated reconciles | `driftedTo` marker — one notification per drifted version |
| Listener misses updates while app disabled | Daily reconcile job as safety net |
| Self-inflicted drift alarms from our own overridden installs | Pin adjusted before finalize on `overridePin` paths; listener compares against the already-updated pin |
| Re-pin reinstalls a version the source no longer offers | Re-pin flows through the normal version-list/install path, so the existing "release not found" error handling applies; banner then suggests Accept |
