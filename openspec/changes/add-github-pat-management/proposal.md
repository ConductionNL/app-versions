# Proposal: add-github-pat-management

## Summary
Add encrypted PAT (Personal Access Token) storage so admins can install Nextcloud apps from **private** GitHub repositories. PATs are per-admin by default, can be optionally shared with other admins, are validated for read-only scope (best-effort), and can be created via deeplinks to the GitHub PAT settings page with as much prefill as GitHub allows.

Builds on [`add-external-source-installs`](../add-external-source-installs/) — the install mechanism is unchanged; this proposal only adds the authentication layer that lets `GithubReleaseSource` see private releases.

## Motivation
After proposal 1, an admin can install ConductionNL apps from public GitHub releases. But many real-world cases need private-repo access:

- ConductionNL ships pre-release / customer builds in private repos before they hit public releases
- Customers run private forks with proprietary modifications
- Internal tooling apps that should never be public

Without PAT support, those installs require manual `wget`/`curl` outside the Nextcloud workflow. With PAT support they flow through the same allowlisted, integrity-checked install path as public releases.

## Scope

- Encrypted PAT storage (`ICrypto`) with a small dedicated table — never plaintext, never logged, never returned over the API after creation
- Per-admin ownership by default; optional `shared_with_admins` flag to make a PAT usable by other admins
- Best-effort scope validation:
  - **Classic PATs** (`ghp_*` prefix) — read scopes from `X-OAuth-Scopes` response header on `GET /user`. Reject if anything beyond `repo` (or `public_repo`) is granted.
  - **Fine-grained PATs** (`github_pat_*` prefix) — GitHub does not expose configured permissions to the holder. Probe with a benign call (`GET /user`) to confirm the token works; record an `unverifiable_scope` warning that the UI surfaces; do NOT silently accept tokens claiming to be read-only when we can't verify.
- Deeplinks to GitHub PAT creation:
  - **Classic** → `https://github.com/settings/tokens/new?scopes=repo&description=Nextcloud%20App%20Versions` (full prefill — works today)
  - **Fine-grained** → `https://github.com/settings/personal-access-tokens/new` (page link only; on-screen instructions for required permissions)
- `GithubReleaseSource` automatically uses an applicable PAT when one is available for the source's `owner/repo`; no controller-layer changes
- Token expiry tracking — read `github-authentication-token-expiration` header, store as `expires_at`, surface a warning in the UI when within 7 days of expiry

## Out of scope

- Refresh-token / OAuth-app flows. This proposal is PAT-only — no GitHub App / OAuth client.
- Auto-rotation of expiring PATs. Admins are notified; replacement is manual.
- Org-level (vs personal) PATs. Org PATs use the same API surface; whether to allow them is an admin decision via the trusted-source allowlist (already in place from proposal 1).
- Search / discovery UI. That's proposal 3.
