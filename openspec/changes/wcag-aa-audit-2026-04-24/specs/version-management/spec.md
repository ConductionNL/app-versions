# Delta — Accessibility

Adds one new Requirement to the `version-management` capability covering
WCAG AA conformance of the admin UI.

### Requirement: Accessibility [MVP]

The admin UI MUST conform to WCAG 2.1 level AA. This applies to every
interactive surface: the app picker (search + filters + card list), the
version list, the install action buttons, the downgrade-confirm dialog,
the install-result panel, and the debug timeline.

#### Scenario: Automated axe-core scan is clean

- GIVEN the admin UI is rendered in any of its five canonical states —
  empty picker, app-selected, version-selected, dialog-open,
  result-visible
- WHEN axe-core runs against the live DOM with WCAG 2.1 AA tags
- THEN zero findings of impact `critical` or `serious` MUST be reported
- AND the automated scan MUST run in CI on every pull request

#### Scenario: Manual screen-reader traversal covers the happy path

- GIVEN an admin user navigating with NVDA on Chrome (or VoiceOver on
  Safari)
- WHEN they Tab through the picker, search for an app, select it, pick
  a version, trigger an install, and review the result
- THEN every focusable control MUST announce a non-empty, meaningful
  label
- AND no focus trap MUST block progress at any step
- AND destructive actions (Degrade, confirm-downgrade) MUST announce
  their destructive semantic beyond just visual red colour

#### Scenario: Version-list filter announces changes

- GIVEN the version list is rendered
- WHEN the admin types into the version-filter input and the filtered
  list updates
- THEN the transition-group wrapper MUST be marked `aria-live="polite"`
- AND the screen reader MUST announce the new match count within a
  reasonable window after the DOM update

#### Scenario: Status badge contrast ratio meets WCAG AA

- GIVEN the install-result status badge is rendered in any of its four
  tones (success / warning / error / info)
- WHEN contrast is measured between the badge text and background
- THEN the ratio MUST meet WCAG AA: ≥ 4.5 : 1 for normal text, ≥ 3 : 1
  for large/bold text
