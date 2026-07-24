# Tasks: add-release-artifact-cache

## Task 1: ArtifactCache service
- **Spec ref**: specs/artifact-cache/spec.md (Requirement: Persist verified artifacts on successful install)
- **Status**: done
- **Acceptance criteria**:
  - `lib/Service/Cache/ArtifactCache.php` over `IAppData` (`artifact-cache/{appId}/{version}.tar.gz` + `.meta.json`)
  - `store` (best-effort, prune oldest beyond `artifact_cache_keep` default 3, 0 disables), `fetch` (sha256 tamper gate), `summary`, `clear`
  - Unit tests: store/prune/tamper/clear/keep=0

## Task 2: Installer success-path persistence
- **Spec ref**: specs/artifact-cache/spec.md (Requirement: Persist verified artifacts on successful install)
- **Status**: done
- **Acceptance criteria**:
  - Both installers call `store()` after successful finalize with archive + meta (signed path: signature + certificate included)
  - Failed installs never store; store failure never fails the install (logged)
  - Unit tests on both paths

## Task 3: Download-failure fallback with re-verification
- **Spec ref**: specs/artifact-cache/spec.md (Requirement: Cached fallback with full re-verification)
- **Status**: done
- **Acceptance criteria**:
  - Download step wrapped: failure → `fetch()`; hit continues the standard pipeline (signed: cert+signature re-verify from stored materials; external: sha256 + standard validation; allowlist unchanged)
  - Tampered/miss → original download error; outcome gains `servedFromCache`
  - Unit tests: fallback matrix (hit-valid, hit-tampered, miss, untrusted, keep=0)

## Task 4: Listing badge + cache management API/UI
- **Spec ref**: specs/artifact-cache/spec.md (Requirement: Cache visibility and management)
- **Status**: done
- **Acceptance criteria**:
  - `getAppVersions` stamps `cachedOffline` (single cache query per listing); `openapi.json` updated
  - `GET /api/cache` summary + `DELETE /api/cache?appId=` (password-confirmed)
  - UI: offline badge on version rows; summary + clear action; Vitest coverage
