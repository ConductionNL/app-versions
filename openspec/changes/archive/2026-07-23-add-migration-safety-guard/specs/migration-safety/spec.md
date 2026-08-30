---
status: proposed
---

# Migration Safety Specification

**Status**: proposed
**Standards**: OCP\App\IAppManager, OCP\IAppConfig, Nextcloud migration framework (OCP\Migration\IMigrationStep)
**Feature tier**: MVP

## Purpose

Nextcloud schema migrations are forward-only; downgrading an app's files cannot undo its schema changes. This capability makes that risk server-enforced and concrete instead of client-suggested and generic: the API refuses downgrades without explicit acknowledgement, names the exact migrations the target version lacks, and maintains a last-known-good version per app so rollback has an evidence-based target. It never claims to make downgrades safe — it makes them informed.

## ADDED Requirements

### Requirement: Server-side downgrade guard [MVP]

When the requested version is lower than the installed version (`version_compare`), `installVersion` MUST refuse with a structured 409 response (category `downgrade_guard`, hint naming both versions) unless the request carries `allowDowngrade: true`. The guard MUST apply to every consumer of the install path (HTTP, CLI, future background jobs) and MUST run server-side before any download. Dry-run requests MUST evaluate and report the guard without requiring the flag.

#### Scenario: API downgrade without acknowledgement

- GIVEN `openregister` installed at 2.5.0
- WHEN `POST .../versions/2.3.0/install` is called without `allowDowngrade`
- THEN the response MUST be 409 with category `downgrade_guard` naming 2.5.0 → 2.3.0
- AND nothing MUST be downloaded or changed

#### Scenario: Acknowledged downgrade proceeds

- WHEN the same request carries `allowDowngrade: true`
- THEN the install MUST proceed through the normal flow (integrity checks, backup, finalize)

#### Scenario: Upgrades are unaffected

- GIVEN installed 2.3.0
- WHEN 2.5.0 is requested without `allowDowngrade`
- THEN the guard MUST NOT trigger

---

### Requirement: Migration diff on downgrade [MVP]

For a downgrade (acknowledged or dry-run), after extracting the target archive and before any file swap, the system MUST compare migration step files (`lib/Migration/Version*.php`) between the installed copy and the target archive and report the steps present in the installed version but absent from the target. The response (and dry-run result) MUST include this list as `orphanedMigrations`; the UI downgrade dialog MUST display it. An empty diff MUST be reported as such ("no schema steps differ"). Diff failure (unreadable archive layout) MUST degrade to the generic warning, never block an acknowledged downgrade.

#### Scenario: Diff names the orphaned steps

- GIVEN installed 2.5.0 contains `Version2040Date20260101000000.php` and target 2.3.0 does not
- WHEN a dry-run downgrade to 2.3.0 runs
- THEN `orphanedMigrations` MUST contain `Version2040Date20260101000000`
- AND the downgrade dialog MUST render the list

#### Scenario: No schema drift

- GIVEN target and installed ship identical migration sets
- WHEN the dry-run runs
- THEN `orphanedMigrations` MUST be an empty list and the UI MUST say no schema steps differ

#### Scenario: Diff failure degrades gracefully

- GIVEN a target archive whose migration directory cannot be read
- WHEN an acknowledged downgrade runs
- THEN the install MUST proceed with the generic schema warning and the response MUST note the diff was unavailable

---

### Requirement: Last-known-good version record [MVP]

After every successful finalize, the system MUST record `lkg.{appId}` (JSON: `version`, `recordedAt` ISO-8601 UTC, `sourceId`) via `IAppConfig`. Failed or reverted installs MUST NOT touch the record. `GET /api/apps` MUST expose the record per app. The UI MUST offer "Roll back to last known good" on apps whose installed version differs from the record; the action MUST route through the standard install flow, inheriting the downgrade guard and migration diff.

#### Scenario: Success updates the record

- GIVEN `openregister` finalizes 2.5.0 successfully
- THEN `lkg.openregister` MUST record version 2.5.0 with timestamp and source

#### Scenario: Failure preserves the record

- GIVEN `lkg.openregister` records 2.5.0
- WHEN an install of 2.6.0 fails and is reverted
- THEN `lkg.openregister` MUST still record 2.5.0

#### Scenario: One-click rollback target

- GIVEN installed 2.6.0 (broken) and `lkg.openregister` = 2.5.0
- WHEN the admin clicks "Roll back to last known good"
- THEN the standard install flow for 2.5.0 MUST start, presenting the downgrade dialog with the migration diff
