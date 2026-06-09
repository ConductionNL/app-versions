## Why

App Versions can install apps from GitHub releases, but Conduction increasingly mirrors and publishes its apps on Codeberg (a Forgejo instance). Admins need to install releases from Codeberg too, without a second parallel code path that drifts from the GitHub one. Forgejo's release API is GitHub-shaped, so a small generic "forge" abstraction lets both forges share one driver, validator, and trust model.

## What Changes

- Introduce a generic **forge abstraction**: a small `Forge` config/registry (one entry per forge) describing `apiBaseUrl`, `webBaseUrl`, `authScheme` (`Bearer` vs `token`), whether the forge exposes a scope header, and the token-create URL. GitHub and Codeberg are the two entries.
- Refactor `GithubReleaseSource` into a generic `ForgeReleaseSource` parameterized by a `Forge`. GitHub becomes the `forge=github` configuration; behaviour for GitHub is unchanged. `getInstallerKind()` stays `INSTALLER_EXTERNAL`.
- Add a **`forge` discriminator** to `SourceBinding` (values `github` | `codeberg`, default `github`). `getId()` becomes `"{forge}:owner/repo"`; `github:owner/repo` is preserved exactly for backward compatibility.
- Make `SourceRegistry` resolve both `github:` and `codeberg:` ids to `ForgeReleaseSource` with the right `Forge`; `parseSourceId()` parses both forges (rejecting unknown ones); `listAvailable()` gains a Codeberg entry.
- Make the **trusted-source allowlist forge-qualified** (e.g. `github:ConductionNL/*`, `codeberg:Conduction/*`). Default changes from `["ConductionNL/*"]` to `["github:ConductionNL/*", "codeberg:Conduction/*"]`. A legacy bare `owner/repo` pattern is treated as `github:owner/repo`.
- Make `PatValidator` **forge-aware**: GitHub path unchanged; Codeberg probes `{apiBaseUrl}/user` with `Authorization: token <token>` and — because Forgejo does not expose token scopes — accepts the token with the existing `unverifiable_scope` best-effort warning. Codeberg tokens are opaque (no `ghp_`/`github_pat_` prefix detection).
- Add a `forge` field to the `Pat` entity (default `'github'`) and a **new migration** adding a `forge` column to `app_versions_pats` (default `'github'`, notnull); existing rows default to `github`. `PatResolver` matches by owner/repo **and** forge.
- Add a **Codeberg token-create deeplink** to `PatDeeplinkBuilder` (`codeberg.org/user/settings/applications`); GitHub deeplinks unchanged.
- **BREAKING (internal API rename only):** `GithubReleaseSource` is replaced by `ForgeReleaseSource`. No persisted-data or HTTP-API break — legacy `github-release` bindings, `github:owner/repo` source-ids, and existing GitHub PATs keep working.

Out of scope (deferred): Codeberg discovery/search (the existing GitHub discovery providers stay GitHub-only), OAuth (PAT/access-token auth only), and any frontend forge picker.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `external-sources`: generic forge abstraction; Codeberg as a second forge; forge-qualified trusted allowlist (with legacy-bare → github compat); forge-aware source binding, registry, and source-id parsing; full backward compatibility for GitHub.
- `pat-management`: forge-aware token validation (Codeberg via Forgejo, accepted with unverifiable-scope warning); `forge` column plus migration; forge-aware PAT resolution; Codeberg token-create deeplink.

## Impact

- **Code:** `lib/Service/Source/` (`GithubReleaseSource` → `ForgeReleaseSource`, new `Forge` config/registry, `SourceBinding`, `SourceRegistry`, `TrustedSourceList`), `lib/Service/Pat/` (`PatValidator`, `PatResolver`, `PatDeeplinkBuilder`), `lib/Db/Pat.php`, new `lib/Migration/Version1001Date*.php`.
- **Database:** one new column `forge` on `app_versions_pats` (additive, defaulted; no data migration needed).
- **External services:** outbound calls to `codeberg.org/api/v1` in addition to `api.github.com`; SSRF guard (`nextcloud: ['allow_local_address' => false]`) applies to both.
- **Tests:** `tests/Unit/Service/Source/` and `tests/Unit/Service/Pat/` updated/extended (forge registry, `ForgeReleaseSource` for the codeberg config, forge-qualified allowlist incl. legacy-bare, Codeberg token validation, backward compat).
- **Unaffected:** App Store install path, discovery providers, OpenRegister (none), frontend.
