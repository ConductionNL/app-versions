---
status: proposed
---

# Audit Trail — Pin Operations Delta

**Status**: proposed
**Depends on**: `add-version-audit-trail` (defines the `audit-trail` capability, table, and read API)

## Purpose

Extend the audit-trail operation vocabulary with the pin lifecycle so the `docs/intro.md` promise ("every install, downgrade, **or pin** is logged with who, what, and when") is fully covered. No schema or API change — the audit table and `GET /api/audit` already carry these entries.

## ADDED Requirements

### Requirement: Pin lifecycle operations are audited [MVP]

The system MUST write audit entries for pin operations: `pin` (on pin creation, including install-then-pin and `overridePin=repin`), `unpin` (on pin removal, including `overridePin=unpin` and Accept→remove), and `pin_drift` (on newly detected drift, with `actor_uid=system`, `from_version` = pinned version, `to_version` = observed version). All writes follow the audit capability's best-effort rule.

#### Scenario: Pin is audited

- GIVEN admin `alice` pins `openregister` at 2.3.0
- WHEN the pin is persisted
- THEN one audit entry MUST exist with `actor_uid=alice`, `app_id=openregister`, `operation=pin`, `to_version=2.3.0`, `status=success`

#### Scenario: Unpin is audited

- GIVEN `openregister` is pinned at 2.3.0
- WHEN admin `alice` unpins it
- THEN one audit entry MUST exist with `operation=unpin`, `from_version=2.3.0`, `status=success`

#### Scenario: Drift is audited as system action

- GIVEN `openregister` pinned at 2.3.0 drifts to 2.5.0 via Nextcloud's own updater
- WHEN the drift handler records the drift
- THEN one audit entry MUST exist with `actor_uid=system`, `operation=pin_drift`, `from_version=2.3.0`, `to_version=2.5.0`
- AND repeated reconcile runs for the same drifted version MUST NOT create additional `pin_drift` entries
