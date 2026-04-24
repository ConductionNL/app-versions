# Tasks — WCAG AA audit + fixes

## Task 1: Automated axe-core scan

- [ ] Add `@axe-core/playwright` as a devDependency.
- [ ] Add a Playwright test file `tests/e2e/accessibility.spec.ts`
  (or wire into the existing Playwright MCP setup under
  `test-accessibility` skill).
- [ ] Exercise the 5 page states from design.md (empty picker, app
  selected, version selected, dialog open, result + debug visible).
- [ ] Capture the axe-core report for each state — store JSON under
  `docs/accessibility/axe-reports/<state>.json`.

## Task 2: Manual screen-reader traversal

- [ ] Run NVDA on Chrome (or VoiceOver on Safari) through the happy
  path: open app → pick app → search → select → install dry-run → read
  result.
- [ ] Record every focusable control + announced label in a traversal
  log under `docs/accessibility.md`.

## Task 3: Fix findings

Apply remediations per component (see design.md for the expected shape;
the actual list comes from Task 1 + Task 2 output):

- [ ] `AppCard.vue` — full-card interaction + keyboard activation.
- [ ] `VersionItem.vue` — `aria-describedby` linkage between degrade
  button and warning paragraph.
- [ ] `VersionPanel.vue` — `aria-live="polite"` region around the
  transition-group.
- [ ] `InstallResultPanel.vue` — `role="status"` on status badge +
  contrast-verified tone backgrounds.
- [ ] `DowngradeConfirmDialog.vue` — `aria-describedby` on dialog
  linking to the warning paragraph.
- [ ] `App.vue` — `aria-label` on the `<main>` wrapper.
- [ ] `SettingsPanel.vue` — verify checked-state announcement; add
  `aria-checked` only if the NVDA traversal shows it's missing.

## Task 4: Regression test

- [ ] Add an axe-core Playwright test that runs in CI and fails if any
  new critical or serious violation appears on any of the 5 page
  states.
- [ ] Wire into `.github/workflows/code-quality.yml` via the
  `enable-accessibility: true` input on the reusable quality workflow
  (if available) or a dedicated `accessibility.yml` workflow.

## Task 5: Docs

- [ ] Create `docs/accessibility.md` documenting: audit date, tool
  versions (axe-core, NVDA), pages audited, findings, remediation
  status.
- [ ] Add a conformance claim at the top:
  "Conforms to WCAG 2.1 level AA as of {date} per axe-core automated
  scan and manual NVDA traversal."
- [ ] Update `docs/adr-audit.md` — flip ADR-010 WCAG AA row from ⚠️ to ✅.
- [ ] Remove the follow-up from `docs/adr-audit.md`'s follow-ups list.
