## 1. Failure classification (backend core)

- [x] 1.1 Define the failure-category enum (`preflight_permission | download | checksum_mismatch | extract | appid_mismatch | version_mismatch | incompatible | finalize | unknown`) as constants and document the canonical `stage` values
- [x] 1.2 Add a central category → (message, hint, HTTP status) mapper in `lib/Service/InstallerService.php`, all strings translatable (adr-005); status map: `preflight_permission`→409, `incompatible`/`version_mismatch`/`appid_mismatch`/`checksum_mismatch`→422, `download`→502, `extract`/`finalize`/`unknown`→500
- [x] 1.3 Ensure installers record the last reached `stage` via the existing `addDebug()` breadcrumb trail and expose it to the orchestrator even when debug is OFF
- [x] 1.4 Rewrite the `catch (Exception)` block in `installAppVersion()` (~lines 309–323) to derive `category` from the last stage + exception inspection and attach `stage`, `category`, `hint`, and category-derived `statusCode` to the payload regardless of `$includeDebug`
- [x] 1.5 Confirm `ApiController::toHttpStatus()` whitelist already permits 409/422/502 (no change expected); add a code comment noting the new statuses are intentional

## 2. Pre-flight environment checks

- [x] 2.1 Add a writability helper that resolves an app's install destination and returns `is_writable(dirname($destination))` plus advisory dev-checkout signals (`.git` presence, `fileowner()` vs `posix_getuid()` mismatch, guarded for missing posix extension)
- [x] 2.2 Enrich `getInstalledApps()` (~lines 54–94) so each card carries `manageable: bool` and a translatable `warning: ?string` reason; warning is non-blocking
- [x] 2.3 Add a fail-fast guard at the top of `installAppVersion()` (before any download/release resolution) that runs the writability check and returns a `preflight_permission` failure (HTTP 409) with an actionable hint when the parent dir is not writable

## 3. Finalize-phase recovery and outcome taxonomy

- [x] 3.1 In `SelectedReleaseInstallerService` reorder so the backup `.appversion-backup` is retained until `finalize()` (called at ~line 299) succeeds; delete it only on success
- [x] 3.2 In `ExternalReleaseInstallerService` apply the same reorder (backup retained through `finalize()` at ~line 207; backup-delete at ~lines 198–200 moved to after finalize success)
- [x] 3.3 On a finalize-phase throw, restore the previous files from the retained backup in both installers; capture whether the restore succeeded cleanly
- [x] 3.4 Map outcomes to `installStatus`: `installed` (clean), `reverted` (pre-finalize failure, backup restored cleanly), `installed-but-broken` (finalize failed, or restore not cleanly guaranteed)
- [x] 3.5 For `installed-but-broken` after a finalize failure, set the hint to state files were reverted but DB migrations may have partially applied and cannot be auto-rolled back — manual check advised; for a failed restore, set the stronger indeterminate-state hint

## 4. Frontend (src/App.vue)

- [x] 4.1 In the install failure branch (~lines 841–849) stop overwriting `payload.message` with `metaMessage`; prefer structured `payload.hint`/`payload.message`, fall back to `metaMessage` only when both are absent
- [x] 4.2 Extend `normalizeInstallResult` (~line 260) to carry `stage`, `category`, `hint`, and the new `installStatus` values
- [x] 4.3 Extend `installStatusTone`/`installStatusLabel` (~lines 296–322) to map `reverted`→warning and `installed-but-broken`→error
- [x] 4.4 Render `stage`, `category`, and `hint` in the existing install result card (do not rebuild the card or debug viewer)
- [x] 4.5 Show a warning badge on app cards where `manageable === false`, displaying the `warning` reason

## 5. Tests (adr-009)

- [x] 5.1 Unit-test the category → (message, hint, status) mapper for every enum value, including `unknown`→500
- [x] 5.2 Unit-test the writability helper: writable parent → manageable; non-writable parent → not manageable + warning; `.git`/owner-mismatch advisory signals enrich warning without blocking
- [x] 5.3 Test the fail-fast guard aborts before download on a non-writable destination with category `preflight_permission` and HTTP 409
- [x] 5.4 Test the install-outcome taxonomy: clean install → `installed` (backup deleted only after finalize); pre-finalize failure → `reverted` with backup restored; finalize throw → `installed-but-broken` with `finalize` category and DB-uncertainty hint; finalize throw + failed restore → `installed-but-broken` indeterminate hint
- [x] 5.5 Assert structured `stage`/`category`/`hint` are present in failure payloads with debug OFF
- [x] 5.6 Frontend test/check: failure renders structured message (not `metaMessage`), result card shows stage/category/hint, and `manageable:false` cards show the warning badge
- [x] 5.7 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the frontend lint/build; fix any pre-existing quality issues encountered

## 6. Manual verification

- [ ] 6.1 Reproduce the bind-mounted-dev-checkout scenario: confirm card warning appears and install aborts fast with a clear 409 + hint
- [ ] 6.2 Verify rollback (install older version) still reports `installed` and the app remains enabled on stable31 and stable32
- [x] 6.3 Document explicitly in the verify notes that the swallowed-own-init-error case (Pipelinq) remains undetectable and out of scope
