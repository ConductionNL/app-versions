# Design — WCAG AA audit + fixes

## Approach

Three-phase pass: automated scan → manual screen-reader → fix + regress.

### Phase 1: Automated scan (axe-core via Playwright)

Boot the Nextcloud test server, sign in as admin, load `/apps/app_versions/`.
Exercise each of these page states and run axe-core at each:

1. Empty picker (no app selected, app list populated)
2. App selected, version list loading
3. Version selected, action buttons visible (Install / Update / Degrade)
4. DowngradeConfirmDialog open
5. Dry-run install completed, ResultPanel + DebugTimeline rendered

For each state, capture the axe-core report. Critical + serious
findings are mandatory fixes; moderate findings are case-by-case.

### Phase 2: Manual screen-reader traversal

NVDA on Chrome (Windows) or VoiceOver on Safari (macOS). Record:
- Every interactive control reached, in order
- Every label announced verbatim
- Any skipped element or focus trap
- Announcement of state changes (filter match count, selected version,
  install result)

Deliverable: a traversal log as a markdown table under
`docs/accessibility.md`.

### Phase 3: Remediation

Likely fixes by component:

- **AppCard.vue** — add `role="button"` + `tabindex="0"` + `@keyup.enter`
  handler on the card element so the whole card is a screen-reader
  button, not just the nested "Choose app" button. OR remove the nested
  button and make the card itself the interactive surface.

- **VersionItem.vue** — the install-action button needs
  `aria-describedby` pointing at the degrade warning paragraph when
  present, so screen readers announce the warning before the button.

- **VersionPanel.vue** — wrap the transition-group in
  `<div role="region" aria-live="polite" aria-label="Available versions">`
  so filter changes announce.

- **InstallResultPanel.vue** — the status badge uses `role="status"`
  (polite announcement) and `aria-label` with the full tone text.
  Verify WCAG AA contrast ratios on the four tone backgrounds:
  success `#16a34a` vs white, warning `#ea580c` vs white, error
  `#dc2626` vs white, info `#475569` vs white. Adjust if any fails.

- **DowngradeConfirmDialog.vue** — NcDialog provides the focus trap.
  Add `aria-describedby` on the dialog root pointing at the warning
  paragraph id, so the warning is announced when the dialog opens.

- **App.vue** — the outer `<main>` element needs an explicit
  `aria-label="App Versions"` since the `<h2>` title is the same string
  and screen readers may collapse them.

- **SettingsPanel.vue** — the two checkboxes are already inside
  `<label>` elements. Verify the checked state is announced (NVDA
  typically does this by default; if it doesn't, add `aria-checked`
  explicitly).

### Phase 4: Regression test

Add one Playwright scenario:
```
test('admin UI is WCAG AA conformant', async ({ page }) => {
    await page.goto('/apps/app_versions/');
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    expect(results.violations.filter(v => ['critical', 'serious'].includes(v.impact))).toHaveLength(0);
});
```

## Rejected alternatives

- **Wave-based static audit**: considered — only covers initial render,
  misses dynamic state changes (dialog, version list update). Rejected
  in favour of axe-core which runs against live DOM.
- **Pa11y**: similar to axe, less Nextcloud-ecosystem traction. Stick
  with axe for alignment with the rest of the Conduction apps.

## Risks

- The four status-tone backgrounds may need to be re-picked for
  contrast — that's a visual change. Use Conduction's CSS-variable
  palette if it exposes WCAG-AA-safe tones; otherwise introduce new
  tokens and document.
- `CnIndexPage` (from the separate composition-review change) may
  change some of these surfaces. Land this a11y change before OR
  after the composition change and re-run axe-core on the merged
  result.
