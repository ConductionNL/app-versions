# Tasks — `CnIndexPage` composition

## Task 1: Verify `CnIndexPage` slot contract

- [ ] Read `@conduction/nextcloud-vue`'s `CnIndexPage.vue`
  (`../nextcloud-vue/src/CnIndexPage.vue` in the monorepo) and confirm
  the three slots exist with the expected names
  (`header-title`, `header-actions`, default).
- [ ] If the slot names differ, update design.md + subsequent tasks
  before proceeding.

## Task 2: Capture pre-refactor screenshots

- [ ] Boot the dev server (`npm run dev` + dev Nextcloud).
- [ ] Capture screenshots via Playwright MCP at the 5 canonical states
  (empty picker, app selected, version selected, dialog open,
  result visible).
- [ ] Store under `docs/composition/pre/` for review diff.

## Task 3: Swap imports in `App.vue`

- [ ] Replace `import { NcAppContent, NcContent } from '@conduction/nextcloud-vue'`
  with `import { CnIndexPage } from '@conduction/nextcloud-vue'`.
- [ ] If `CnIndexPage` is not yet exported from
  `@conduction/nextcloud-vue`'s main entry, add the import alias or
  open a small PR on `nextcloud-vue` first to expose it.

## Task 4: Restructure the template

- [ ] Replace `<NcContent><NcAppContent>...</NcAppContent></NcContent>`
  with `<CnIndexPage>...</CnIndexPage>`.
- [ ] Move the `<h2>{{ t('...', 'App Versions') }}</h2>` into a
  `#header-title` slot.
- [ ] Evaluate `SettingsPanel` fit (design.md criterion). If it fits,
  put it in `#header-actions`; otherwise leave it in the body.
- [ ] Keep `AppPicker` / `VersionPanel` / `InstallResultPanel` in the
  default slot in their current order.
- [ ] Move `<DowngradeConfirmDialog>` to a sibling of `CnIndexPage`
  (still inside `<template>`) so it renders via NcDialog's own portal.

## Task 5: Prune dead CSS

- [ ] Remove `.layout`, `.mainContent`, `.contentRow`, `.contentRowSplit`,
  `.leftColumn`, `.leftColumnFull`, `.rightColumn` from `App.vue`'s
  `<style module>`.
- [ ] If the default slot needs a body flex layout, keep a minimal
  `.body` + `.sideBySide` rule — but prefer utility classes from
  `@conduction/nextcloud-vue` if available.
- [ ] Verify every remaining `$style.X` reference in `App.vue` has a
  matching `.X` in `<style module>` (and vice-versa).

## Task 6: Visual regression

- [ ] Capture post-refactor screenshots at the same 5 states under
  `docs/composition/post/`.
- [ ] Diff side-by-side. Intentional deltas (header padding, title
  typography) document as "ADR-017 alignment". Unintentional deltas
  (broken layout, missing panel) are bugs.
- [ ] Attach the diff to the PR body.

## Task 7: Re-run axe-core

- [ ] Run the axe-core regression test from the
  `wcag-aa-audit-2026-04-24` change (if it has landed) or spot-check
  axe-core against the post-refactor states.
- [ ] If the audit change has NOT landed yet, run axe-core once and
  record any findings in a "known gaps" note so the audit change
  inherits a current baseline.

## Task 8: Docs

- [ ] Update `docs/architecture.md` — the component diagram should now
  show `CnIndexPage` as the top-level shell instead of `NcContent`.
- [ ] Update `docs/adr-audit.md` — flip ADR-017 row from ⚠️ to ✅.
- [ ] Remove the follow-up from `docs/adr-audit.md`'s follow-ups list.
