# Proposal: add-release-artifact-cache

## Summary
Keep the verified release archives App Versions installs, in app data, so rollback still works when the upstream download URL has rotted. Cached versions are marked "available offline" in the picker; installs transparently fall back to the cache when the source download fails; verification is never weakened (cached artifacts re-pass the same checks).

## Motivation
The App Store hosts release *links*, not binaries — an old version stays installable only while its external URL stays alive, and forge releases can be deleted or rewritten. Link rot silently breaks this app's core promise: the moment an admin most needs to roll back (upstream shipped a broken release, maybe pulled it) is exactly when the old artifact is most likely to be gone. The deepdive flagged this as a distinct risk (insight "Old-release availability depends on external URLs — cache artifacts locally", deepdive-2026-07-23-app-versions) and no ecosystem analogue covers it either — apt's `/var/cache/apt/archives` is the model to copy.

The app already has every ingredient at install time: the verified bytes, their SHA-256, and (App Store path) the signature materials. Persisting them is cheap; re-verifying on reuse keeps the trust model intact.

## Scope
- On every **successful** install, persist the downloaded archive + verification metadata (sha256; App Store: signature + certificate) to app data (`IAppData`, folder per appId, file per version)
- Retention: keep the last N archives per app (`artifact_cache_keep`, default 3, 0 disables caching); prune on write; global "clear cache" admin action
- Version listings mark cached versions (`cachedOffline: true`)
- Install flow: when the source download fails (network error, 404, deleted release), fall back to the cached artifact **for the same appId+version**, re-running the full verification chain (App Store: signature verify with stored cert materials; external: sha256 match + the standard archive validation); the outcome notes the cache was used
- Cache is never consulted when its stored hash fails re-verification → fall through to the original download error
- API: cache summary (size per app) + `DELETE` clear endpoint (password-confirmed)

## Non-goals
- No proactive pre-fetching of versions never installed
- No cache sharing between instances
- No bypass of trusted-source/allowlist checks (a cached artifact for an unbound/untrusted source is not installable)

## Impact
- New capability spec: `artifact-cache`
- Touches both installer services (persist + fallback), `lib/Service/InstallerService.php` (listing enrichment), new `lib/Service/Cache/ArtifactCache.php`, `lib/Controller/ApiController.php`, `src/App.vue` (badges + clear action)
