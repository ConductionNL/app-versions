# Proposal: add-discovery-search-ui

## Summary
Add the missing Discover tab to the admin SPA: a search box over the fully-implemented but unreachable `GET /api/discover` backend, rendering multi-source hits with installed/installable state and routing straight into the existing bind + version-picker flows.

## Motivation
The app-discovery capability is an **orphaned capability**: `DiscoveryAggregator`, App Store discovery, GitHub private (PAT-scoped) and opt-in public search providers, dedupe, and the admin-only API all exist and are unit-tested — and no pixel of UI can reach them. The original spec explicitly deferred the frontend; nothing picked it up. Until a search box exists, the discovery backend delivers zero user value (spec-says-done ≠ feature runs — this exact defect class is tracked fleet-wide).

Discovery also completes the app's story: today an admin can manage versions of *installed* apps only via the card list; finding a not-yet-installed private Conduction app (the PAT + trusted-source machinery's whole point) still requires leaving Nextcloud.

## Scope
- New **Discover** tab in `src/App.vue` (5th tab): debounced search input (2–100 chars, mirroring API validation), source filter, installed-only toggle
- Hit cards: name, appId, per-source badges (`sourceCandidates`), installed version indicator, installable flag
- Actions per hit: installed app → jump to its version picker (Apps tab, preselected); not-installed + installable candidate → prefill the Sources bind flow; non-installable → show the reason (source not allowlisted) with a link to the Trusted sources tab
- Per-provider fail-soft surfacing: partial provider errors render as dismissible notes, results still shown
- Empty/loading/error states, WAI-ARIA compliant within the existing tablist

## Non-goals
- No new backend endpoints (the API is complete); no provider changes
- No install-from-search shortcut that bypasses bind/allowlist/password confirmation

## Impact
- MODIFIED requirement in `app-discovery` (UI surfacing added; removes the "frontend out of scope" note)
- Touches `src/App.vue`, new `src/components/DiscoverPanel.vue`
