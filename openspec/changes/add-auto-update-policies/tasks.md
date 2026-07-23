# Tasks: add-auto-update-policies

## Task 1: PolicyStore + API
- **Spec ref**: specs/auto-update-policies/spec.md (Requirement: Per-app update policy)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Service/Policy/{Policy,PolicyStore}.php` (`policy.{appId}` JSON; malformed → none + log)
  - `GET /api/policies`, `PUT/DELETE /api/app/{appId}/policy` (password-confirmed writes, 400 on invalid level, 403 non-admin)
  - `openapi.json` updated; unit tests: round-trip, invalid level, malformed stored JSON

## Task 2: AutoUpdateJob
- **Spec ref**: specs/auto-update-policies/spec.md (Requirement: Nightly policy execution through the standard installer)
- **Status**: todo
- **Acceptance criteria**:
  - Daily `TimedJob` in `info.xml`; entry checks kill switch + window (midnight-crossing supported)
  - Candidate selection per level (patch/minor/all; pre-release + non-semver rules per design); never downgrades
  - Pin skip (optional-service lookup so build order vs add-version-pinning is flexible)
  - Attempt ledger `auto_attempt.{appId}` (never-retry, pruned to 10); per-app try/catch isolation
  - Installs via `InstallerService::installAppVersion` only
  - Unit tests: selection matrix, window logic, ledger, pin skip, sweep isolation

## Task 3: Outcome notifications
- **Spec ref**: specs/auto-update-policies/spec.md (Requirement: Every auto-update outcome is reported)
- **Status**: todo
- **Acceptance criteria**:
  - `Notifier` subjects `auto_update_success`/`auto_update_failure` (classified hint in failures); no notification on no-op runs
  - Unit tests for both subjects

## Task 4: UI — policy selector + global controls
- **Spec ref**: specs/auto-update-policies/spec.md (Requirements: Per-app update policy; Global kill switch and window)
- **Status**: todo
- **Acceptance criteria**:
  - App-card `NcSelect` policy control (with `inputLabel`), active badge, "automation disabled" hint
  - Kill switch + window field with `HH:MM-HH:MM` validation in the Apps tab settings area
  - Vitest: selector, disabled indication, window validation
