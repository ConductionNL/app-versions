# Design: add-external-source-sha-pinning

## Trust model: TOFU (trust-on-first-use)

| Moment | Today | With this change |
| --- | --- | --- |
| First install of `app@v` from external source | allowlist + appId/version match + sibling `.sha256` if present | unchanged — **plus** the observed digest is recorded |
| Reinstall/rollback to `app@v` later | same checks against whatever the source serves *now* | downloaded bytes MUST hash to the recorded digest, else fail closed |

The sibling `.sha256` asset is co-located with the artifact, so a release rewrite rewrites both; it authenticates transport, not history. The recorded digest is the only history the source cannot rewrite.

## Storage: inside `SourceBinding`, not a new key

The binding payload (`source.{appId}` app config JSON) gains a `sha256` map:

```json
{
  "kind": "github-release",
  "owner": "ConductionNL",
  "repo": "openregister",
  "assetPattern": "*.tar.gz",
  "boundAt": "2026-05-02T12:00:00Z",
  "sha256": {
    "2.5.0": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
    "2.3.0": "60303ae22b998861bce3b28f33eec1be758a213c86c93c076dbe9f558c11c752"
  }
}
```

Why here and not a separate `sha.{appId}` key:

- A recorded hash is meaningless outside its source — `2.5.0` from `github:ConductionNL/openregister` and from a fork are different artifacts. Binding-scoped storage makes the lifecycle automatic: **rebinding to a different source replaces the binding payload and the old hashes die with it** (re-binding to the *same* owner/repo preserves them).
- `SourceBinding` already round-trips unknown config keys through `fromArray`/`toArray`; the value object gains typed accessors `getRecordedSha(string $version): ?string` and `withRecordedSha(string $version, string $sha): self`.

Size is bounded in practice (tens of versions × 64 hex chars); a cap of 200 entries (evict oldest by insertion order) keeps the config value from growing without bound.

## Enforcement point

`ExternalReleaseInstallerService` already computes `hash_file('sha256', $tempFile)` for sibling verification. Order of checks becomes:

1. Allowlist (unchanged, fail fast before download)
2. Download → compute actual SHA-256 once
3. **Recorded-hash check**: if the binding records a hash for this version and it differs → fail with "Artifact for openregister@2.5.0 does not match the checksum recorded at first install (expected …, got …). The release may have been rewritten upstream." — before extraction, before backup, no filesystem change
4. Sibling `.sha256` verification (unchanged)
5. Extraction + appId/version match (unchanged)
6. On overall install success: record/confirm the hash in the binding

Recording happens **only on success** — a failed install must not poison the map with a hash of bytes that never ran.

`acceptNewSha: true` skips check 3 for this one request and, on success, replaces the recorded hash. It rides the existing password-confirmed install request; the replacement is warning-logged and audited (`message` carries old + new digests) when the audit capability is present.

## Surfacing

- Version list (external sources): versions present in the map get `recordedSha: "<hex>"` in the API payload; the picker badges them ("matches first-install checksum" after a verified reinstall, "checksum recorded" otherwise).
- Mismatch error responses carry a machine-readable `code: "sha_mismatch"` so the UI can render the explanatory dialog with the explicit "Accept new checksum and install" escape hatch (which re-submits with `acceptNewSha: true`).
- `GET /api/source/{appId}` (binding read) includes the map — digests are public-by-nature, not secrets.

## Risks

| Risk | Mitigation |
| --- | --- |
| Legitimate release rewrite (maintainer re-tags to fix a packaging error) bricks reinstall | Explicit `acceptNewSha` escape hatch behind the same password confirmation as any install; clear dialog explaining what changed |
| Hash recorded from a compromised *first* install | Out of scope by design (TOFU); stated honestly in spec Notes — first contact is as trusted as today, no worse |
| Config value growth | 200-entry cap with oldest-first eviction |
| Stale hashes after source rebind | Lifecycle is binding-bound: new binding payload ⇒ old map gone; same-source rebind preserves |
| Failure poisoning the map | Record only after full install success |
