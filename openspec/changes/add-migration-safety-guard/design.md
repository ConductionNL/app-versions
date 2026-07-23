# Design: add-migration-safety-guard

## Guard placement
The downgrade check runs in `InstallerService::installAppVersion` immediately after resolving installed + target versions, **before** dispatching to either installer (so before any download). New failure category `downgrade_guard` in `FailureClassifier` → HTTP 409 + translatable hint naming both versions. `allowDowngrade` flows: HTTP body param → CLI `--allow-downgrade` (add-occ-cli-commands maps onto the same service flag).

Ordering note vs the UI: the client-side safe-mode toggle and confirmation dialog remain as UX affordances; after this change the dialog's "confirm" simply sets `allowDowngrade: true` on the request. Server is authoritative.

## Migration diff
- Runs inside each installer after archive extraction (temp dir), only when `targetVersion < installedVersion`.
- Installed set: `glob('lib/Migration/Version*.php')` in the current app directory (not the DB `oc_migrations` table — file diff is source-of-truth for "what the target ships"; DB table would also list steps from even older versions, producing noise). Target set: same glob in the extracted temp dir.
- Diff = installed − target, class basenames without extension, sorted.
- Carried on the structured result as `orphanedMigrations: string[] | null` (`null` = diff unavailable). Dry-run carries the same field.
- try/catch: diff failure → `null` + warning flag; acknowledged downgrades never blocked by diff machinery.

## Last-known-good
- Written in `InstallFinalizer::finalize` success path (single choke point shared by both installers): `IAppConfig` key `lkg.{appId}`, JSON `{version, recordedAt, sourceId}` — same storage pattern as `source.{appId}` / planned `pin.{appId}`.
- Also written on the app's *first* successful App Versions install (there is no record before this app manages it — the record intentionally means "last version App Versions saw finalize cleanly", not "last version that ever worked").
- `getInstalledApps` enriches cards with `lkg`; UI renders the action when `lkg.version !== installedVersion`.
- The action is pure client routing: select the lkg version in the picker and open the normal install confirmation (downgrade dialog shows diff). No special install path.

## Interaction with pinning (add-version-pinning)
Independent: a pinned app rolling back to lkg still hits the pin guard (409) and needs `overridePin` — correct, both guards compose. No coupling in code.

## Testing
- Unit: guard matrix (up/down/equal × flag), classifier mapping, diff computation (fixtures with fabricated Version*.php sets), diff failure path, lkg write-on-success/preserve-on-failure.
- Vitest: dialog renders diff list, empty-diff copy, lkg action visibility + routing.

## Rejected alternatives
- Reading `oc_migrations` for the installed set — noisy (contains all historic steps) and requires no archive anyway; file diff is symmetric and cheap.
- Blocking downgrades when diff is non-empty — paternalistic; the app's core promise is rollback. Inform + require acknowledgement instead.
