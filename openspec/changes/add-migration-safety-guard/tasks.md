# Tasks: add-migration-safety-guard

## Task 1: Downgrade guard + failure category
- **Spec ref**: specs/migration-safety/spec.md (Requirement: Server-side downgrade guard)
- **Status**: done
- **Acceptance criteria**:
  - Guard in `InstallerService::installAppVersion` before dispatch/download; `allowDowngrade` service flag plumbed from HTTP param
  - `FailureClassifier` category `downgrade_guard` → 409 + hint naming both versions
  - Dry-run evaluates and reports the guard without requiring the flag
  - Unit tests: up/down/equal × flag matrix

## Task 2: Migration diff
- **Spec ref**: specs/migration-safety/spec.md (Requirement: Migration diff on downgrade)
- **Status**: done
- **Acceptance criteria**:
  - Both installers compute installed−target `Version*.php` diff post-extraction on downgrades (and dry-runs)
  - Result carries `orphanedMigrations: string[]|null`; `null` + warning when diff unavailable; failure never blocks an acknowledged downgrade
  - Unit tests with fixture archives: non-empty diff, empty diff, unreadable layout

## Task 3: Last-known-good record
- **Spec ref**: specs/migration-safety/spec.md (Requirement: Last-known-good version record)
- **Status**: done
- **Acceptance criteria**:
  - `InstallFinalizer` success path writes `lkg.{appId}` JSON (`version`, `recordedAt`, `sourceId`); failures/reverts never write
  - `GET /api/apps` exposes `lkg` per app; `openapi.json` updated
  - Unit tests: write-on-success, preserve-on-failure, malformed stored JSON treated as absent

## Task 4: UI — informed downgrade dialog + lkg action
- **Spec ref**: specs/migration-safety/spec.md (Requirements: Migration diff on downgrade; Last-known-good version record)
- **Status**: done
- **Acceptance criteria**:
  - Downgrade dialog lists `orphanedMigrations` (or "no schema steps differ" / generic warning when null); confirm sets `allowDowngrade: true`
  - "Roll back to last known good" appears when `lkg.version !== installedVersion`, routes through the standard picker + confirmation
  - Vitest: diff rendering states, action visibility, request carries the flag
