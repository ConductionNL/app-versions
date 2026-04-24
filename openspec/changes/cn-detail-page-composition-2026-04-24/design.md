# Design — `CnIndexPage` composition

## Approach

`CnIndexPage` is a wrapper component exported by
`@conduction/nextcloud-vue` that bundles the standard Conduction page
shell. It exposes three principal slots:

- `header-title` — top-of-page heading block (icon + title + subtitle)
- `header-actions` — right-aligned action buttons / controls
- default — the body content

For an admin utility like App Versions, the shell maps naturally:

- `header-title`: the literal text "App Versions" + a small icon
- `header-actions`: the three controls currently in `SettingsPanel`
  (update-channel readout, safe-mode toggle, debug toggle)
- default body: `AppPicker` on the left, `VersionPanel` in the middle,
  `InstallResultPanel` on the right (when `hasInstallResult`)

The downgrade-confirm dialog stays outside `CnIndexPage` — `NcDialog`
uses its own Teleport portal, so it's already DOM-detached from the
page shell.

## Template shape after refactor

```vue
<CnIndexPage>
    <template #header-title>
        {{ t('app_versions', 'App Versions') }}
    </template>
    <template #header-actions>
        <SettingsPanel
            :update-channel="updateChannel"
            :safe-mode="safeModeEnabled"
            :debug-mode="debugModeEnabled"
            :disabled="isInstallingVersion"
            @update:safe-mode="safeModeEnabled = $event"
            @update:debug-mode="debugModeEnabled = $event"
        />
    </template>
    <AppPicker ... />
    <VersionPanel ... />
    <InstallResultPanel
        v-if="hasInstallResult && lastInstallResult"
        ...
    />
</CnIndexPage>

<DowngradeConfirmDialog ... />
```

## What this replaces

- `NcContent` wrapper — `CnIndexPage` provides its equivalent.
- `NcAppContent` wrapper — same.
- Manual `<main :class="$style.mainContent">` — landmark role now from
  `CnIndexPage`.
- Manual `<h2>` — title now in the header slot.
- Manual `.contentRow` + `.leftColumn` + `.rightColumn` flex layout —
  `CnIndexPage` handles the canonical Conduction list + detail layout,
  OR we keep a simple flex layout inside the default slot.

Open question: does `CnIndexPage` provide the list-vs-detail split we
need, or does it only give us a flat body? The component's source
lives at `../nextcloud-vue/src/CnIndexPage.vue` in the monorepo —
Task 1 reads it and confirms the slot contract before Task 2 restructures.

## SettingsPanel fit-check

`SettingsPanel` currently renders:
- a paragraph showing the update channel
- two checkbox toggles

In a `header-actions` slot (typically small, right-aligned), the two
toggles plus the update-channel readout may be cramped. Fallback: keep
`SettingsPanel` in the body above `AppPicker` and only put a single
summary icon/button in `header-actions` that opens a settings popover.

Decision criterion: if `SettingsPanel` renders at less than 320px wide
without wrapping, it goes in `header-actions`. Otherwise it stays
above `AppPicker`.

## Rejected alternatives

- **`CnDetailPage`**: wrong metaphor. Detail pages are for a single
  persisted object (an Item, an Article); we have no persisted object.
- **Keep `NcAppContent` and add `CnPageHeader`**: partial adoption,
  worst of both worlds — doesn't close the ADR-017 gap, adds extra
  component imports.

## Risks

- Visual regression: the header bar from `CnIndexPage` has its own
  padding/spacing tokens. The result will be visually closer to other
  Conduction apps but diverges from today's look. Mitigation: capture
  before/after screenshots; reviewer confirms the diff is the
  intentional style-alignment and not a layout bug.
- a11y regression: see cross-dependency note in proposal.md. The
  accessibility audit change (`wcag-aa-audit-2026-04-24`) should land
  first so the a11y baseline is recorded.
- Browser test coverage: the existing Newman collection only covers the
  OCS endpoints, not the UI. Need to rely on Playwright for visual +
  interaction regression — this change adds a minimal Playwright smoke
  test if one doesn't already exist.
