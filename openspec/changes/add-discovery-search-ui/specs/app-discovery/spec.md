---
status: proposed
---

# App Discovery Specification (delta)

**Status**: proposed
**Standards**: WAI-ARIA 1.2 (tabs, combobox/listbox semantics), Nextcloud Vue components
**Feature tier**: MVP

## Purpose

Surface the implemented discovery backend in the admin UI so multi-source app search delivers user value. This delta adds the frontend requirement the original capability explicitly deferred.

## ADDED Requirements

### Requirement: Discover tab surfaces multi-source search [MVP]

The admin SPA MUST provide a Discover tab containing a debounced search input (client-enforced 2–100 characters, mirroring the API), an optional source filter, and an installed-only toggle, calling `GET /api/discover` and rendering the aggregated hits. Each hit MUST show name, appId, source badges from `sourceCandidates`, and the installed version when present. Loading, empty-result, and request-error states MUST be distinct and localized. Per-provider partial failures reported by the aggregator MUST render as dismissible notes without suppressing the remaining results.

#### Scenario: Search renders multi-source hits

- GIVEN an admin on the Discover tab with a PAT configured
- WHEN they type "openregister" (debounced)
- THEN hits from the App Store and GitHub private providers MUST render with source badges
- AND an installed app's hit MUST show its installed version

#### Scenario: Partial provider failure stays usable

- GIVEN the GitHub provider errors (rate limit) while the App Store provider succeeds
- WHEN the search completes
- THEN App Store hits MUST render and a dismissible note MUST name the failing provider

#### Scenario: Input validation mirrors the API

- WHEN the admin types a single character
- THEN no request MUST be sent and a hint MUST indicate the minimum length

---

### Requirement: Hits route into existing flows [MVP]

From a hit, the admin MUST be able to: (a) for an installed app, jump to the Apps tab with that app's version picker opened; (b) for a not-installed app with an installable source candidate, jump to the Sources flow prefilled with that candidate; (c) for a non-installable hit, see the reason (source not in the trusted allowlist) and a link to the Trusted sources tab. Discovery MUST NOT introduce any install path that bypasses binding, allowlist validation, or password confirmation.

#### Scenario: Installed hit opens the picker

- GIVEN `openregister` is installed
- WHEN the admin activates its Discover hit
- THEN the Apps tab MUST open with the `openregister` version picker expanded

#### Scenario: Installable candidate prefills bind

- GIVEN a not-installed private app hit with candidate `github:ConductionNL/hermiq` and that pattern allowlisted
- WHEN the admin activates the hit's install action
- THEN the Sources bind flow MUST open prefilled with `github:ConductionNL/hermiq`

#### Scenario: Non-installable explains why

- GIVEN a hit whose only candidate is not allowlisted
- WHEN it renders
- THEN the card MUST state the source is not trusted and link to the Trusted sources tab
