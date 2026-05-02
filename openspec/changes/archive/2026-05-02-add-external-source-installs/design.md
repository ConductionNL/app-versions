# Design: add-external-source-installs

## Architecture overview

```
ApiController
   │
   ├── /api/sources              ───┐
   ├── /api/source/{appId}/bind  ───┼──► SourceRegistry ──► SourceInterface
   ├── /api/app/{appId}/versions ───┘                          │
   │                                                            ├── AppStoreSource     (existing flow)
   │                                                            └── GithubReleaseSource (new)
   │
   └── /api/app/{appId}/versions/{version}/install
           │
           ├── source = "appstore"      ──► SelectedReleaseInstallerService (existing, unchanged)
           └── source = "github:..."    ──► ExternalReleaseInstallerService (new)
                                              │
                                              ├── TrustedSourceList::assertAllowed(source)
                                              ├── Download artifact
                                              ├── Optional SHA-256 verification (.sha256 sibling asset)
                                              ├── Extract → validate appinfo.xml (id, version)
                                              ├── Backup current → replace → run migrations
                                              └── On failure: restore backup
```

## Source binding

When an external install succeeds, we record:

```php
$config->setAppValue('app_versions', "source.{$appId}", json_encode([
    'kind' => 'github-release',
    'owner' => 'ConductionNL',
    'repo' => 'openregister',
    'assetPattern' => '*.tar.gz',
    'boundAt' => '2026-05-02T12:00:00Z',
]));
```

`InstallerService::getAppVersions($appId)` checks this binding first. If bound, it queries the bound source. If unbound, it falls back to App Store (current behaviour). Admins can rebind via `POST /api/source/{appId}/bind`.

## Trusted-source allowlist

Stored as `IConfig::setAppValue('app_versions', 'trusted_sources', json_encode(['ConductionNL/*']))`. Globs use simple `fnmatch()` semantics (`*` matches any chars within an `owner` or `repo` segment). Default is `["ConductionNL/*"]`.

Any external install must pass `TrustedSourceList::assertAllowed("github:ConductionNL/openregister")` or the install fails fast with a clear error before any download.

## Why parallel installer instead of extending `SelectedReleaseInstallerService`

The existing service hard-codes certificate + signature verification, with the algorithm and root.crt path as part of its contract. Bolting an "if external skip cert" branch into it would:

1. Make the most security-critical method (`installFromSelectedRelease`) harder to reason about
2. Risk a future refactor accidentally bypassing certificate checks for App Store installs
3. Conflate two install paths with materially different trust models in one class

The parallel `ExternalReleaseInstallerService` shares helper methods (extraction, copyRecursive, installAppLastSteps) via a small private trait or a shared base class so we don't duplicate the migration/repair-step logic. App Store installs continue to flow through the unchanged `SelectedReleaseInstallerService`.

## Integrity checks for external artifacts

Without code-signing we lean on three non-cryptographic checks plus one optional cryptographic check:

1. **Trusted-source allowlist** — fail fast if `owner/repo` not allowed
2. **`appinfo/info.xml` `<id>` match** — the extracted archive must declare the requested appId
3. **`appinfo/info.xml` `<version>` match** — the extracted version must match the release tag/version requested
4. **SHA-256** (optional) — if a release has a sibling `<asset>.sha256` file, verify before extraction. Missing `.sha256` is allowed but produces an "unverified integrity" warning surfaced in the install response

Asset selection: prefer the first asset matching `assetPattern` (default `*.tar.gz`). Multiple matches → fail with "ambiguous asset, set explicit assetPattern".

## Open questions deferred to follow-up

- **Auto-pin to checksum** — once a SHA-256 is observed for a release, lock the binding to it so a maintainer rewriting the GitHub release can't ship altered bytes silently. Tracked as a TODO in the spec; not in this proposal's scope.
- **Cosign / Sigstore signature verification** — would close the cryptographic gap completely. Future work.
- **Rollback of database migrations** — same constraint as today's App Store rollback path: this app does not roll back DB migrations.

## Risks

| Risk | Mitigation |
| --- | --- |
| Trust downgrade vs App Store path | Parallel installer (no shared "skip cert" branch); UI labels external installs as "Unsigned source"; trusted-source allowlist gate |
| Allowlist misconfiguration (too broad) | Default to `ConductionNL/*` only; admin must opt in to broader globs; require password confirmation on every external install just like App Store |
| Asset ambiguity (multiple `.tar.gz` per release) | Fail fast with actionable error; per-app `assetPattern` config for power users |
| GitHub API rate limits (unauthenticated 60/h) | This proposal touches public releases only; rate-limit headers logged in debug output. Authenticated PAT path (proposal 2) raises limit to 5K/h |
| Backup-and-replace race condition during failed install | Reuse existing maintenance-mode wrap and rename-based backup from `SelectedReleaseInstallerService` |
