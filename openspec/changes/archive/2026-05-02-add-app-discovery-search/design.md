# Design: add-app-discovery-search

## Provider interface

```php
interface DiscoveryProviderInterface {
    public function getId(): string;          // 'appstore' | 'github-private' | 'github-search'
    public function getLabel(): string;        // Admin-facing
    public function isEnabled(): bool;         // Reads config / PAT availability
    public function search(string $query): DiscoveryResult;
}

final class DiscoveryResult {
    /** @var list<DiscoveryHit> */
    public readonly array $hits;
    public readonly ?string $error;
}

final class DiscoveryHit {
    public readonly string $appId;
    public readonly string $name;
    public readonly string $summary;
    public readonly ?string $iconUrl;
    public readonly string $sourceProviderId;
    public readonly array $sourceBinding;       // What `POST /api/source/{appId}/bind` would consume
    public readonly bool $installable;          // false when binding is blocked by allowlist or missing PAT
    public readonly ?string $installableReason; // human-readable explanation when `installable=false`
    public readonly ?string $homepageUrl;
}
```

## Aggregator behavior

```
GET /api/discover?q=&sources=appstore,github-private
  ↓
DiscoveryAggregator::search(query, sourceIds)
  ↓
  for each enabled provider in sourceIds:
      hits = provider->search(query)
      annotate(hits, with: alreadyInstalledApps, allowlistedSources)
  ↓
  group by appId — one card per app
      sourceCandidates = unique source bindings across hits
      pick representative (App Store > github-private > github-search)
      preserve `installable` info per candidate
  ↓
  return [{
      appId, name, summary, iconUrl,
      installedVersion: ?string,
      sourceCandidates: [{providerId, sourceBinding, installable, installableReason}, ...]
  }, ...]
```

## Why not parallelize via processes / threads

Each provider is one-or-two HTTP calls (App Store catalog request, GitHub API). PHP can't truly parallelise without curl_multi or React/Amp. Total wall time for a 3-provider search is dominated by the slowest provider (~1-2 s GitHub round-trip). For an admin-facing search this is acceptable; we're not on a hot path. If it becomes an issue, swap in `IClient::getMulti` later.

## AppStoreDiscovery

Pulls the App Store catalog (already partially fetched by `AppStoreSource`) and filters client-side by query against `name`, `summary`, `description`, and `categories`. Uses an in-memory cache keyed by `app_versions.cache.appstore_catalog` for the catalog JSON, expiring after 1 hour. The catalog is small enough (~250 apps) that in-memory filter is faster than calling the App Store search endpoint — and it works offline if the catalog was warmed.

When already-installed: pull `installedVersion` from `IAppManager::getAppVersion`.

## GithubPrivateDiscovery

For each PAT visible to the current admin (`PatMapper::findVisibleTo`), uses the GitHub Search API (`code+filename:info.xml+path:appinfo`) restricted to the PAT's target repos (`repo:{owner}/{repo}` for explicit bindings, or `org:{owner}` if the PAT is org-scoped — best-effort).

- Returns one hit per matching repo
- `appId` extracted from the indexed `appinfo/info.xml` content via raw fetch
- `sourceBinding = {kind: github-release, owner, repo}`
- `installable = TrustedSourceList::isAllowed(...)` — if the org is not allowlisted, `installable = false` with a "Add `{org}/*` to trusted sources first" message

Edge case: a private repo that the PAT can read but is NOT in the trusted-source allowlist still surfaces in search (so the admin sees that it exists), but with `installable: false`. This is intentional — silently hiding hits is worse than telling the admin why they can't install yet.

## GithubSearchDiscovery (opt-in)

Uses `GET https://api.github.com/search/code?q=filename:info.xml+path:appinfo+{query}` with no auth (public results only). Limits to first page (30 results) to stay well under the 30-req/min rate limit. Each hit's repo is checked against `TrustedSourceList`; non-allowlisted repos still surface with `installable: false`.

Disabled by default. Admin enables via:

```bash
occ config:app:set app_versions discovery.github_search_enabled --value=true
```

When disabled, the provider returns an empty result and is filtered out of the source list returned by `GET /api/sources` (so the UI doesn't show a chip the admin can't actually use).

## API endpoint

```
GET /api/discover
  ?q={query}            # required, min 2 chars
  &sources={csv}         # optional; defaults to all enabled providers
  &limit={int}           # optional; default 50, max 200
  &installedOnly={bool}  # optional; default false. When true, only include apps already installed (useful for "manage installed apps" view)
```

Response:

```json
{
  "results": [
    {
      "appId": "openregister",
      "name": "Open Register",
      "summary": "Decentralized data registers for Nextcloud",
      "iconUrl": "https://...",
      "installedVersion": "0.2.13-unstable.80",
      "sourceCandidates": [
        {
          "providerId": "appstore",
          "sourceBinding": {"kind": "appstore"},
          "installable": true,
          "installableReason": null
        },
        {
          "providerId": "github-private",
          "sourceBinding": {"kind": "github-release", "owner": "ConductionNL", "repo": "openregister"},
          "installable": true,
          "installableReason": null
        }
      ]
    }
  ],
  "providers": [
    {"id": "appstore", "label": "Nextcloud App Store", "enabled": true},
    {"id": "github-private", "label": "GitHub (private, your PATs)", "enabled": true},
    {"id": "github-search", "label": "GitHub (public search)", "enabled": false}
  ]
}
```

## Risks

| Risk | Mitigation |
| --- | --- |
| GitHub search rate limit (30/min unauth, 30/min for code search even auth) | Limit to first page; cache last query → result for 60 s; surface a clear error to the admin when rate-limited |
| Already-installed flag: race between search response and admin opening picker | We snapshot `IAppManager::getInstalledApps()` on each request — staleness window is one request, acceptable |
| PAT exposure via search query that ends up in GitHub logs | `GithubPrivateDiscovery` does NOT include PAT plaintext in the URL or query (auth header only). Search query is the *user's* input — if they search for sensitive strings they're sending those to GitHub, but that's expected behaviour for any code search. |
| Admin enables `github_search_enabled` and immediately sees confusing "installable: false" hits | UI explanation covers this; in this PR the API surfaces `installableReason` so the UI can render a badge + tooltip. |
