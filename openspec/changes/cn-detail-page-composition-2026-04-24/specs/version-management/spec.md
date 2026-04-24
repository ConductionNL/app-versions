# Delta — Page composition

Adds a requirement to the `version-management` capability stating that
the admin UI is composed using `@conduction/nextcloud-vue`'s
`CnIndexPage` shell, not a hand-rolled `NcContent` / `NcAppContent`
wrapper.

### Requirement: Page Composition [MVP]

The admin UI MUST be rendered inside a `CnIndexPage` shell from
`@conduction/nextcloud-vue`. Direct use of `NcContent` and
`NcAppContent` as top-level wrappers in `App.vue` is NOT permitted
(ADR-017).

#### Scenario: Root component is `CnIndexPage`

- GIVEN `src/App.vue`
- WHEN the root element of the `<template>` is inspected
- THEN it MUST be a `CnIndexPage` component
- AND the component MUST be imported from `@conduction/nextcloud-vue`
- AND `NcContent` / `NcAppContent` MUST NOT appear in the file's
  imports or template

#### Scenario: Title renders in the header slot

- GIVEN the admin UI is rendered
- WHEN the DOM is inspected for the page title "App Versions"
- THEN the title MUST be rendered inside `CnIndexPage`'s
  `#header-title` slot
- AND MUST NOT be an inline `<h2>` in the body

#### Scenario: Body layout is free of redundant wrappers

- GIVEN `App.vue`'s `<style module>` block post-refactor
- WHEN it is inspected for layout class rules
- THEN the classes `.layout`, `.mainContent`, `.contentRow`,
  `.contentRowSplit`, `.leftColumn`, `.leftColumnFull`, `.rightColumn`
  MUST be absent (the layout is provided by `CnIndexPage`)

#### Scenario: Downgrade dialog renders via portal

- GIVEN an admin triggers the downgrade confirmation
- WHEN `DowngradeConfirmDialog` opens
- THEN the dialog MUST render via NcDialog's portal (not inside the
  `CnIndexPage` body)
- AND the focus trap MUST be preserved
- AND keyboard Escape MUST close the dialog without triggering a
  downgrade
