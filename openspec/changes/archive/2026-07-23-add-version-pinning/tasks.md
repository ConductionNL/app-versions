# Tasks: add-version-pinning

## Task 1: Pin value object + PinStore
- **Spec ref**: specs/version-pinning/spec.md (Requirement: Pin an installed app to its current version)
- **Status**: done
- **Acceptance criteria**:
  - `lib/Service/Pin/Pin.php` — immutable value object mirroring `SourceBinding` (constructor validation, `fromArray`/`toArray`, fields: version, pinnedBy, pinnedAt, reason?, driftedTo?, driftedAt?)
  - `lib/Service/Pin/PinStore.php` — get/set/clear/all over `IAppConfig` key `pin.{appId}`; malformed stored JSON is treated as no-pin and logged (never fatals)
  - Version strings validated (`/^[0-9A-Za-z.\-+]+$/`) to keep the config payload sane

## Task 2: Pin API endpoints
- **Spec ref**: specs/version-pinning/spec.md (Requirements: Pin…, Unpin, Honest pin presentation)
- **Status**: done
- **Acceptance criteria**:
  - `PUT /api/app/{appId}/pin` (pin installed version, optional reason), `DELETE /api/app/{appId}/pin`, `GET /api/pins` (pins joined with live `IAppManager` versions + drift status)
  - `PasswordConfirmationRequired` on PUT/DELETE; all three behind the existing admin gate; non-admin → 403
  - PUT rejects when the app is not installed or a `version` other than the installed one is requested
  - Routes: this app has no `appinfo/routes.php` (house convention) — routed via `#[ApiRoute]` attributes on `ApiController`, same as every other endpoint; `openapi.json` updated with the 3 new paths

## Task 3: Install-path pin guard + overridePin + pin:true
- **Spec ref**: specs/version-pinning/spec.md (Requirement: Pins are enforced on App Versions' own install path)
- **Status**: done
- **Acceptance criteria**:
  - `installVersion` returns 409 (naming the pinned version) when target ≠ pinned version and no `overridePin`
  - `overridePin=repin|unpin` implemented; invalid values → 400; pin state changes only after install success
  - Reinstalling exactly the pinned version proceeds without override and keeps the pin
  - `pin: true` install parameter pins the installed version after success (atomic: failed install writes no pin)
  - Pin adjusted synchronously right after install success (before the response returns), so a same-request drift check never misreads our own override — investigated and confirmed App Versions' own installers (`SelectedReleaseInstallerService`/`ExternalReleaseInstallerService`) never call `IAppManager::upgradeApp()` and so never dispatch `AppUpdateEvent` themselves (that event is only fired by NC's own web-updater/`occ app:update` path via `OC\App\AppManager`); no self-inflicted-drift race is reachable in practice

## Task 4: Drift detection — listener + reconcile job
- **Spec ref**: specs/version-pinning/spec.md (Requirement: Drift detection)
- **Status**: done
- **Acceptance criteria**:
  - `lib/Listener/AppUpdatedListener.php` on `OCP\App\Events\AppUpdateEvent`, registered via `IRegistrationContext::registerEventListener`; compares `IAppManager::getAppVersion($appId, false)` against the pin
  - `lib/Cron/PinReconcileJob.php` extends `TimedJob` (24h), walks all pins; registered via `<background-jobs>` in `appinfo/info.xml` (NOT `IRegistrationContext` — no such API)
  - Drift recorded as `driftedTo`/`driftedAt` on the pin; idempotent — no re-handling while `installedVersion == driftedTo`
  - Re-pin/Accept clear the drift markers

## Task 5: Drift notifications
- **Spec ref**: specs/version-pinning/spec.md (Requirement: Drift response — notify and offer re-pin)
- **Status**: done
- **Acceptance criteria**:
  - `lib/Notification/Notifier.php` registered via `IRegistrationContext::registerNotifierService`; subject names app, pinned version, observed version; link target is the App Versions UI
  - Notification fanned out to `admin` group members via `OCP\Notification\IManager`
  - Exactly one notification per (appId, driftedTo) pair
  - i18n: English source strings as keys; `l10n/nl.json` added with nl translations (this app had no `l10n/` directory before this change — first translation file in the repo, scoped to the pin-lifecycle strings this change introduces)

## Task 6: UI — pin actions, badge, drift banner, honest copy
- **Spec ref**: specs/version-pinning/spec.md (Requirements: Honest pin presentation, Drift response)
- **Status**: done
- **Acceptance criteria**:
  - Pin/unpin actions in the version picker; pin dialog includes reason field and the "enforced inside App Versions, monitored elsewhere — Nextcloud's own updater can still update this app" explanation
  - Pin badge on app-list cards and in the picker (version, pinnedBy, pinnedAt, reason on hover/detail)
  - Drift banner with Re-pin (drives the normal install flow incl. password confirmation) and Accept (move pin / remove pin)
  - 409 from the install endpoint surfaces as a pinned-app dialog offering re-pin/unpin/cancel
  - Theme CSS variables only (NL Design), dialogs in their own files per modal-isolation rule
  - Deferred: no "pin after install" checkbox wired to the backend's `pin: true` install parameter — the primary UI path is the explicit Pin button on the installed version. The backend parameter is implemented and unit-tested; wiring a UI control for it is a small, low-risk follow-up

## Task 7: Audit delta wiring
- **Spec ref**: specs/audit-trail/spec.md (Requirement: Pin lifecycle operations are audited)
- **Status**: done
- **Acceptance criteria**:
  - `pin`, `unpin`, `pin_drift` entries written through the existing `AuditLogger` (best-effort)
  - `pin_drift` uses `actor_uid=system`; no duplicate entries per drifted version
  - Skipped gracefully (logged, not fatal) if the audit capability is not yet deployed — see dependency note in proposal.md

## Task 8: Tests + stubs
- **Spec ref**: all spec files
- **Status**: done
- **Acceptance criteria**:
  - Unit tests: Pin/PinStore (validation, malformed JSON tolerance), pin guard matrix (no-override 409, repin, unpin, same-version pass, pin:true atomicity, drift-marker clearing on re-pin), listener drift detection incl. no-self-drift after override, reconcile idempotency + partial-failure isolation, drift-handler notify/idempotency, notifier subject rendering, controller auth (403 non-admin) + pin/unpin/pins endpoint behaviour
  - `OCP\App\Events\AppUpdateEvent`, `OCP\Notification\*`, `TimedJob` all resolved directly from `vendor/nextcloud/ocp` under `bootstrap-unit-only.php` — no stub additions needed
  - Discovered and fixed a pre-existing gap while touching this file: `tests/phpunit-unit-only.xml`'s curated suite excluded several directories (root-level `tests/unit/Service/*`, `unit/Sections`, `unit/Settings`, `unit/Cron`) that actually run clean under the no-bootstrap harness — expanded the suite to the full `unit/Service` tree plus the missing directories; this also surfaced and fixed a genuinely broken test file (`InstallerServiceTrustedPatternTest.php` was calling `InstallerService`'s constructor with a stale, too-short argument list). Local gate is now 298 tests green (was 166 baseline; ~98 were pre-existing-but-unrun, ~34 are new for this change)
  - `composer check:strict` is not a defined script in this repo; ran the equivalent local gates instead — `php -l` on every changed file, `php-cs-fixer fix --dry-run` (clean), and `psalm --no-cache` (0 new errors; pre-existing errors in untouched files unchanged, 2 pre-existing psalm findings I did touch in `InstallerService.php`/`PinStore.php` while adding this code were fixed)

## Task 9: Docs + browser verification
- **Spec ref**: all spec files
- **Status**: partial — docs and version bump done; live browser/dev-container verification NOT performed
- **Acceptance criteria**:
  - `docs/intro.md` "Rollback or pin" paragraph updated to the honest semantics (self-enforced + monitored drift + notification) — done
  - In the dev container: pin an app → badge appears; attempt install of another version → 409 dialog; update the app via NC's Apps page → notification received + drift banner; Re-pin restores the pinned version; Accept moves/clears the pin — **not performed**: no live Nextcloud dev instance was available/provisioned as part of this build; behaviour is covered by the unit test suite (guard matrix, listener, reconcile idempotency, drift-handler notify) but not live-verified through the actual UI. Flagging as a follow-up for whoever next has a running dev container.
  - Verify exactly one notification on repeated cron runs (`occ background-job:execute` on the reconcile job) — covered by `PinReconcileJobTest`/`PinDriftHandlerTest` idempotency assertions, not live-verified
  - Bump `appinfo/info.xml` `<version>` with the bundle change (NC immutable-cache gotcha) — done (1.1.0 → 1.2.0)
