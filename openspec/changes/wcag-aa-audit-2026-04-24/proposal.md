# WCAG AA audit + fixes

Closes one of the three partial items flagged in `docs/adr-audit.md`
after the template + ADR cleanup PR: deep WCAG AA audit was skipped.

## Problem

ADR-010 ("NL Design System") requires Conduction apps to meet WCAG AA.
The cleanup PR verified the obvious surface — form controls are
labelled, keyboard focus works, the 7 Vue components use CSS custom
properties — but no structured a11y pass was run. Likely gaps:

- Status-tone badges in `InstallResultPanel` (green/yellow/red) — may
  fail contrast ratios against their text colour.
- `AppCard` clickable cards — without explicit `role="button"` or
  `tabindex`, screen-reader traversal may skip them entirely.
- `VersionItem`'s two-button action group — the Degrade variant uses
  red backgrounds that may fail contrast for the label text.
- The transition-group wrapping the version list — `aria-live` region
  missing, so screen readers don't announce version-filter changes.
- `DowngradeConfirmDialog` — NcDialog handles focus trap, but the
  destructive "Downgrade" button may not announce its destructive
  semantics beyond the visual red.

## Scope

- Automated scan via `@axe-core/playwright` against the admin UI
  rendered in a test Nextcloud server.
- Manual screen-reader traversal with NVDA (Windows) or VoiceOver (macOS)
  — capture findings as an issue list.
- Fix every **critical** and **serious** finding in the 7 Vue
  components + App.vue.
- Document the audit outcome under `docs/accessibility.md` with the
  date, tool versions, and conformance claim.
- Add axe-core regression test to the Newman/Playwright suite so
  future regressions fail CI.

## Not in scope

- Colour-scheme theming changes (relies on Nextcloud's own tokens).
- AAA conformance — AA is the target per ADR-010.
- Mobile / touch-specific accessibility — this is an admin-only
  desktop interface.

## Acceptance

1. `docs/accessibility.md` exists and documents: tool versions, pages
  audited, findings list, remediation status per finding.
2. axe-core reports zero critical or serious findings across every page
  state (empty picker, app selected, version selected, dialog open,
  result panel visible with debug).
3. Manual screen-reader traversal captures a full happy-path flow
  (pick app → pick version → dry-run install → view result) with no
  unlabelled controls and no focus traps.
4. A Playwright + axe-core test runs in CI and fails when a new
  regression lands.
