# `CnIndexPage` composition

Closes the third partial item flagged in `docs/adr-audit.md` after the
template + ADR cleanup PR: ADR-017 "Component composition" requires apps
to use `CnDetailPage` / `CnIndexPage` from `@conduction/nextcloud-vue`
rather than wrapping content directly in `NcAppContent` / `NcContent`.

## Problem

`App.vue` is currently structured as:

```
<NcContent app-name="app_versions">
    <NcAppContent>
        <DowngradeConfirmDialog />
        <div class="layout">
            <main class="mainContent">
                <h2>App Versions</h2>
                <SettingsPanel />
                <div class="contentRow">
                    <div class="leftColumn"><AppPicker /></div>
                    <VersionPanel />
                    <div class="rightColumn"><InstallResultPanel /></div>
                </div>
            </main>
        </div>
    </NcAppContent>
</NcContent>
```

This is the "raw Nextcloud shell" layout — two columns built by hand
with flex, `<main>` declared inline, headings placed manually. ADR-017
says: "Do not wrap self-contained components in `NcAppContent` —
`CnIndexPage` / `CnDetailPage` already provide the shell, the
navigation patterns, the header actions, the sidebar slots."

Using `CnIndexPage` gives us: consistent page shell with other
Conduction apps, built-in header-actions slot (ADR-018), automatic
sidebar integration (ADR-019) for when that becomes relevant, and
free a11y semantics (landmark roles, heading hierarchy).

## Scope

- Replace the `NcContent` + `NcAppContent` + manual two-column layout
  in `App.vue` with `CnIndexPage` using its built-in slots.
- Move the page title (`App Versions`) into the `header-title` slot.
- Move `SettingsPanel` into the `header-actions` slot if the slot
  supports tall widgets; otherwise keep it in the body.
- Keep `AppPicker` as the primary list and `VersionPanel` +
  `InstallResultPanel` as the detail surface — `CnIndexPage` handles
  the left-rail / main-content split natively.
- Remove the now-redundant `.layout`, `.mainContent`, `.contentRow`,
  `.contentRowSplit`, `.leftColumn`, `.leftColumnFull`, `.rightColumn`
  classes from `App.vue`'s `<style module>`.
- Preserve `DowngradeConfirmDialog` as a top-level teleport-style
  overlay (NcDialog handles its own portal).
- Re-run the Playwright smoke test from the Newman suite to confirm
  the URL surface + admin check still work after the DOM restructure.

## Not in scope

- `CnDetailPage` — different slot contract, not what we want; we're an
  index/overview surface, not a single-object detail.
- Theme / colour changes.
- Any change to business logic or routing.

## Dependencies

- The WCAG AA audit change (`wcag-aa-audit-2026-04-24`) SHOULD land
  first. `CnIndexPage` may introduce its own a11y defaults that change
  the audit findings; doing the audit first captures the baseline.
  If both changes are in flight, the builder MUST re-run axe-core
  after this change to confirm no regression.

## Acceptance

1. `App.vue` no longer imports `NcContent` or `NcAppContent`.
2. `App.vue`'s `<style module>` drops the 7 layout classes listed
   above.
3. Playwright smoke test passes (happy path: open → pick → version →
   dry-run → result).
4. Visual regression: a before/after screenshot pair is captured and
   reviewed; differences are intentional (e.g., header spacing
   tokens from `CnIndexPage`).
5. `docs/adr-audit.md` flips ADR-017 row from ⚠️ to ✅.
