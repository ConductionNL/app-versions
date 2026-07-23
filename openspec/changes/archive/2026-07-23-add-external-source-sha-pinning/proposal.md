# Proposal: add-external-source-sha-pinning

## Summary
Auto-pin the SHA-256 of every externally-sourced artifact on first successful install (trust-on-first-use), and enforce it on any later download of the same app+version from the same source. A maintainer (or an attacker with repo access) who rewrites an already-published GitHub release can then no longer ship altered bytes to instances that installed it before — the reinstall fails closed with an explicit, override-only escape hatch.

## Motivation
The `external-sources` spec closes its Notes with an acknowledged TODO:

> Future hardening work: auto-pin observed SHA-256 to the binding so a maintainer rewriting the GitHub release cannot ship altered bytes silently. Tracked as a TODO; not in this proposal's MVP.

Today the external path verifies a `.sha256` sibling asset **when the release publishes one** — but that sibling lives in the same release as the artifact, so whoever can rewrite the artifact can rewrite the checksum beside it. The only thing the attacker cannot rewrite is what this instance already observed. Recording the hash at first install converts "verify against what the source currently claims" into "verify against what we previously trusted":

- **Rollback integrity.** Rollback is this app's core flow; re-downloading an old release is precisely when release-rewriting bites (old tags attract no attention).
- **No publisher cooperation needed.** TOFU pinning works for releases with no `.sha256` sibling at all — we compute and record the digest locally.
- **Honest scope.** First install of a never-seen artifact remains exactly as trusted as today (allowlist + appId/version checks + optional sibling checksum). This change adds no protection for first contact and the spec says so.

This is the standard model of `apt` pinned hashes, lockfile integrity fields (npm/yarn `integrity`), and Renovate's pinned digests, applied to NC app artifacts.

## Scope
- Record `sha256` per (appId, version) on every **successful** external install — from the verified `.sha256` sibling when present, otherwise computed locally from the downloaded archive (which `ExternalReleaseInstallerService` already hashes via `hash_file('sha256', ...)`)
- Storage: a `sha256` map (`version → hex digest`) inside the existing `SourceBinding` config payload (`source.{appId}` app config) — recorded hashes are scoped to, and lifecycle-bound to, the binding
- Enforcement in `ExternalReleaseInstallerService`: recorded hash mismatch → fail closed before extraction, backup/restore guarantees unchanged
- Override: `acceptNewSha: true` install parameter (password-confirmed like every install) replaces the recorded hash; the event is logged and audited
- Lifecycle: rebinding an app to a different source discards the recorded hashes of the old binding
- Surfacing: version list marks versions with a recorded hash ("matches first-install checksum" once verified); binding API returns the map (hashes are not secrets)

## Out of scope
- First-contact protection (no prior observation → nothing to compare; unchanged trust model)
- Cosign / Sigstore signature verification — the cryptographically complete future answer, separate change
- Hash pinning for App Store installs — that path keeps the full NC code-signing chain, which is stronger
- A central/shared hash database across instances — future work

## Dependencies
- None hard. If `add-version-audit-trail` is deployed, `acceptNewSha` overrides and mismatch failures appear in the audit trail; otherwise they are logged via `LoggerInterface` only.
