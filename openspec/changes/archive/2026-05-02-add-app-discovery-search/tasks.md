# Tasks: add-app-discovery-search

## Task 1: Discovery interface + result types
- **Spec ref**: specs/app-discovery/spec.md (Requirement: Provider interface)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Service/Discovery/DiscoveryProviderInterface.php` defines `getId`, `getLabel`, `isEnabled`, `search`
  - `lib/Service/Discovery/DiscoveryHit.php` immutable DTO with all required fields
  - `lib/Service/Discovery/DiscoveryResult.php` `{hits, error}` envelope

## Task 2: AppStoreDiscovery provider
- **Spec ref**: specs/app-discovery/spec.md (Requirement: App Store discovery)
- **Status**: todo
- **Acceptance criteria**:
  - Reads catalog via existing App Store endpoint (reuses logic from `AppStoreSource`)
  - Filters case-insensitively across `name`, `summary`, `description`, `categories`
  - Caches catalog JSON via `IAppConfig` for 1 hour to avoid repeated round-trips
  - Returns hits sorted by relevance (exact-match > prefix > substring)

## Task 3: GithubPrivateDiscovery provider
- **Spec ref**: specs/app-discovery/spec.md (Requirement: GitHub private discovery)
- **Status**: todo
- **Acceptance criteria**:
  - For each PAT visible to current admin, uses `GET /search/code?q=path:appinfo+filename:info.xml+{query}` with the PAT's auth
  - Restricts to repos the PAT can see (the API does this naturally for fine-grained PATs)
  - Extracts appId by raw-fetching the matched `info.xml` and parsing the `<id>` tag
  - Annotates each hit with trusted-source allowlist status (`installable: true|false`)
  - Returns empty enabled=false when no PATs are configured

## Task 4: GithubSearchDiscovery provider (opt-in)
- **Spec ref**: specs/app-discovery/spec.md (Requirement: GitHub public search)
- **Status**: todo
- **Acceptance criteria**:
  - Disabled by default; reads `app_versions.discovery.github_search_enabled` from app config
  - Uses unauthenticated `GET /search/code?q=path:appinfo+filename:info.xml+{query}`
  - Cached 60 s per query
  - Annotates with allowlist status; non-allowlisted repos surface with `installable: false` and a clear reason

## Task 5: Aggregator + de-dup
- **Spec ref**: specs/app-discovery/spec.md (Requirement: Result aggregation)
- **Status**: todo
- **Acceptance criteria**:
  - `DiscoveryAggregator::search($query, $sourceIds = null, $installedOnly = false): array`
  - Runs each enabled provider; collects hits
  - Groups by `appId`, builds per-app `sourceCandidates` list
  - Annotates with `installedVersion` from `IAppManager`
  - Sort: installed apps first, then App Store source first within each group

## Task 6: Discover endpoint
- **Spec ref**: specs/app-discovery/spec.md (Requirement: Discovery API)
- **Status**: todo
- **Acceptance criteria**:
  - `GET /api/discover?q=&sources=&limit=&installedOnly=`
  - Validates `q` length (≥2, ≤100 chars)
  - Returns `{results, providers}` structure
  - 400 on missing/short query
  - Forbidden for non-admins
  - `GET /api/sources` extended to include `discoverProviders: [...]` so the UI can render source chips

## Task 7: Tests
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - `DiscoveryAggregatorTest` covering: empty providers, single provider, multi-provider de-dup by appId, sort order, installedOnly filter
  - `AppStoreDiscoveryTest` covering: name match, summary match, no match, case-insensitive, caching
  - `GithubPrivateDiscoveryTest` covering: with PAT (mocked) returns hits, without PAT returns empty enabled=false, allowlist annotation
  - `GithubSearchDiscoveryTest` covering: disabled by default, enabled returns hits, allowlist annotation
  - All 64+N tests pass via `tests/phpunit-unit-only.xml`

## Task 8: Browser verification
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - `GET /api/discover?q=register` returns at least one App Store hit (e.g. "Open Register")
  - `GET /api/discover?q=register&sources=appstore` returns same hit, no GitHub providers
  - `GET /api/discover?q=` returns 400 (query too short)
  - `GET /api/discover?q=...` for an installed app reports `installedVersion` correctly
  - `occ config:app:set app_versions discovery.github_search_enabled --value=true` flips that provider to enabled in `GET /api/sources`
