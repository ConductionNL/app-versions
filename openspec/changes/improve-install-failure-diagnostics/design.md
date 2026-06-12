## Context

App Versions installs a chosen release of an already-installed Nextcloud app by downloading it, backing up the existing folder, swapping in the new files, and running `InstallFinalizer::finalize()` (migrations, repair steps, job/route registration). The orchestration entry point is `InstallerService::installAppVersion()`, which delegates to either `SelectedReleaseInstallerService` (signed App Store path) or `ExternalReleaseInstallerService` (unsigned GitHub-release path). Both installers walk an `addDebug(stage, data)` breadcrumb trail.

Three weaknesses surfaced during a real install of Pipelinq 0.3.2-beta.1:

1. **Opaque failures.** The `catch (Exception)` block in `installAppVersion()` (lines ~309–323) returns HTTP 500 with only `$error->getMessage()`. The breadcrumb trail is attached only when `debug=1`. There is no machine-readable failure classification.
2. **No pre-flight check.** The app folder under `apps-extra` is commonly a bind-mounted git checkout owned by a uid other than the web-server user, so it is not writable. Today the installer downloads the entire release and only fails at the `rename()` backup step — wasted work and a confusing error.
3. **Unsafe finalize ordering.** In BOTH installers the backup folder (`.appversion-backup`) is deleted immediately after the file copy succeeds, and only THEN does `finalize()` run. A throw inside `finalize()` (a declared migration/repair step) is therefore unrecoverable: the backup is gone, files are swapped, the DB is partially migrated — yet the failure is reported as a generic 500 implying the previous version is intact (false).

A compounding frontend bug: on any failure `src/App.vue` (~lines 841–849) overwrites the backend `payload.message` with the OCS `metaMessage` (`"OCS request failed"`), so even today's flat backend message never reaches the admin.

Constraints: admin-only utility (adr-007); no database and no OpenRegister schemas; all user-facing strings must be translatable (adr-005); password confirmation already enforced on install via `PasswordConfirmationRequired`; the app store / source API can be unreachable and must degrade gracefully.

**ADR-016 (mandatory seed data) does not apply to this change.** App Versions touches no OpenRegister schemas and has no database — there is nothing to seed.

## Goals / Non-Goals

**Goals:**

- Attach `stage`, `category`, and `hint` to every install-failure payload, independent of the debug toggle, and map each category to an actionable translatable message and an appropriate HTTP status.
- Detect non-writable / dev-checkout app folders and surface them two ways: a non-blocking warning on the app card (proactive) and a fail-fast guard at the top of `installAppVersion()` (reactive).
- Reorder both installers so the backup is retained until `finalize()` succeeds, restore previous files on a finalize-phase throw, and report an honest outcome via a new `installStatus` taxonomy (`installed` / `reverted` / `installed-but-broken`).
- Fix the frontend so it prefers the structured payload over `metaMessage` and renders `stage` / `category` / `hint`.

**Non-Goals:**

- **Database migration rollback.** Filesystem rollback (restoring the backup folder) is straightforward; DB rollback is impossible because Nextcloud migrations are forward-only and App Versions cannot know which migrations a finalize-phase failure already committed. The `reverted-after-finalize` outcome therefore must state "files reverted, database state uncertain, manual check advised" rather than implying a clean restore.
- **Detecting apps that swallow their own init errors.** The specific Pipelinq failure was an app catching its OWN boot exception and logging `"Pipelinq initialization failed"`. There is no exception for App Versions to catch and no standard Nextcloud app-health signal to query, so this is fundamentally undetectable. The user explicitly accepted this as out of reach.
- No new routes, no schema changes, no new persistent config keys.

## Decisions

### D1 — Failure category enum mapped centrally

A single mapping (category → message + hint + HTTP status) lives in `InstallerService`, applied in the `catch` block so both installer paths converge on one payload shape. Enum: `preflight_permission | download | checksum_mismatch | extract | appid_mismatch | version_mismatch | incompatible | finalize | unknown`.

Status mapping: `preflight_permission` → 409 Conflict (environment is in a state that blocks the action); `incompatible` / `version_mismatch` / `appid_mismatch` / `checksum_mismatch` → 422 Unprocessable Entity (the requested release is unusable); `download` → 502 Bad Gateway (upstream fetch failed); `extract` / `finalize` → 500 (server-side processing failure, but now classified); `unknown` → 500. The `ApiController::toHttpStatus()` whitelist already permits 409/422/502, so no controller change is required.

*Alternatives considered:* throwing typed exception subclasses carrying their own category. Rejected for now — the installers already throw plain `Exception` in many spots, and a central mapper keyed off the last `addDebug` stage plus exception inspection is less invasive than retrofitting a typed hierarchy. The category is derived from (a) the last recorded stage and (b) lightweight inspection of the exception/message; `stage` is read from the installer's breadcrumb trail (already populated even when debug is off — only the *emission* to the payload was debug-gated, not the recording).

### D2 — Pre-flight writability check

The functional check is `is_writable(dirname($destination))`, because `rename()` of the existing app folder needs write permission on the PARENT directory, not the app folder itself. Dev-checkout heuristics (presence of a `.git` directory in the app folder; app-dir owner differing from the web-server uid via `fileowner()` vs `posix_getuid()`) are advisory signals used only to enrich the human `warning` text — they never, by themselves, block an install. Only the `is_writable` functional check drives the fail-fast guard.

`getInstalledApps()` computes `manageable: bool` + `warning: ?string` per app card (proactive). `installAppVersion()` runs the same writability check before any download and aborts with category `preflight_permission` when the parent is not writable (fail-fast). The card warning is non-blocking (the admin may understand the situation and proceed/fix it); the install guard aborts because the operation provably cannot succeed.

*Alternatives considered:* attempting a throwaway `touch`/`rename` probe in the parent dir. Rejected — `is_writable()` is sufficient and side-effect-free.

### D3 — Backup retained until finalize succeeds

Both installers are reordered: after the file copy, do NOT delete the backup. Run `finalize()`. Only on `finalize()` success delete the backup (`.appversion-backup`). On a finalize-phase throw, attempt to restore the previous files from the backup (swap the new folder out, rename the backup back). Outcome reporting:

- Copy/pre-finalize throw, backup restored cleanly → `installStatus: reverted` (fully safe; previous version intact).
- `finalize()` throw, files restored cleanly from backup → `installStatus: installed-but-broken` with a hint stating files were reverted but DB migrations may have partially applied and cannot be rolled back automatically — manual check advised.
- `finalize()` throw AND restore could not be guaranteed (e.g. restore rename failed) → `installStatus: installed-but-broken` with a stronger hint that the install is in an indeterminate state.

Rationale: the honest distinction the admin needs is "is my previous version safe?" Pre-finalize failures answer "yes" (`reverted`); finalize-phase failures answer "files maybe, database no" (`installed-but-broken`). Collapsing both into a generic 500 was the original defect.

*Alternatives considered:* snapshotting the DB before finalize. Rejected — App Versions has no DB ownership, snapshotting an arbitrary app's tables is out of scope and unreliable.

### D4 — Frontend prefers structured payload

In `src/App.vue`, the failure branch stops assigning `metaMessage` over `payload.message`. Precedence becomes `payload.hint` (action) + `payload.message` (what happened), with `metaMessage` used only as a last-resort fallback when the structured payload has neither. The existing `installStatusTone`/`installStatusLabel` computed properties are extended to recognise `reverted` (warning tone) and `installed-but-broken` (error tone). The result card renders `stage`, `category`, and `hint`; the debug viewer is unchanged. `normalizeInstallResult` is extended to carry the new fields. This reuses the existing payload-preservation path (`unwrapOcsResponseWithMeta` already returns the payload on failure).

## Risks / Trade-offs

- **DB state uncertainty after finalize failure** → cannot be solved; surfaced honestly via the `installed-but-broken` outcome and an explicit hint rather than hidden behind a 500.
- **`posix_getuid()` / `fileowner()` may be unavailable or misleading** (e.g. unusual PHP builds, container quirks) → owner-mismatch is advisory only; the functional `is_writable()` check is authoritative for the fail-fast guard, so a missing posix extension degrades the warning text but never produces a false block.
- **Restore-after-finalize itself can fail** (parent dir permissions changed mid-flight) → handled as the third outcome branch (`installed-but-broken`, indeterminate) with the strongest hint.
- **Category misclassification** (central mapper guesses wrong category from stage/message) → default to `unknown`/500 when no confident match; the raw `stage` and message are always present so the admin still has signal.
- **Additive payload fields** → non-breaking; older frontend builds ignore unknown fields.
- **Swallowed app-init errors remain invisible** → documented Non-Goal; no mitigation possible from App Versions.

## Migration Plan

Pure code change, no data or schema migration. Deploy backend + rebuilt frontend bundle together. Rollback = revert the commit; payloads are additive so a mixed (new backend / old frontend) state is safe in the interim. No config keys added, so nothing to clean up on rollback.

## Open Questions

None blocking. Exact HTTP status for `preflight_permission` (409 vs 422) was chosen as 409 Conflict and recorded in DEFERRED_QUESTIONS; either is within the `toHttpStatus()` whitelist and can be tuned during implementation without spec change.