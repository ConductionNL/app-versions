# Design: add-github-pat-management

## Storage

New table `oc_app_versions_pats` (Nextcloud automatically prefixes with `oc_`):

| column | type | notes |
| --- | --- | --- |
| `id` | bigint, autoinc, PK |  |
| `owner_uid` | string(64), indexed | Nextcloud uid that uploaded the PAT |
| `label` | string(128) | Admin-facing label, e.g. "ConductionNL prod" |
| `target_pattern` | string(255), indexed | `owner/repo` glob the PAT is scoped to (e.g. `ConductionNL/*`) — the source-binding lookup matches on this |
| `kind` | string(32) | `classic` \| `fine-grained` |
| `encrypted_token` | text | `ICrypto::encrypt()` output. Never returned via API. |
| `token_hint` | string(8) | First 4 + last 4 chars of plaintext for UI display. |
| `shared_with_admins` | bool, default false |  |
| `last_validated_scopes` | json | Result of last validation call: `{scopes: [...], rate_limit_remaining: int, validated_at: iso, warnings: [...]}` |
| `expires_at` | datetime, nullable | From `github-authentication-token-expiration` header |
| `last_used_at` | datetime, nullable |  |
| `created_at` | datetime |  |

Index on `(owner_uid, target_pattern)` for the per-source lookup.

## Encryption

Plaintext PAT is encrypted via `\OCP\Security\ICrypto::encrypt()` immediately on receipt. Only the returned ciphertext is persisted. Decryption happens only inside `PatManager::useToken()`, which yields the plaintext to a callback and discards it afterwards — no decrypted value is ever stored on a property or returned across method boundaries.

```php
$patManager->useToken($pat, function (string $plaintext) use ($source, $appId, $version): array {
    return $source->resolveReleaseAuthenticated($appId, $version, $plaintext);
});
```

## API surface

```
GET    /api/pats                       — list PATs visible to current admin (own + shared)
POST   /api/pats                       — upload + validate; body: {label, kind, targetPattern, token}
DELETE /api/pats/{id}                  — owner-only
PATCH  /api/pats/{id}                  — owner-only; body: {label?, sharedWithAdmins?}
GET    /api/pats/{id}/probe            — re-run validation against GitHub
GET    /api/pats/deeplink?kind=classic — returns the prefilled GitHub URL + recommended permissions
```

The `POST /api/pats` flow:

1. Receive `{label, kind, targetPattern, token}`
2. Sanity-check kind by token prefix (`ghp_` vs `github_pat_`)
3. Probe `GET https://api.github.com/user` with the token
   - 401 → 400 to caller, "Token is invalid or revoked"
   - 200 + `X-OAuth-Scopes: repo, write:packages` → 400, "PAT has scopes beyond what App Versions needs (write:packages)"
   - 200 + `X-OAuth-Scopes: ` empty for fine-grained → record `unverifiable_scope: true` warning, accept
4. Encrypt + persist; return id + redacted record (no plaintext)

## Authentication wiring into `GithubReleaseSource`

`GithubReleaseSource` gains an optional `PatResolver` dependency:

```php
final class PatResolver {
    public function findFor(SourceBinding $binding, string $currentUid): ?Pat {
        // Match (owner_uid = $currentUid OR shared_with_admins = true)
        //  AND target_pattern matches binding's owner/repo
        //  AND (expires_at IS NULL OR expires_at > NOW())
    }
}
```

When `GithubReleaseSource::listVersions` runs and `PatResolver` returns a PAT, the request gains `Authorization: Bearer <decrypted>`. Otherwise the request is unauthenticated (current behaviour). This keeps the public-release path intact.

## Deeplink

Classic PAT URL builder:

```
https://github.com/settings/tokens/new?
  scopes=repo&
  description=Nextcloud%20App%20Versions%20-%20{nextcloud-host}
```

For fine-grained PATs we only have a page link plus structured guidance returned in the API response:

```json
{
  "url": "https://github.com/settings/personal-access-tokens/new",
  "instructions": [
    "Repository access: Only select repositories — pick the ones you want App Versions to install from",
    "Permissions → Repository permissions:",
    "  - Contents: Read-only",
    "  - Metadata: Read-only (auto-included)",
    "Expiration: 90 days recommended"
  ]
}
```

The frontend renders these as a checklist next to the redirect button.

## Why per-admin default with optional share

Per-admin maximises blast-radius isolation: a leaked PAT only affects installs that admin would have done anyway. The "share with other admins" toggle exists because some teams use a single shared service-account PAT and don't want each admin to have to upload their own. Explicit toggle, never implicit — the default stays per-admin.

When an admin who owns the PAT is deleted from Nextcloud, their PATs are deleted by a hook on user-removal. (Implemented as part of this proposal — small but easy to forget.)

## Risks

| Risk | Mitigation |
| --- | --- |
| PAT leaked via API logs / responses | `encrypted_token` never appears in any DataResponse; only `token_hint` (first 4 / last 4 chars) is exposed |
| Fine-grained PAT scope can't be verified | Explicit `unverifiable_scope` warning surfaced in UI; admin sees that we couldn't enforce read-only |
| Admin deletion leaves orphan PATs | `Listener\UserDeletedListener` deletes PATs owned by the removed uid |
| Token expires silently | Background job (added in this proposal) checks `expires_at` weekly and writes a notification |
| Race condition: two admins re-probe same PAT simultaneously | `last_validated_scopes` write is idempotent; no locking needed |
| Bypass: admin uploads PAT with broad scopes via direct DB insert | Out of threat model — Nextcloud admins can already drop tables |
