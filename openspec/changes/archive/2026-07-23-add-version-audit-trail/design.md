# Design: add-version-audit-trail

## Architecture overview

```
SelectedReleaseInstallerService ──┐
ExternalReleaseInstallerService ──┼──► AuditLogger::record(AuditEntry)   (best-effort, never throws out)
SourceBindingStore (bind/rebind) ─┘            │
                                               ▼
                                      AuditEntryMapper ──► oc_app_versions_audit
                                               ▲
ApiController::auditLog ◄── GET /api/audit ────┘   (admin-only, paginated, read-only)
                                               ▲
PruneAuditJob (TimedJob, daily) ───────────────┘   (delete WHERE created_at < now - retention)
```

## Table: `app_versions_audit`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint, autoincrement, PK | |
| `actor_uid` | string(64) | NC uid of the admin who triggered the operation; `system` for background-job writes (future) |
| `app_id` | string(255) | target app |
| `operation` | string(32) | `install`, `bind_source` — extensible vocabulary, see below |
| `from_version` | string(64), nullable | installed version before the operation (`''` for first install) |
| `to_version` | string(64), nullable | requested/target version |
| `source_id` | string(255), nullable | canonical source id (`appstore`, `github:owner/repo`) — `SourceBinding::getId()` shape |
| `status` | string(16) | `success` / `failure` |
| `message` | text, nullable | failure reason or integrity warning text; NEVER tokens/secrets |
| `created_at` | datetime | UTC |

Index on (`app_id`, `created_at`) for the per-app tab; index on `created_at` for pruning and the global list.

### Operation vocabulary

Open string set, validated to `[a-z_]{1,32}`. This change writes `install` (covers upgrade, downgrade, reinstall — direction is derivable from `from_version`/`to_version`) and `bind_source` (covers bind + rebind; `message` carries the previous source id on rebind). `add-version-pinning` adds `pin`, `unpin`, `pin_drift` via a delta on this capability — the schema and read API need no change for that.

## Write path: best-effort by construction

`AuditLogger::record()` wraps the mapper insert in try/catch and logs (via `LoggerInterface::error`) on failure. Rationale: the installers' backup-and-restore flow is the most security-critical code in the app; an audit INSERT failing (table missing mid-upgrade, DB hiccup) must not abort or roll back an otherwise successful install, and must not mask the real error on a failed one. The reverse trade-off (refusing to install when auditing is down) is not what the docs promise and would turn a logging bug into an outage.

Failure-path hook placement: in the installers' top-level catch — record `status=failure` with the exception message **before** rethrowing/returning the error response, so failed attempts are always visible.

## Why a DB table and not `nextcloud.log`

The intro.md promise is explicitly "without digging through server logs". Server logs rotate, mix all apps, and are not queryable per app/actor. A dedicated table gives stable retention, pagination, and per-app filtering. This app is otherwise DB-light (one `pats` table) — one more small table stays within its "no OpenRegister, NC internals only" architecture.

## Immutability

No update/delete endpoints exist; the entity has no setters exposed via API. The only deletion path is the retention prune job. This is what makes the trail useful for accountability — an admin who rolled something back cannot edit the record.

## Retention

`app_versions.audit_retention_days` (IAppConfig, default `365`, minimum `30` — values below are clamped and logged). A daily `TimedJob` (`lib/Cron/PruneAuditJob.php`, registered via `background-jobs` in `info.xml`) deletes entries older than the window in batches of 1000 to avoid long transactions.

## API

`GET /api/audit?appId=&limit=&offset=` — admin-gated like every other endpoint (`adminCheck` pattern in `ApiController`), `limit` capped at 200, default 50, newest-first. Response entries serialize all columns; `message` is plain text (already secret-free by the write-path rule).

## UI

- Global **History** section in the app's navigation: table of entries (when / who / app / operation / from→to / source / status), newest-first, "load more" pagination.
- Per-app **History** tab in the version picker, same component filtered by `appId`.
- Failure rows visually distinct (error color token via NL Design CSS variables, no hardcoded colors).

## Risks

| Risk | Mitigation |
| --- | --- |
| Audit write slows/breaks installs | Best-effort try/catch around the single `record()` call; one INSERT per operation |
| Secrets leak into `message` | Write-path rule: only exception messages and integrity-warning strings already shown in API responses are stored; PAT values never flow through installer error paths (they live inside `PatManager::useToken`) |
| Unbounded table growth | Daily prune job + retention default 365 days |
| Failed installs unlogged because exception bubbles before hook | Hook lives in the installers' outermost catch, not in the happy path only |
