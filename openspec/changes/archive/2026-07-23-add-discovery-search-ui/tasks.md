# Tasks: add-discovery-search-ui

## Task 1: DiscoverPanel component
- **Spec ref**: specs/app-discovery/spec.md (Requirement: Discover tab surfaces multi-source search)
- **Status**: done
- **Acceptance criteria**:
  - `src/components/DiscoverPanel.vue` with debounced (400 ms) + aborting search, source filter, installed-only toggle
  - Client validation mirrors API (2–100 chars) with localized hint
  - Hit cards: name, appId, source badges, installed version; distinct loading/empty/error states
  - Per-provider failure notes (dismissible) alongside surviving results
  - Vitest: debounce/abort, validation, rendering, partial-failure

## Task 2: Tab integration
- **Spec ref**: specs/app-discovery/spec.md (Requirement: Discover tab surfaces multi-source search)
- **Status**: done
- **Acceptance criteria**:
  - Discover registered as 5th tab in `App.vue`'s WAI-ARIA tablist, accessible semantics intact
  - `inputLabel` on the search input; provider notes `role="status"`

## Task 3: Hit routing into existing flows
- **Spec ref**: specs/app-discovery/spec.md (Requirement: Hits route into existing flows)
- **Status**: done
- **Acceptance criteria**:
  - `@open-app` → Apps tab with filter + expanded picker; `@prefill-bind` → Sources tab with prefill props (SourcesPanel gains optional, non-breaking props)
  - Non-installable hit states the untrusted-source reason and links to Trusted sources tab
  - No install path bypasses bind/allowlist/password confirmation
  - Vitest: routing events, prefill propagation, reason rendering

## Task 4: Spec doc alignment
- **Spec ref**: specs/app-discovery/spec.md (delta)
- **Status**: done
- **Acceptance criteria**:
  - Main `openspec/specs/app-discovery/spec.md` note "frontend search UI is not part of this proposal" superseded on archive (sync via opsx-sync/archive flow)
