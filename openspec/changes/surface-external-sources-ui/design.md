## Context

App Versions has a full external-source backend — `SourceRegistry`, `SourceBinding`, `TrustedSourceList`, PAT storage/validation, and the controller endpoints `GET /api/sources`, `POST /api/source/{appId}/bind`, `GET /api/source/{appId}/binding`, the PAT CRUD set, and `GET /api/pats/deeplink`. None of these are reachable from the UI: `src/App.vue` is a ~2060-line single-file SPA that only consumes `/api/admin-check`, `/api/update-channel`, `/api/apps`, and `/api/app/{appId}/versions(/install)`. It imports `NcContent`/`NcAppContent`/`NcDialog` and is mostly custom-styled with no tab structure.

This change surfaces that machinery in the admin Settings panel. It assumes two prerequisite changes are already applied:

- `move-to-admin-settings` (#1) — the app is mounted inside a Nextcloud admin Settings section (no top-nav, no page route).
- `codeberg-forge-support` (#2) — a generic forge abstraction with Codeberg as forge #2: a `forge` field on `SourceBinding`/`Pat`, forge-qualified allowlist patterns (`github:owner/repo`, `codeberg:owner/repo`), and forge-aware token validation/deeplinks.

Constraints: this app has **no database** for its own state (allowlist lives in app config via `IAppConfig`); writes are admin-only and password-confirmed; the allowlist is the trust boundary (adr-007). Stakeholders: Conduction admins managing self-hosted app installs from GitHub and Codeberg.

## Goals / Non-Goals

**Goals:**
- Tabbed admin UI (Apps / Sources / Tokens / Trusted sources) with the existing apps→versions→install view as the default tab.
- `SourcesPanel.vue`, `TokensPanel.vue`, `TrustedSourcesPanel.vue` wired to existing endpoints; forge (github|codeberg) selectable in every form.
- One genuine backend addition: admin-only, password-confirmed trusted-allowlist write endpoints that wrap `TrustedSourceList::setPatterns()`, with curated pattern construction and over-broad-glob rejection.
- Shell adaptation so the SPA renders cleanly inside Settings → Administration (drop/replace `NcContent`/`NcAppContent`).
- All new strings translatable (adr-005); standard NC Vue components, no hardcoded colors, WCAG AA (adr-003).

**Non-Goals:**
- Discovery/search UI (`app-discovery` is untouched; `/api/discover` remains UI-unused) — a later change.
- OAuth auth (deferred; PAT-style access tokens only).
- New DB tables, migrations, or OpenRegister schemas.
- **Seed data — ADR-016 is N/A** for this change (no seedable entities; the only persisted state is the config-backed allowlist, managed interactively). No Seed Data section, no seed task.

## Decisions

**D1 — Tab structure: in-component tab bar over Nc navigation.** Inside a single admin settings panel, an in-`App.vue` tab/section switcher (NcButton group or NcCheckboxRadioSwitch `type="button"` segmented control, or a simple `currentTab` ref with NcAppNavigation-free layout) is simpler than wiring Nextcloud navigation, which is geared to full-app shells we are removing. Alternative considered: `NcAppNavigation` — rejected because #1 removed the top-nav/page-route context and Settings panels are not full app shells.

**D2 — Shell adaptation: drop `NcContent`/`NcAppContent`.** These render the full app chrome (navigation rail + content) and look wrong embedded in a settings panel. Replace with a plain settings-section container (`<div class="section">` per NC settings conventions) wrapping the tab bar + active panel. Alternative: context detection (render shell only outside settings) — rejected as unnecessary complexity now that #1 fixes the mount to settings.

**D3 — Component composition (adr-012).** Extract each config area into its own child component so `App.vue` keeps the existing apps/versions logic and delegates the rest. Panels receive the admin/forge context as props and own their own fetch state, emitting events on mutation so `App.vue` can refresh shared data (e.g. re-load `/api/sources` after an allowlist change).

**D4 — Trusted-allowlist write API shape.** Add to `ApiController`:
- `POST /api/trusted-sources` — body `{forge, owner, repo?}`; server constructs `{forge}:{owner}/{repo}` when `repo` is present, else `{forge}:{owner}/*`; appends to the existing patterns and persists via a new `InstallerService::addTrustedPattern()` delegating to `TrustedSourceList::setPatterns()`.
- `DELETE /api/trusted-sources/{pattern}` — the pattern is supplied as a **URL-encoded path parameter** (the embedded `/` and `:` are percent-encoded by the client, e.g. `codeberg%3AConduction%2Fopenregister`); `InstallerService::removeTrustedPattern()` removes the exact match and persists.
Both `#[PasswordConfirmationRequired(strict: false)]`, admin-gated, mirroring `bindSource`/`createPat`. Listing reuses the existing `GET /api/sources` `trustedPatterns` field (no dedicated GET added). The DELETE route must accept an encoded path segment — register it so the encoded `{pattern}` is captured intact and decoded server-side before matching.

**D5 — Over-broad-glob rejection (the trust boundary).** Validation lives in `InstallerService` (adr-008 layering: controller → service → `TrustedSourceList`), returning 400/422 with a clear message. Reject when: forge is unknown; owner is empty or exactly `*`; the resulting pattern is `*`, `*/*`, or `{forge}:*`; owner/repo do not match the same safe charset `SourceBinding` enforces (`[A-Za-z0-9_.\-]+`). A concrete owner is always required. This guarantees the curated path can never trust an entire forge or everything.

**D6 — Token `targetPattern` derived, not free-text.** In `TokensPanel.vue` the PAT `targetPattern` is derived from the chosen forge + owner (`owner/*`) with an optional repo, mirroring the curated allowlist UX, rather than exposing a raw glob field — fewer footguns and consistent with D5. The backend `createPat` already accepts `targetPattern`, so this is a UI-only decision. (Recorded as a deferred question; provisional choice = derived.)

**D7 — Forge list source.** Forge options come from `GET /api/sources` `listAvailable()` (post-#2 it includes both github and codeberg), so the UI does not hardcode the forge set beyond labels.

## Risks / Trade-offs

- [Prerequisites not yet implemented] → Specs and tasks are written assuming #1 and #2 have landed; tasks.md notes the dependency. If applied out of order, the forge field and codeberg source will be missing and the panels degrade to github-only.
- [Large monolithic `App.vue`] → Extracting panels touches a 2060-line file; risk of regressing the existing apps/versions flow. Mitigation: leave the apps/versions view logic in place, wrap it as the default tab, and add panels alongside; rely on `npm run build`/`lint` + the existing flow's manual verification.
- [Shell swap visual regression] → Removing `NcContent`/`NcAppContent` could change layout. Mitigation: adopt NC settings-section markup and verify in Settings → Administration manually.
- [Allowlist write is a trust boundary] → A bug could let an over-broad glob through. Mitigation: centralize validation in the service, password-confirm + admin-gate the endpoints, and unit-test the rejection set (`*`, `*/*`, `{forge}:*`, empty/`*`-only owner, bad charset) plus non-admin Forbidden.
- [No DB; concurrent allowlist writes] → Two admins editing the allowlist could race (read-modify-write on a single config string). Acceptable for an admin-only utility; last-write-wins, no locking added.

## Migration Plan

No data migration (no DB, no seed data). Deploy is code-only: ship the new controller endpoints + service methods and the rebuilt frontend bundle (`npm run build`). Apply after #1 and #2. Rollback = revert the commit and rebuild; the allowlist config key is unchanged in shape (still a JSON array of patterns), so no config rollback is needed.

## Open Questions

See `DEFERRED_QUESTIONS` in tasks.md for resolved-with-provisional-choice items (DELETE shape, dedicated GET vs reuse, tab mechanism, token targetPattern entry). No blocking unknowns remain.
