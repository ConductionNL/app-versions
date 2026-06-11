# Tasks: add-version-audit-trail

## Task 1: AuditEntry entity + mapper + migration
- **Spec ref**: specs/audit-trail/spec.md (Requirement: Version operations are recorded)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Db/AuditEntry.php` extends `Entity` with the columns from `design.md` (actor_uid, app_id, operation, from_version, to_version, source_id, status, message, created_at)
  - `lib/Db/AuditEntryMapper.php` extends `QBMapper` (`TABLE_NAME = 'app_versions_audit'`); provides `insert`, `findPage(?string $appId, int $limit, int $offset)`, `deleteOlderThan(\DateTimeImmutable $cutoff, int $batchSize)`
  - New `lib/Migration/Version1001Date{...}.php` creates the table + indexes on (`app_id`, `created_at`) and (`created_at`); idempotent (guards with `$schema->hasTable`)
  - `AuditEntry::jsonSerialize()` exposes all columns; no setter-based mutation path is reachable from controllers

## Task 2: AuditLogger service (best-effort write path)
- **Spec ref**: specs/audit-trail/spec.md (Requirement: Version operations are recorded — best-effort scenario)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Service/Audit/AuditLogger.php` with `record(string $actorUid, string $appId, string $operation, ...): void`
  - Validates `operation` against `[a-z_]{1,32}`; truncates `message` to a sane bound (e.g. 4000 chars)
  - Entire insert wrapped in try/catch; on failure logs via `LoggerInterface::error` and returns normally
  - Unit test proves a throwing mapper does not propagate out of `record()`

## Task 3: Installer hooks (success + failure, both installers)
- **Spec ref**: specs/audit-trail/spec.md (Requirement: Version operations are recorded)
- **Status**: todo
- **Acceptance criteria**:
  - `SelectedReleaseInstallerService` and `ExternalReleaseInstallerService` record `operation=install` with `from_version` (read before install), `to_version`, canonical `source_id`, and `status`
  - Failure entries written in the outermost error path with the same message returned to the API caller
  - External-install entries include the integrity warning text in `message` when present
  - No PAT/Authorization material can reach `message` (assert via unit test on the external path)

## Task 4: SourceBindingStore hooks
- **Spec ref**: specs/audit-trail/spec.md (Requirement: Source binding changes are recorded)
- **Status**: todo
- **Acceptance criteria**:
  - Explicit `bindSource` endpoint and implicit install-time binding both record `operation=bind_source`
  - Rebind records the previous source id in `message`
  - Binding writes that originate from one logical install produce one `bind_source` entry (no duplicates per install)

## Task 5: Read API
- **Spec ref**: specs/audit-trail/spec.md (Requirement: Audit entries are immutable and admin-readable)
- **Status**: todo
- **Acceptance criteria**:
  - `GET /api/audit?appId=&limit=&offset=` in `ApiController` using the existing admin-gate pattern; non-admin → 403
  - Default limit 50, hard cap 200; newest-first ordering
  - No PUT/PATCH/DELETE audit routes in `appinfo/routes.php`
  - OpenAPI (`openapi.json`) updated for the new endpoint

## Task 6: Retention prune job
- **Spec ref**: specs/audit-trail/spec.md (Requirement: Retention)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Cron/PruneAuditJob.php` extends `OCP\BackgroundJob\TimedJob`, interval 24h, batched deletes (1000/iteration)
  - Reads `app_versions.audit_retention_days` via `IAppConfig`; default 365, clamps to minimum 30 with a logged warning
  - Registered via `<background-jobs>` in `appinfo/info.xml` (NOT via `IRegistrationContext` — there is no `registerJob` API; see fleet gotcha)

## Task 7: History UI
- **Spec ref**: specs/audit-trail/spec.md (Requirement: Audit history UI)
- **Status**: todo
- **Acceptance criteria**:
  - Global History view in the app navigation: paginated table (when/who/app/operation/from→to/source/status), newest-first
  - Per-app History tab in the version picker, filtered by `appId`
  - Failure rows styled with theme CSS variables (NL Design compliant, no hardcoded colors); i18n keys are English source strings
  - Loading/empty/error states present

## Task 8: Tests + stubs
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - Unit tests: AuditLogger (happy path, throwing mapper, operation validation, message truncation), mapper pagination/filter, prune cutoff + clamp, controller 403 for non-admin
  - Installer tests extended: success entry, failure entry, integrity-warning capture, no-secret assertion
  - If any new NC internals are touched, extend `tests/stubs/server-internals.php` accordingly so psalm + the unit-only suite (`tests/phpunit-unit-only.xml`) stay green — note the local stub-staleness gotcha: validate by reasoning + `php -l`, defer deep static analysis to CI
  - `composer check:strict` passes

## Task 9: Browser verification
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - Run migration in the dev container; confirm `oc_app_versions_audit` exists
  - Install a version via the UI → entry appears in History with correct actor/from/to
  - Force a failed install (bad version) → failure entry with message
  - Bind a GitHub source → `bind_source` entry
  - Non-admin user gets 403 on `GET /api/audit`
  - Bump `appinfo/info.xml` `<version>` with the bundle change (NC immutable-cache gotcha)
