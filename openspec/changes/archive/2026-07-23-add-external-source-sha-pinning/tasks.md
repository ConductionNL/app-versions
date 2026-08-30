# Tasks: add-external-source-sha-pinning

## Task 1: SourceBinding sha256 map
- **Spec ref**: specs/external-sources/spec.md (Requirement: Recorded digests are binding-scoped and surfaced)
- **Status**: done
- **Acceptance criteria**:
  - `SourceBinding` gains `getRecordedSha(string $version): ?string` and `withRecordedSha(string $version, string $sha): self` (immutable copy-on-write, matching the class's style)
  - Map round-trips through `fromArray`/`toArray`; digests validated as 64 lowercase hex chars; invalid stored entries dropped + logged
  - 200-entry cap with oldest-first eviction
  - `SourceBindingStore` persists the updated payload atomically per install

## Task 2: Record on successful external install
- **Spec ref**: specs/external-sources/spec.md (Requirement: SHA-256 recorded on first successful external install)
- **Status**: done
- **Acceptance criteria**:
  - `ExternalReleaseInstallerService` records the digest only after full install success (after finalize), reusing the already-computed `hash_file('sha256', $tempFile)` value — the archive is hashed exactly once per install
  - Sibling-verified digest preferred; locally computed digest used when no sibling exists
  - Failure paths (allowlist, download, appId/version mismatch, extraction, finalize) write nothing

## Task 3: Enforce recorded digest on reinstall + acceptNewSha
- **Spec ref**: specs/external-sources/spec.md (Requirement: Recorded SHA-256 enforced on reinstall)
- **Status**: done
- **Acceptance criteria**:
  - Check ordered after download, before extraction/backup; mismatch raises a typed exception surfaced as `code: "sha_mismatch"` with expected + actual digests in the message
  - Recorded digest outranks a (possibly rewritten) sibling `.sha256`
  - `acceptNewSha: true` install parameter: single-request bypass; on success replaces the recorded digest; warning-logged with both digests; audit entry written when the audit-trail capability is present (best-effort, soft dependency)
  - No filesystem change of any kind on mismatch failure (assert in test: no backup dir created)

## Task 4: Binding lifecycle on rebind
- **Spec ref**: specs/external-sources/spec.md (Requirement: Recorded digests are binding-scoped and surfaced)
- **Status**: done
- **Acceptance criteria**:
  - Rebinding to a different `owner/repo` (or to `appstore`) produces a binding without the previous digests
  - Rebinding to the identical source id preserves the map
  - Covered by unit tests on `SourceBindingStore`

## Task 5: API + UI surfacing
- **Spec ref**: specs/external-sources/spec.md (Requirement: Recorded digests are binding-scoped and surfaced)
- **Status**: done
- **Acceptance criteria**:
  - Binding read endpoint and external version-list payload include recorded digests
  - Version picker badges versions with a recorded digest ("checksum recorded" / "matches first-install checksum" after a verified reinstall)
  - `sha_mismatch` renders a dedicated dialog (own file per modal-isolation rule) explaining the rewrite risk, with an explicit "Accept new checksum and install" action that re-submits with `acceptNewSha: true` through the normal password-confirmed flow
  - i18n: English source-string keys; nl translations
  - `openapi.json` updated for the new parameter and response code

## Task 6: Tests + stubs
- **Spec ref**: all spec files
- **Status**: done
- **Acceptance criteria**:
  - Unit tests: map round-trip/validation/cap-eviction; record-on-success-only matrix; mismatch fail-closed (incl. rewritten sibling `.sha256` not rescuing the install); acceptNewSha bypass + replace + no-recorded-digest no-op; rebind discard/preserve
  - No new NC internals expected, but if any are referenced extend `tests/stubs/server-internals.php` so psalm and `tests/phpunit-unit-only.xml` stay green (local ocp stub staleness gotcha: `php -l` locally, deep static analysis in CI)
  - `composer check:strict` passes

## Task 7: Browser/integration verification
- **Spec ref**: all spec files
- **Status**: partial (version bump done; live GitHub-release-rewrite simulation not performed in this run — no disposable test repo/fixture available; covered instead by unit tests asserting fail-closed + no-filesystem-change behavior)
- **Acceptance criteria**:
  - In the dev container: install an app from a GitHub release → digest visible on the binding; reinstall the same version → "matches first-install checksum" badge
  - Simulate a rewrite (point the binding at a fixture release with altered bytes, or swap the asset on a test repo) → install fails with the `sha_mismatch` dialog; accepting installs and updates the digest
  - Rebind to another source → digests gone
  - Update `external-sources` spec Notes to drop the now-implemented TODO when syncing deltas; bump `appinfo/info.xml` `<version>` with the bundle change
