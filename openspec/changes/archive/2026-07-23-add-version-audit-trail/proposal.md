# Proposal: add-version-audit-trail

## Summary
Add a persistent, immutable audit trail of every version operation App Versions performs — installs (App Store and external), rollbacks, failures, and source-binding changes — recording who did it, what changed, and when. Expose the trail through an admin-only read API and a history view in the UI, with a configurable retention window enforced by a background job.

## Motivation
`docs/intro.md` explicitly promises:

> **Audit-trailed.** Every install, downgrade, or pin is logged with who, what, and when — so a Friday-evening rollback by an on-call admin is visible Monday morning without digging through server logs.

Nothing backs this today. There is no audit service, no audit table (the only migration creates `app_versions_pats`), and no spec mentions audit or history. For an app whose whole purpose is letting admins replace running code on a production instance — including from unsigned external sources — the operation log is the compliance story, not a nice-to-have:

- **Accountability.** Multiple admins share an instance; "who rolled openregister back to 2.3.0 on Friday?" must be answerable without grepping `nextcloud.log` (which rotates and mixes in unrelated noise).
- **Incident forensics.** A failed install that left an integrity warning ("no SHA-256 available") is exactly the record a security review needs later.
- **Drift explanation.** When the installed version differs from what anyone remembers choosing, the trail shows the sequence of operations that got there.

Every comparable tool in the category (apt history.log, dnf history, watchtower notifications, Renovate PR trail) keeps an operation history; a version manager without one is below table stakes.

## Scope
- `lib/Db/AuditEntry.php` + `lib/Db/AuditEntryMapper.php` + migration creating `app_versions_audit` (follows the `app_versions_pats` naming convention)
- `lib/Service/Audit/AuditLogger.php` — single write path, best-effort (an audit write failure must never fail or roll back an install)
- Write hooks in `SelectedReleaseInstallerService`, `ExternalReleaseInstallerService` (success **and** failure paths, including integrity warnings), and `SourceBindingStore` (bind / rebind)
- Read API: `GET /api/audit` (admin-only, paginated, filterable by `appId`); no update or delete endpoints — entries are immutable
- UI: a "History" view (global, newest-first) and a per-app history tab in the version picker
- Retention: `app_versions.audit_retention_days` (default 365), enforced by a daily `TimedJob` prune; entries are never pruned by count alone
- Update `docs/intro.md` only if behavior ends up narrower than the promise (target: it does not)

## Out of scope
- Pin / unpin / drift audit operations — the operation vocabulary is extensible, and the pin operations are added by the companion change `add-version-pinning` (delta on this capability)
- PAT lifecycle auditing (create/delete of tokens) — PATs already have `last_used_at`; a fuller credential audit is future work
- Nextcloud Activity / Notification integration — this is domain state with its own admin surface, not generic activity (per the fleet convention); a notification on *drift* lives in `add-version-pinning`
- Auditing app updates performed **outside** App Versions (NC's own updater) — detection of those belongs to pin drift detection in `add-version-pinning`

## Dependencies
None. This change is self-contained; `add-version-pinning` depends on it (it extends the operation vocabulary), not the reverse.
