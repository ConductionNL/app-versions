# Tasks: add-version-pinning

## Task 1: Pin value object + PinStore
- **Spec ref**: specs/version-pinning/spec.md (Requirement: Pin an installed app to its current version)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Service/Pin/Pin.php` — immutable value object mirroring `SourceBinding` (constructor validation, `fromArray`/`toArray`, fields: version, pinnedBy, pinnedAt, reason?, driftedTo?, driftedAt?)
  - `lib/Service/Pin/PinStore.php` — get/set/clear/all over `IAppConfig` key `pin.{appId}`; malformed stored JSON is treated as no-pin and logged (never fatals)
  - Version strings validated (`/^[0-9A-Za-z.\-+]+$/`) to keep the config payload sane

## Task 2: Pin API endpoints
- **Spec ref**: specs/version-pinning/spec.md (Requirements: Pin…, Unpin, Honest pin presentation)
- **Status**: todo
- **Acceptance criteria**:
  - `PUT /api/app/{appId}/pin` (pin installed version, optional reason), `DELETE /api/app/{appId}/pin`, `GET /api/pins` (pins joined with live `IAppManager` versions + drift status)
  - `PasswordConfirmationRequired` on PUT/DELETE; all three behind the existing admin gate; non-admin → 403
  - PUT rejects when the app is not installed or a `version` other than the installed one is requested
  - Routes added to `appinfo/routes.php`; `openapi.json` updated

## Task 3: Install-path pin guard + overridePin + pin:true
- **Spec ref**: specs/version-pinning/spec.md (Requirement: Pins are enforced on App Versions' own install path)
- **Status**: todo
- **Acceptance criteria**:
  - `installVersion` returns 409 (naming the pinned version) when target ≠ pinned version and no `overridePin`
  - `overridePin=repin|unpin` implemented; invalid values → 400; pin state changes only after install success
  - Reinstalling exactly the pinned version proceeds without override and keeps the pin
  - `pin: true` install parameter pins the installed version after success (atomic: failed install writes no pin)
  - Pin adjusted before finalize on override paths so the subsequent `AppUpdateEvent` is not flagged as drift

## Task 4: Drift detection — listener + reconcile job
- **Spec ref**: specs/version-pinning/spec.md (Requirement: Drift detection)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Listener/AppUpdatedListener.php` on `OCP\App\Events\AppUpdateEvent`, registered via `IRegistrationContext::registerEventListener`; compares `IAppManager::getAppVersion($appId, false)` against the pin
  - `lib/Cron/PinReconcileJob.php` extends `TimedJob` (24h), walks all pins; registered via `<background-jobs>` in `appinfo/info.xml` (NOT `IRegistrationContext` — no such API)
  - Drift recorded as `driftedTo`/`driftedAt` on the pin; idempotent — no re-handling while `installedVersion == driftedTo`
  - Re-pin/Accept clear the drift markers

## Task 5: Drift notifications
- **Spec ref**: specs/version-pinning/spec.md (Requirement: Drift response — notify and offer re-pin)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Notification/Notifier.php` registered via `IRegistrationContext::registerNotifierService`; subject names app, pinned version, observed version; link target is the App Versions UI
  - Notification fanned out to `admin` group members via `OCP\Notification\IManager`
  - Exactly one notification per (appId, driftedTo) pair
  - i18n: English source strings as keys; nl translations provided

## Task 6: UI — pin actions, badge, drift banner, honest copy
- **Spec ref**: specs/version-pinning/spec.md (Requirements: Honest pin presentation, Drift response)
- **Status**: todo
- **Acceptance criteria**:
  - Pin/unpin actions in the version picker; pin dialog includes reason field and the "enforced inside App Versions, monitored elsewhere — Nextcloud's own updater can still update this app" explanation
  - Pin badge on app-list cards and in the picker (version, pinnedBy, pinnedAt, reason on hover/detail)
  - Drift banner with Re-pin (drives the normal install flow incl. password confirmation) and Accept (move pin / remove pin)
  - 409 from the install endpoint surfaces as a pinned-app dialog offering re-pin/unpin/cancel
  - Theme CSS variables only (NL Design), dialogs in their own files per modal-isolation rule

## Task 7: Audit delta wiring
- **Spec ref**: specs/audit-trail/spec.md (Requirement: Pin lifecycle operations are audited)
- **Status**: todo
- **Acceptance criteria**:
  - `pin`, `unpin`, `pin_drift` entries written through the existing `AuditLogger` (best-effort)
  - `pin_drift` uses `actor_uid=system`; no duplicate entries per drifted version
  - Skipped gracefully (logged, not fatal) if the audit capability is not yet deployed — see dependency note in proposal.md

## Task 8: Tests + stubs
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - Unit tests: Pin/PinStore (validation, malformed JSON tolerance), pin guard matrix (no-override 409, repin, unpin, same-version pass, pin:true atomicity), listener drift detection incl. no-self-drift after override, reconcile idempotency, notifier subject rendering, controller auth (403 non-admin)
  - Extend `tests/stubs/server-internals.php` with any newly referenced internals; `OCP\App\Events\AppUpdateEvent`, `OCP\Notification\*`, `TimedJob` should resolve from shipped OCP — verify the unit-only suite (`tests/phpunit-unit-only.xml`) and psalm stay green; local ocp stub is stale, so confirm `php -l` locally and rely on CI for deep static analysis
  - `composer check:strict` passes

## Task 9: Docs + browser verification
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - `docs/intro.md` "Rollback or pin" paragraph updated to the honest semantics (self-enforced + monitored drift + notification)
  - In the dev container: pin an app → badge appears; attempt install of another version → 409 dialog; update the app via NC's Apps page → notification received + drift banner; Re-pin restores the pinned version; Accept moves/clears the pin
  - Verify exactly one notification on repeated cron runs (`occ background-job:execute` on the reconcile job)
  - Bump `appinfo/info.xml` `<version>` with the bundle change (NC immutable-cache gotcha)
