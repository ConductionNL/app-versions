# Tasks: security-advisory-correlation

## 1. Advisory resolution service

- [ ] 1.1 Add `lib/Service/AdvisoryService.php` (SPDX docblock): for each installed app, resolve advisories affecting the installed/pinned version from the app's bound source — NC App Store security info for store-sourced apps, the source adapter's advisory endpoint for external-sourced apps. Reuse the existing source-adapter + PAT/credential path (no new HTTP client, no new secret storage). Read-only, cache-backed. Compute the state `none` | `advisory-available` | `pinned-to-vulnerable` and the nearest resolving version.
  - **spec_ref**: `specs/security-advisory-correlation/spec.md#requirement-the-installedpinned-version-is-correlated-against-known-security-advisories`
  - **acceptance_criteria**:
    - Reuses source-adapter/PAT path; no bespoke HTTP client; read-only (no install/pin mutation)
    - Unit tests: pinned-to-vulnerable, advisory-available, none — store + external sources stubbed

## 2. Surface + notify

- [ ] 2.1 Add the advisory state + advisory detail to the version-list read path; render a per-app badge (with `pinned-to-vulnerable` visually prominent) and a detail with advisory id/severity/summary + recommended safe version. Strings via `t()`; data via the API/`loadState`.
- [ ] 2.2 Add a scheduled refresh job that re-resolves advisories and raises an NC notification when a newly-published advisory affects an installed or pinned version. No auto-update/auto-unpin.
  - **spec_ref**: `specs/security-advisory-correlation/spec.md#requirement-the-admin-is-notified-and-stays-in-control`
  - **acceptance_criteria**:
    - Badge shows the three states; notification fired on new advisory; job performs no version change

## 3. Verify

- [ ] 3.1 `openspec validate security-advisory-correlation --strict` clean; PHPUnit for the service + job green; vitest for the badge; no bespoke HTTP client added; read-only invariant (no install/pin call) verified.
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + tests green; delegation to source-adapter + no-auto-change verified
