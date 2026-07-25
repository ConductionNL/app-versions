---
status: implemented
---

# Artifact Cache Specification

**Status**: implemented
**Standards**: OCP\Files\IAppData, existing App Versions verification chain (code-signing / SHA-256)
**Feature tier**: MVP

## Purpose

Make rollback link-rot-proof: verified archives are retained locally after successful installs and used as a same-version fallback when the upstream source can no longer deliver — with the full verification chain re-run on every reuse. The cache extends availability, never trust.

## Requirements

### Requirement: Persist verified artifacts on successful install [MVP]

After a successful install, the system MUST store the downloaded archive and its verification metadata (SHA-256 always; App Store path additionally signature + certificate) in app data, keyed by appId and version. At most `artifact_cache_keep` (default 3, 0 = caching disabled) archives MUST be retained per app — pruned oldest-first on write. Failed installs MUST NOT populate the cache. Cache write failures MUST NOT fail the install (best-effort, logged).

#### Scenario: Successful install populates the cache

@e2e tests/e2e/install-effects.spec.ts

- GIVEN `openregister` 2.3.0 installs successfully from the App Store
- THEN the archive, its sha256, signature, and certificate MUST be stored under the app's cache folder for version 2.3.0

#### Scenario: Retention prunes oldest

@e2e exclude requires artifact_cache_keep bound + several installs; the prune rule is unit-tested.

- GIVEN `artifact_cache_keep` is 3 and versions 2.1.0, 2.2.0, 2.3.0 are cached
- WHEN 2.4.0 installs successfully
- THEN 2.1.0's archive MUST be removed and the other three retained

#### Scenario: Cache write failure is non-fatal

@e2e exclude a cache write failure cannot be injected in e2e; the best-effort write is unit-tested.

- GIVEN app data is unwritable
- WHEN an install succeeds
- THEN the install outcome MUST still be success and the failure MUST be logged

---

### Requirement: Cached fallback with full re-verification [MVP]

When an install's source download fails (network error, HTTP error, missing release/asset) and the cache holds an artifact for the exact appId+version, the system MUST fall back to it and re-run the complete verification chain for the source kind (App Store: certificate + signature verification with the stored materials, appId/version match; external: stored SHA-256 match plus standard archive validation, trusted-source allowlist still enforced). A cached artifact failing re-verification MUST be discarded and the original download error surfaced. Install outcomes MUST state when the cache served the artifact.

#### Scenario: Rollback survives a dead URL

@e2e tests/e2e/forge.spec.ts

- GIVEN 2.3.0 is cached and the App Store download URL now 404s
- WHEN the admin installs 2.3.0
- THEN the cached archive MUST be used, signature re-verified with stored materials, and the install MUST succeed with the outcome noting cache use

#### Scenario: Tampered cache is discarded

@e2e exclude requires tampering the on-disk cache file; the sha re-check is unit-tested.

- GIVEN the cached 2.3.0 archive no longer matches its stored sha256
- WHEN the fallback is attempted
- THEN the artifact MUST be discarded and the original download error returned

#### Scenario: Untrusted source is not served from cache

@e2e exclude the allowlist gate precedes any cache read; unit-tested.

- GIVEN a cached artifact for an app whose source pattern was removed from the allowlist
- WHEN an install of that version is attempted
- THEN the allowlist gate MUST reject it exactly as it would a fresh download

---

### Requirement: Cache visibility and management [MVP]

Version listings MUST mark cached versions (`cachedOffline: true`). The API MUST expose a cache summary (per-app versions + total size) and a password-confirmed clear operation (all or per app). The UI MUST badge offline-available versions and offer the clear action in settings.

#### Scenario: Offline badge

@e2e tests/e2e/forge.spec.ts

- GIVEN 2.3.0 is cached
- WHEN the version list renders
- THEN the 2.3.0 row MUST show an offline-available indicator

#### Scenario: Clear cache

@e2e tests/e2e/install-effects.spec.ts

- WHEN the admin clears the cache with password confirmation
- THEN all cached archives MUST be removed and the summary MUST report empty
