# Design: add-release-artifact-cache

## Storage layout
`IAppData` folder `artifact-cache/{appId}/` containing `{version}.tar.gz` + `{version}.meta.json` (`{sha256, sourceId, installerKind, signature?, certificate?, cachedAt, size}`). `ArtifactCache` service owns all IO:
- `store(appId, version, archivePath, meta)` — copy + meta write + prune (`artifact_cache_keep`, oldest by `cachedAt`); best-effort try/catch.
- `fetch(appId, version)` — returns archive stream + meta or null; verifies file sha256 against meta **before** returning (tamper gate lives here).
- `summary()` / `clear(?appId)`.

## Installer integration
Both installers already hold the archive on a temp path plus its verification materials at the moment finalize succeeds — `store()` is called from the shared success path (`InstallFinalizer` returns → installer stores; not inside finalize, which stays install-only).

Fallback: wrap the existing download step. On download exception/HTTP failure → `fetch()`; hit → continue the untouched pipeline with the cached temp copy (allowlist gate already ran before download on the external path; signed path re-runs certificate + `openssl_verify` using stored materials instead of store-fetched metadata). Miss or tamper-discard → rethrow original download error. Outcome envelope gains `servedFromCache: bool`.

## Listing enrichment
`InstallerService::getAppVersions` asks `ArtifactCache` for the app's cached version set once and stamps `cachedOffline` per entry — no per-version IO.

## API/UI
- `GET /api/cache` (summary), `DELETE /api/cache?appId=` (password-confirmed). Routes via `#[ApiRoute]` like the rest.
- UI: offline badge on version rows; cache summary + clear button near the trusted-sources/settings area.

## Trust notes
- Cache never satisfies a *version listing* — only an install of an exact (appId, version) the instance verified before.
- `keep=0` disables both store and fallback.
- Signed path: storing cert+signature makes re-verification offline-complete; CRL check reuses NC's bundled root/CRL as the live path does.

## Testing
- Unit: store/prune/fetch-tamper/clear; fallback matrix (hit-valid, hit-tampered, miss, keep=0, untrusted source); outcome flag; listing stamps.
- Vitest: badge + clear action.

## Rejected alternatives
- Caching in `oc_appconfig` (size) or the filesystem outside IAppData (permissions, backup semantics) — rejected.
- Serving cache when the source merely lists no such version — rejected: availability extension only on *download* failure keeps source authority intact.
