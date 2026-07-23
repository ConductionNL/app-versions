---
status: implemented
---

# Audit Trail Specification

**Status**: implemented
**Standards**: OCP\AppFramework\Db\QBMapper, OCP\BackgroundJob\TimedJob, OCP\IAppConfig
**Feature tier**: MVP

## Purpose

The audit trail records every version operation App Versions performs — installs (App Store and external, success and failure) and source-binding changes — with who, what, and when. It backs the explicit `docs/intro.md` promise ("Every install, downgrade, or pin is logged with who, what, and when") with a dedicated, immutable, retention-managed table that admins can query without digging through server logs.

## Requirements

### Requirement: Version operations are recorded [MVP]

The system MUST write one audit entry for every install operation executed through App Versions, on both the success and the failure path. Each entry MUST record: actor uid, app id, operation (`install`), version before (`from_version`), requested version (`to_version`), canonical source id, status (`success`/`failure`), an optional message, and a UTC timestamp. The audit write MUST be best-effort: a failed audit insert MUST NOT fail, abort, or roll back the install, and MUST be logged via `LoggerInterface`.

#### Scenario: Successful App Store install is recorded

- GIVEN admin `alice` has `openregister@2.5.0` installed
- WHEN she installs `openregister@2.3.0` from the App Store via App Versions and the install succeeds
- THEN one audit entry MUST exist with `actor_uid=alice`, `app_id=openregister`, `operation=install`, `from_version=2.5.0`, `to_version=2.3.0`, `source_id=appstore`, `status=success`
- AND `created_at` MUST be the operation time in UTC

#### Scenario: Failed install is recorded with the failure reason

- GIVEN a download error occurs during an install attempt (e.g. HTTP 404 on the artifact)
- WHEN the install fails
- THEN one audit entry MUST exist with `status=failure`
- AND `message` MUST contain the same actionable error text returned to the admin
- AND the entry MUST be written even though the installer raised an error

#### Scenario: External install records source and integrity warning

- GIVEN admin `alice` installs `openregister@2.5.0` from `github:ConductionNL/openregister` and no `.sha256` sibling asset exists
- WHEN the install succeeds with `integrityWarning: "No SHA-256 checksum available for this artifact."`
- THEN the audit entry MUST record `source_id=github:ConductionNL/openregister` and `status=success`
- AND `message` MUST contain the integrity warning text

#### Scenario: Audit write failure does not break the install

- GIVEN the audit table is unavailable (e.g. migration not yet run)
- WHEN an admin installs a version and the install itself succeeds
- THEN the install response MUST report success
- AND the audit failure MUST be logged via `LoggerInterface::error`
- AND no exception from the audit path MUST reach the API response

#### Scenario: No secrets in audit entries

- GIVEN an external install for a private repo authenticates with a stored PAT
- WHEN the audit entry is written (success or failure)
- THEN neither the `message` nor any other column MUST contain the PAT value, an `Authorization` header, or any other secret

---

### Requirement: Source binding changes are recorded [MVP]

The system MUST write an audit entry with `operation=bind_source` whenever a source binding is created or overwritten — both via the explicit `POST /api/source/{appId}/bind` endpoint and via the implicit binding performed by an install.

#### Scenario: Explicit bind is recorded

- GIVEN admin `alice` calls `POST /api/source/openregister/bind` with `{kind: "github-release", owner: "ConductionNL", repo: "openregister"}`
- WHEN the binding is persisted
- THEN one audit entry MUST exist with `actor_uid=alice`, `app_id=openregister`, `operation=bind_source`, `source_id=github:ConductionNL/openregister`, `status=success`

#### Scenario: Rebinding records the previous source

- GIVEN `openregister` is bound to `github:ConductionNL/openregister`
- WHEN an admin rebinds it to the App Store
- THEN the audit entry MUST record `source_id=appstore`
- AND `message` MUST name the previous source id `github:ConductionNL/openregister`

---

### Requirement: Audit entries are immutable and admin-readable [MVP]

The system MUST expose audit entries through `GET /api/audit` — admin-only, paginated (default 50, maximum 200 per page), newest-first, filterable by `appId`. The system MUST NOT expose any endpoint that updates or deletes audit entries; the retention prune job is the only deletion path.

#### Scenario: Admin lists the audit log

- GIVEN 75 audit entries exist
- WHEN an admin calls `GET /api/audit`
- THEN the response MUST contain the 50 newest entries, newest-first
- AND `GET /api/audit?offset=50` MUST return the remaining 25

#### Scenario: Filter by app

- GIVEN audit entries exist for `openregister` and `calendar`
- WHEN an admin calls `GET /api/audit?appId=openregister`
- THEN every returned entry MUST have `app_id=openregister`

#### Scenario: Non-admin is blocked

- GIVEN a non-admin authenticated user
- WHEN they call `GET /api/audit`
- THEN the system MUST respond with HTTP 403
- AND no audit data MUST be returned

#### Scenario: No mutation endpoints exist

- GIVEN any authenticated user, including an admin
- WHEN they attempt `PUT`/`PATCH`/`DELETE` against `/api/audit` or `/api/audit/{id}`
- THEN the router MUST NOT expose such a route (404)
- AND no code path outside the retention prune job MUST modify or delete audit rows

---

### Requirement: Audit history UI [MVP]

The system MUST present the audit trail in the admin UI: a global history view and a per-app history tab in the version picker. Each row MUST show timestamp, actor, app, operation, from→to versions, source, and status; failed operations MUST be visually distinct using theme CSS variables (no hardcoded colors).

#### Scenario: Global history view

- GIVEN audit entries exist for multiple apps
- WHEN the admin opens the History section
- THEN entries MUST be listed newest-first across all apps
- AND each row MUST show when, who, app, operation, from→to, source, and status
- AND additional pages MUST be loadable without a full page reload

#### Scenario: Per-app history tab

- GIVEN the admin opened the version picker for `openregister`
- WHEN they switch to the History tab
- THEN only entries with `app_id=openregister` MUST be shown
- AND a rollback entry (from 2.5.0 to 2.3.0) MUST be readable as a downgrade at a glance

---

### Requirement: Retention [MVP]

The system MUST prune audit entries older than `app_versions.audit_retention_days` (default 365, minimum 30 — lower configured values MUST be clamped to 30) via a daily background job. Entries within the window MUST NOT be pruned by count.

#### Scenario: Old entries are pruned

- GIVEN `audit_retention_days` is unset (default 365)
- AND an audit entry is 400 days old
- WHEN the daily prune job runs
- THEN that entry MUST be deleted
- AND entries newer than 365 days MUST remain

#### Scenario: Retention floor is enforced

- GIVEN an admin sets `audit_retention_days` to `7`
- WHEN the prune job runs
- THEN entries newer than 30 days MUST NOT be deleted
- AND the clamping MUST be logged

## User Stories

1. As a Nextcloud admin, I want to see who rolled an app back, to which version, and when, so that a Friday-evening rollback is visible Monday morning.
2. As a security officer, I want failed install attempts and unsigned-source installs (with their integrity warnings) on record, so that reviews don't depend on rotated server logs.
3. As a Nextcloud admin, I want the trail to be immutable, so that the record is trustworthy even when the actor is also an admin.

## Acceptance Criteria

- [ ] `app_versions_audit` table created by migration; indexed on (`app_id`, `created_at`) and `created_at`
- [ ] Both installers (App Store + external) write entries on success and failure
- [ ] Bind/rebind writes `bind_source` entries (explicit endpoint and implicit install binding)
- [ ] Audit insert failure never fails an install; it is logged
- [ ] `GET /api/audit` is admin-only, paginated, filterable by `appId`; no mutation routes
- [ ] Global History view + per-app History tab render the trail
- [ ] Daily prune job enforces `audit_retention_days` (default 365, floor 30)
- [ ] No secrets ever stored in audit rows
- [ ] `composer check:strict` passes; PHPUnit suite passes

## Notes

- Operation vocabulary is an open string set (`[a-z_]{1,32}`); this spec defines `install` and `bind_source`. The `add-version-pinning` change extends it with `pin`, `unpin`, `pin_drift` without schema changes.
- Downgrade vs upgrade is derived from comparing `from_version`/`to_version`; no separate operation value is needed.
- This app deliberately stores audit state locally (no OpenRegister) — it wraps NC installer internals and keeps its DB surface minimal (`pats` + `audit`).
