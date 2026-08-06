# Design: add-discovery-search-ui

## Component
`src/components/DiscoverPanel.vue`, mounted as the 5th tab in `App.vue`'s existing WAI-ARIA tablist (pattern: SourcesPanel/TokensPanel). State local to the panel; OCS calls via `src/ocs.ts`.

- Debounce 400 ms; requests aborted on new input (AbortController) so stale results never render.
- Client-side validation mirrors API: <2 chars → hint, >100 → clamp.
- Source filter: select fed from `GET /api/sources` registered source kinds; installed-only toggle maps to the API's `installedOnly`.

## Hit → flow routing
Cross-tab navigation needs light lifting in `App.vue`:
- `openApp(appId)` — switches to Apps tab, sets the app filter to the appId, expands its version picker (existing expansion state).
- `prefillBind(candidate)` — switches to Sources tab, passes `{appId, sourceId}` as a prop; SourcesPanel gains optional prefill props (non-breaking).
- Trusted-sources link — plain tab switch.
These are emitted events (`@open-app`, `@prefill-bind`) handled by `App.vue`; no store introduction for a single SPA settings page.

## Fail-soft
The aggregator already returns per-provider errors alongside hits; render as `NcNoteCard type="warning"` dismissibles above the results list.

## Accessibility
Results as a list with each card an `article` labelled by app name; actions are real buttons; the search input has `inputLabel`; provider notes get `role="status"`.

## Testing
- Vitest: debounce + abort, validation hint, hit rendering (badges, installed version), partial-failure note, routing events, non-installable reason.
- No backend tests needed (API untouched).

## Rejected alternatives
- Direct install button on hits — would bypass the bind + password-confirmation ceremony; routing into existing flows keeps one install path.
- vue-router introduction — overkill for a settings-embedded SPA; event-based tab switching suffices.
