# Design: add-auto-update-policies

## Storage
- `policy.{appId}` — `IAppConfig` JSON `{level, setBy, setAt}`; `PolicyStore` + immutable `Policy` value object mirroring `SourceBinding`/planned `PinStore` (same validation posture: malformed JSON → treated as none + logged).
- Attempt ledger: `auto_attempt.{appId}` JSON map `version → {at, outcome}` — bounds the never-retry rule without a new table; pruned to the last 10 entries per app.
- Globals: `auto_update_enabled`, `auto_update_window` plain app-config values.

## Job
`AutoUpdateJob extends TimedJob`, interval 24 h, registered in `info.xml`. Flow per app (policy ≠ none):
1. pin check (`PinStore` if present — the class is introduced by add-version-pinning; guarded `class_exists`/optional-service lookup so build order is flexible),
2. version listing via `InstallerService::getAppVersions` (bound source),
3. candidate = max(available) where `version_compare(candidate, installed, '>')` ∧ compatible ∧ level-bounded (semver split on `.`; non-semver versions never qualify for patch/minor, only for `all`),
4. attempt-ledger check, then `installAppVersion(dryRun: false, allowDowngrade: false)`,
5. record attempt, notify outcome.

The job wraps each app in try/catch; a throwing app never stops the sweep. Window check + kill switch happen once at entry. Maintenance-mode note: installs already toggle maintenance; the job runs them sequentially, so one window may briefly toggle maintenance several times — acceptable for a night window, documented.

## Level semantics
patch: same major+minor, greater patch · minor: same major, greater minor-or-patch · all: any greater. Pre-release/build suffixes: excluded from patch/minor (conservative), `all` uses raw `version_compare`.

## Notifications
Extend `Notifier` with `auto_update_success` / `auto_update_failure` subjects; failure message reuses `FailureClassifier` hint. Links point to the admin settings section.

## UI
- App card: policy `NcSelect` (None/Patch/Minor/All, `inputLabel`), badge when active, "automation disabled" hint when kill switch off.
- Settings row (Apps tab header area): kill switch `NcCheckboxRadioSwitch` + window text field with client-side `HH:MM-HH:MM` validation.

## Testing
- Unit: PolicyStore round-trip + malformed JSON; candidate selection matrix (levels × semver/pre-release/non-semver); window logic incl. midnight crossing; attempt ledger; pin skip; sweep isolation (one app throws).
- Vitest: policy selector, disabled indication, window validation.

## Rejected alternatives
- Cron expression per app — over-configuration for MVP; one nightly window mirrors unattended-upgrades.
- Auto-rollback on failure — requires lkg semantics (separate change) and risks flapping; notification + one-click manual rollback instead.
- `occ app:update --all` delegation — bypasses source bindings, integrity checks, and reporting; the whole point is not to.
