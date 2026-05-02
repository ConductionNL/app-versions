# Proposal: add-app-discovery-search

## Summary
Add a multi-source app-discovery search API so admins can find new Nextcloud apps to install — across the Nextcloud App Store, their PAT-visible private GitHub repos, and (opt-in) public GitHub topic search — through a single endpoint with consistent result shape. Builds on proposals 1 ([external-source installs](../add-external-source-installs/)) and 2 ([PAT management](../add-github-pat-management/)).

## Motivation
After proposals 1 and 2, an admin can install:
- Apps from the Nextcloud App Store (signed)
- Public ConductionNL GitHub releases (allowlisted, integrity-checked)
- Private GitHub releases (via a stored PAT)

But finding **which** app to install still requires knowing the appId in advance. Proposal 3 closes that loop: a single `GET /api/discover?q=&sources=` endpoint that aggregates results from registered discovery providers and returns a uniform schema the UI can render as cards with an "Install from {source}" button.

## Scope

- `DiscoveryProviderInterface` for any source that can answer "what apps match this query?"
- Three concrete providers shipped in this PR:
  - **AppStoreDiscovery** — searches the existing garm3.nextcloud.com catalog by name/summary/category/tag substring
  - **GithubPrivateDiscovery** — uses the current admin's PAT(s) to list GitHub repos they can see that have an `appinfo/info.xml` file. Only repos where `target_pattern` matches are considered (per the trusted-source allowlist + PAT scoping established in proposals 1 and 2). Off when no applicable PAT is present.
  - **GithubSearchDiscovery** — public, opt-in. Uses GitHub's code-search API restricted to `path:appinfo/info.xml` matching the query. Default off; admin enables via app config flag `app_versions.discovery.github_search_enabled`.
- `DiscoveryAggregator` runs the active providers in parallel (semantically — synchronous PHP, but each provider is independent), de-duplicates by appId, and returns a merged result with the `sourceCandidates` list per app so the UI can show "Install from: App Store | GitHub (private) | …".
- `GET /api/discover?q={query}&sources={comma-separated}` — admin-only, filterable by source id, returns the merged result.
- Result shape includes the `installable: true|false` flag — for example, an App Store search result that requires a private fork would still surface, but with a clear note that the admin needs to add a PAT first.
- Already-installed apps are flagged so the UI can render an "Already installed (vX.Y.Z)" badge and route to the version picker instead of an install button.

## Out of scope

- The Vue search UI itself. The existing frontend bundle is not built in the dev environment and a UI overhaul is its own design exercise. This PR delivers the backend so any client (the to-be-built Vue UI, an admin's own tool, the openapi.json, ...) can consume it.
- Software Catalogus integration — tracked in [issue #24](https://github.com/ConductionNL/app-versions/issues/24).
- Search ranking. Initial implementation returns provider-defined ordering with a soft "App Store first → installed apps first" bias.
- Federation (asking another Nextcloud's App Versions for its search results). Future work.

## Anti-scope

What we deliberately do NOT do:

- **No silent GitHub search by default**: `GithubSearchDiscovery` is opt-in because it raises new privacy/data-flow questions (every search query goes to GitHub and may be logged there). Admin must consciously enable it via app config.
- **No write side-effects from search**: Discovery is read-only. Searching an app does not create source bindings, does not pre-fetch artifacts, does not warm any caches that would change behaviour for other admins.
