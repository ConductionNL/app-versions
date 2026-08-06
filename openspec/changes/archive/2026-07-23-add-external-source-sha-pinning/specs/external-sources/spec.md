---
status: proposed
---

# External Sources — SHA-256 Auto-Pinning Delta

**Status**: proposed
**Standards**: SHA-256 (FIPS 180-4), GitHub REST API v2022-11-28
**Feature tier**: Hardening

## Purpose

Implements the hardening TODO recorded in the external-sources spec Notes: auto-pin the observed SHA-256 of every externally-installed artifact (trust-on-first-use) so a maintainer rewriting an already-published release cannot ship altered bytes silently to instances that installed it before. First-contact installs keep today's trust model unchanged; this delta protects every subsequent download of a previously observed (appId, version, source) triple.

## ADDED Requirements

### Requirement: SHA-256 recorded on first successful external install [Hardening]

On every successful external install, the system MUST record the artifact's SHA-256 in the app's source binding (`source.{appId}` payload, `sha256` map keyed by version). The digest MUST be taken from the verified `.sha256` sibling when one was checked, and otherwise computed locally from the downloaded archive. Recording MUST happen only after the install fully succeeded. The map MUST be capped (200 entries, oldest evicted first).

#### Scenario: Digest recorded from verified sibling

- GIVEN `openregister@2.5.0` is installed from `github:ConductionNL/openregister` and the release publishes a matching `.sha256` sibling
- WHEN the install succeeds
- THEN the binding for `openregister` MUST contain `sha256["2.5.0"]` equal to the verified digest

#### Scenario: Digest computed and recorded without sibling

- GIVEN the release for `openregister@2.4.0` has no `.sha256` sibling asset
- WHEN the install succeeds (with the existing `integrityWarning`)
- THEN the binding MUST contain `sha256["2.4.0"]` equal to the locally computed SHA-256 of the downloaded archive

#### Scenario: Failed install records nothing

- GIVEN an external install of `openregister@2.5.0` fails after download (e.g. appId mismatch in `appinfo/info.xml`)
- WHEN the install aborts
- THEN no `sha256["2.5.0"]` entry MUST be written for that attempt

---

### Requirement: Recorded SHA-256 enforced on reinstall [Hardening]

When the binding records a SHA-256 for the requested version, the system MUST compare it against the SHA-256 of the freshly downloaded artifact before extraction. On mismatch the install MUST fail with a message naming both digests and the machine-readable error code `sha_mismatch`, and no extraction, backup, or filesystem change MUST happen. The request parameter `acceptNewSha: true` MUST bypass the comparison for that single request and, on install success, replace the recorded digest; the replacement MUST be logged at warning level and audited when the audit-trail capability is available.

#### Scenario: Matching digest proceeds

- GIVEN the binding records `sha256["2.3.0"]` for `openregister`
- WHEN the admin rolls back to 2.3.0 and the downloaded artifact hashes to the recorded digest
- THEN the install MUST proceed through the existing checks
- AND the install response MUST indicate the artifact matched the first-install checksum

#### Scenario: Rewritten release fails closed

- GIVEN the binding records `sha256["2.3.0"]` and the upstream release asset has since been replaced with different bytes
- WHEN the admin attempts to reinstall 2.3.0 without `acceptNewSha`
- THEN the install MUST fail with error code `sha_mismatch` naming the expected and actual digests
- AND no extraction, backup, or change to the installed app MUST happen
- AND a co-published rewritten `.sha256` sibling MUST NOT cause the check to pass (the recorded digest takes precedence)

#### Scenario: Explicit acceptance replaces the recorded digest

- GIVEN a `sha_mismatch` failure for `openregister@2.3.0`
- WHEN the admin retries with `acceptNewSha: true` (password-confirmed install) and the install succeeds
- THEN `sha256["2.3.0"]` MUST be replaced with the new digest
- AND the replacement MUST be logged at warning level with both digests
- AND an audit entry MUST record the acceptance when the audit-trail capability is deployed

#### Scenario: acceptNewSha without a recorded digest is harmless

- GIVEN no digest is recorded for the requested version
- WHEN the admin installs with `acceptNewSha: true`
- THEN the install MUST behave exactly as a normal first install (record on success)

---

### Requirement: Recorded digests are binding-scoped and surfaced [Hardening]

Recorded digests MUST live inside the source binding payload so their lifecycle follows the binding: rebinding an app to a different source MUST discard the previous binding's digests, while rebinding to the same source MUST preserve them. The binding read API and the external version list MUST expose recorded digests (they are not secrets), and the version picker MUST badge versions that have a recorded digest.

#### Scenario: Rebinding to a different source discards digests

- GIVEN `openregister` is bound to `github:ConductionNL/openregister` with recorded digests
- WHEN the admin rebinds it to `github:myorg/openregister-fork`
- THEN the new binding MUST contain no `sha256` entries from the previous binding

#### Scenario: Digests visible in version list

- GIVEN the binding records `sha256["2.3.0"]`
- WHEN the admin loads the version list for `openregister`
- THEN the 2.3.0 entry MUST include the recorded digest
- AND the picker MUST badge 2.3.0 as having a first-install checksum on record

## User Stories

1. As a Conduction admin, I want a rollback download to be byte-identical to what I originally installed, so that a rewritten GitHub release cannot silently put different code on my instance.
2. As a security officer, I want release-rewrite attempts to fail closed and be visible, instead of being absorbed by a checksum file the attacker also controls.
3. As an admin handling a legitimate upstream re-tag, I want an explicit, password-confirmed way to accept the new bytes.

## Acceptance Criteria

- [ ] `SourceBinding` carries a `sha256` version→digest map with typed accessors; round-trips through `fromArray`/`toArray`; 200-entry cap
- [ ] Digest recorded on every successful external install (sibling-verified or locally computed), never on failure
- [ ] Recorded digest checked before extraction; mismatch → `sha_mismatch`, no filesystem change, recorded digest outranks a sibling `.sha256`
- [ ] `acceptNewSha: true` bypasses once, replaces on success, is warning-logged (and audited when available)
- [ ] Rebind to different source discards digests; same source preserves them
- [ ] Binding API + external version list expose digests; picker badges recorded versions; mismatch dialog offers the explicit acceptance path
- [ ] `composer check:strict` passes; PHPUnit suite passes

## Notes

- Trust model is TOFU: the **first** install of a never-observed artifact is exactly as trusted as today (allowlist + appId/version checks + optional sibling checksum). This delta adds no first-contact protection and claims none.
- The sibling `.sha256` check is kept as a transport check; the recorded digest is the history check and wins on conflict.
- Cosign/Sigstore verification remains the future cryptographically complete answer (separate change); App Store installs keep the NC code-signing chain and are out of scope here.
