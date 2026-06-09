## 0. Prerequisites

- [x] 0.1 Confirm `move-to-admin-settings` (#1) is applied — the app mounts inside Settings → Administration (no top-nav/page route)
- [x] 0.2 Confirm `codeberg-forge-support` (#2) is applied — `forge` field on SourceBinding/Pat, codeberg source registered, forge-qualified allowlist patterns, forge-aware token validation/deeplink

## 1. Backend — trusted-allowlist write API

- [x] 1.1 Add `addTrustedPattern(string $forge, string $owner, ?string $repo): void` to `lib/Service/InstallerService.php` — construct `{forge}:{owner}/{repo}` (or `{forge}:{owner}/*` when repo is null), validate, append to existing patterns, persist via `TrustedSourceList::setPatterns()`
- [x] 1.2 Add `removeTrustedPattern(string $pattern): void` to `InstallerService` — remove the exact pattern from the current set and persist via `setPatterns()`
- [x] 1.3 Implement over-broad-glob validation in `InstallerService` (adr-008 layering): reject unknown forge; empty or `*`-only owner; resulting patterns `*`, `*/*`, `{forge}:*`; owner/repo not matching `[A-Za-z0-9_.\-]+`. Throw a typed/`InvalidArgumentException` with a clear message
- [x] 1.4 Add `POST /api/trusted-sources` to `lib/Controller/ApiController.php` — admin-gated, `#[PasswordConfirmationRequired(strict: false)]`, body `{forge, owner, repo?}`; map validation failures to HTTP 400/422, success returns the updated `trustedPatterns`
- [x] 1.5 Add `DELETE /api/trusted-sources/{pattern}` to `ApiController` — admin-gated, password-confirmed; pattern is a URL-encoded path param (client percent-encodes the `/` and `:`), decoded server-side before matching; ensure the route captures the encoded segment intact; returns the updated `trustedPatterns`
- [x] 1.6 Confirm listing reuses existing `GET /api/sources` `trustedPatterns` (no dedicated GET added)

## 2. Backend — tests (adr-009)

- [x] 2.1 Unit test: curated add of `{forge:codeberg, owner:Conduction, repo:openregister}` persists `codeberg:Conduction/openregister`
- [x] 2.2 Unit test: owner-only add persists `{forge}:owner/*`
- [x] 2.3 Unit test: dangerous globs rejected — `*`, `*/*`, `{forge}:*`, empty owner, owner `*`, unknown forge, bad charset
- [x] 2.4 Unit test: remove deletes exactly the given pattern and persists
- [x] 2.5 Unit test: non-admin gets HTTP 403 from both write endpoints and the allowlist is unchanged

## 3. Frontend — shell + tab structure

- [x] 3.1 In `src/App.vue` replace `NcContent`/`NcAppContent` with a settings-section container that renders cleanly inside the admin Settings panel
- [x] 3.2 Add an in-component tab/section switcher (Apps / Sources / Tokens / Trusted sources) with `currentTab` state; default to Apps
- [x] 3.3 Wrap the existing apps → versions → install view as the Apps tab, preserving its current behaviour

## 4. Frontend — SourcesPanel

- [x] 4.1 Create `src/components/SourcesPanel.vue`; on app select, show current binding from `GET /api/source/{appId}/binding`
- [x] 4.2 Add bind form: forge select (github|codeberg from `GET /api/sources` `listAvailable`), owner, repo, optional assetPattern → `POST /api/source/{appId}/bind` (password-confirmed)
- [x] 4.3 On successful bind, refresh the binding display and the app's version list

## 5. Frontend — TokensPanel

- [x] 5.1 Create `src/components/TokensPanel.vue`; list redacted PATs from `GET /api/pats`
- [x] 5.2 Add-token form: forge select, label, target (owner [+ optional repo] → derived `targetPattern`), token field → `POST /api/pats`
- [x] 5.3 Edit label / share toggle → `PATCH /api/pats/{id}`; delete → `DELETE /api/pats/{id}` (both password-confirmed)
- [x] 5.4 Per-forge "create a token" button → `GET /api/pats/deeplink?forge=…`; show returned url + instructions

## 6. Frontend — TrustedSourcesPanel

- [x] 6.1 Create `src/components/TrustedSourcesPanel.vue`; list current forge-qualified patterns from `GET /api/sources` `trustedPatterns`
- [x] 6.2 Curated add: forge select + owner [+ optional repo], explicit "I trust this source" confirmation → `POST /api/trusted-sources` (password-confirmed)
- [x] 6.3 Surface backend rejection messages (e.g. `*/*` refused) in the UI via NcNoteCard
- [x] 6.4 Remove a pattern → `DELETE /api/trusted-sources/{pattern}` with the URL-encoded pattern (password-confirmed); refresh the list

## 7. Frontend — quality (adr-003, adr-005, adr-012)

- [ ] 7.1 Use standard NC Vue components (NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcDialog, NcNoteCard); no hardcoded colors; WCAG AA
- [ ] 7.2 Wrap all new user-facing strings in `t('app_versions', …)` (adr-005)
- [x] 7.3 Frontend mount/render check if a harness exists; at minimum ensure the app compiles with `vite build`

## 8. Quality gates (run in the container per project convention)

- [x] 8.1 `composer cs:check`
- [x] 8.2 `composer psalm`
- [x] 8.3 `composer test:unit` (NOTE: this app has NO `composer check:strict` — use individual scripts)
- [x] 8.4 `npm run build`
- [ ] 8.5 `npm run lint`

## 9. Manual verification

- [ ] 9.1 Open Settings → Administration → App Versions and switch between all tabs
- [ ] 9.2 Sources tab: bind a Codeberg repo to an app and confirm its version list loads
- [ ] 9.3 Trusted sources tab: add a curated source, confirm it persists; attempt to add `*/*` and confirm it is rejected with a clear message; remove a pattern
- [ ] 9.4 Tokens tab: add a token (with forge selection), edit its label/share, delete it; open a per-forge deeplink
- [ ] 9.5 Confirm the existing apps → versions → install flow still works on the default tab

## Notes

- Depends on `move-to-admin-settings` (#1) and `codeberg-forge-support` (#2) being applied first.
- ADR-016 (seed data) is N/A — no seedable entities; no Seed Data task.

## DEFERRED_QUESTIONS

- **DELETE endpoint shape for removing a pattern** — RESOLVED (user): pattern as a **URL-encoded path param** (`DELETE /api/trusted-sources/{pattern}`); the client percent-encodes the `/` and `:`, the server decodes before matching. Affected: `external-sources` spec, `ApiController`, TrustedSourcesPanel.
- **Dedicated `GET /api/trusted-sources` vs reuse `GET /api/sources`** — provisional choice: **reuse** `GET /api/sources` (`trustedPatterns`), no new GET. Affected: `external-sources` spec, frontend fetch wiring.
- **Tab mechanism (in-component tab bar vs Nc navigation)** — provisional choice: **in-component tab bar** inside the settings container, since #1 removed the full app-shell/nav context. Affected: `version-management` spec, `App.vue`.
- **Token `targetPattern` entry (free-text vs derived)** — provisional choice: **derived** from forge + owner (`owner/*`, optional repo), mirroring the curated allowlist UX, to avoid raw-glob footguns. Affected: `pat-management` spec, TokensPanel (UI-only; backend `createPat` already accepts `targetPattern`).
