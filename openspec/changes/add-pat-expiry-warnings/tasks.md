# Tasks: add-pat-expiry-warnings

## Task 1: Ledger column + migration
- **Spec ref**: specs/pat-management/spec.md (Requirement: PAT expiry warnings)
- **Status**: todo
- **Acceptance criteria**:
  - Additive idempotent migration adds `warned_thresholds` TEXT default `[]` to `app_versions_pats` (style of the `forge` migration)
  - `Pat` entity + mapper expose it; `PatManager::update` clears it when `expiresAt` changes
  - Unit tests: ledger reset on expiry change

## Task 2: PatExpiryWarningJob
- **Spec ref**: specs/pat-management/spec.md (Requirement: PAT expiry warnings)
- **Status**: todo
- **Acceptance criteria**:
  - Daily `TimedJob` in `info.xml`; skips null expiry; highest-crossed-threshold logic (expired > 3d > 14d, lower implied); once per threshold; per-token try/catch
  - Owner-only notification with label, forge, days remaining, renewal deeplink
  - Unit tests: threshold matrix (12 d/2 d/expired/unknown/late-added token), idempotence across runs

## Task 3: Notifier subjects
- **Spec ref**: specs/pat-management/spec.md (Requirement: PAT expiry warnings)
- **Status**: todo
- **Acceptance criteria**:
  - `pat_expiring` + `pat_expired` localized subjects/messages, deeplink as notification link
  - Unit tests for both

## Task 4: expiryState in API + Tokens UI badges
- **Spec ref**: specs/pat-management/spec.md (Requirement: Expiry state in the PAT API and UI)
- **Status**: todo
- **Acceptance criteria**:
  - `GET /api/pats` serialization gains `expiryState` + `daysRemaining`; `openapi.json` updated
  - TokensPanel badges: warning "expires in N days" (≤14 d), error "expired", neutral "expiry unknown"
  - Vitest: four states
