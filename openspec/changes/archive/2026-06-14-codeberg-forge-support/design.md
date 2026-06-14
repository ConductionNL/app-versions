## Context

App Versions already installs Nextcloud apps from GitHub releases (proposal 1: `external-sources`) with encrypted PAT support for private repos (proposal 2: `pat-management`). The install path is: `SourceBinding` (persisted per app) → `SourceRegistry` resolves it to a `SourceInterface` driver → `GithubReleaseSource` lists/resolves releases → `SelectedReleaseInstallerService` downloads and verifies the artifact. PATs are validated on upload (`PatValidator`), stored encrypted (`app_versions_pats`), and matched to a binding by `owner/repo` (`PatResolver`).

Conduction now publishes/mirrors its apps on **Codeberg**, a hosted **Forgejo** instance. Forgejo's release JSON is GitHub-shaped (`tag_name`, `assets[].name`, `assets[].browser_download_url`), and it serves public-repo release listings unauthenticated, exactly like GitHub. The differences are narrow: base URLs, the auth header scheme (`Authorization: token <t>` vs `Bearer <t>`), and the fact that Forgejo does not expose token scopes to the holder (no `X-OAuth-Scopes`).

This change generalizes the GitHub-specific pieces into a **forge** abstraction so both forges share one driver, validator, and trust model. Auth is via access tokens (PAT-style) only.

**Constraints:** admin-only (ADR-007); SSRF guard `nextcloud: ['allow_local_address' => false]` on all outbound forge calls (ADR-007); tokens encrypted at rest already (ICrypto, system secret); backend layering (ADR-008); mandatory unit coverage (ADR-009); i18n for user-facing strings (ADR-005); API conventions (ADR-002). **Hard requirement:** persisted legacy state (`{kind:'github-release', owner, repo}` bindings, `github:owner/repo` source-ids, existing GitHub PAT rows) MUST keep working unchanged.

## Goals / Non-Goals

**Goals:**
- A single `Forge` config + small registry that the driver and validator read, so adding a forge is config, not a new class.
- One generic `ForgeReleaseSource` replacing `GithubReleaseSource`; GitHub behaviour byte-for-byte identical (existing tests guard it, retargeted to the generic source).
- Codeberg install path end to end: forge-qualified source binding, source-id, trusted allowlist, token validation, and PAT resolution.
- Full backward compatibility for all persisted GitHub state.

**Non-Goals:**
- Codeberg discovery/search — deferred to a future discovery change. `GithubSearchDiscovery` / `GithubPrivateDiscovery` stay GitHub-only.
- OAuth flows — access tokens only.
- Any frontend forge picker — later change.
- New OpenRegister schemas — this app has none.
- Cryptographic scope verification for Codeberg tokens — Forgejo does not expose it; we accept with a warning.

## Decisions

### D1: `Forge` as an immutable value object + small registry
A `Forge` carries `id`, `apiBaseUrl`, `webBaseUrl`, `authScheme` (`Bearer`|`token`), `exposesScopeHeader` (bool), `tokenCreateUrl`. A `ForgeRegistry` (DI singleton) holds the two known forges and offers `get(string $forgeId): Forge` (throws on unknown) and `has()`.

- `github` → apiBaseUrl `https://api.github.com`, webBaseUrl `https://github.com`, authScheme `Bearer`, exposesScopeHeader `true`, tokenCreateUrl `https://github.com/settings/tokens`.
- `codeberg` → apiBaseUrl `https://codeberg.org/api/v1`, webBaseUrl `https://codeberg.org`, authScheme `token`, exposesScopeHeader `false`, tokenCreateUrl `https://codeberg.org/user/settings/applications`.

*Alternative considered:* a `ForgeInterface` with one subclass per forge. Rejected — the only behavioural delta is the auth header and the scope-header presence, both expressible as data; subclasses would be empty config holders. Config-as-data keeps the driver the single source of release-parsing logic.

### D2: Refactor `GithubReleaseSource` → `ForgeReleaseSource`
Rename the class and parameterize the two GitHub-specific bits: the API base (`{forge->apiBaseUrl}/repos/{owner}/{repo}/releases`) and the auth header (`Authorization: {Bearer|token} <token>` chosen from `forge->authScheme`). Everything else (release parsing, dedupe/sort, asset selection, `.sha256` capture, error humanizing) is forge-agnostic and unchanged. The driver reads the forge from the binding's `forge` field via `ForgeRegistry`. `getInstallerKind()` stays `INSTALLER_EXTERNAL`. The existing GitHub unit test is retargeted to `ForgeReleaseSource` with the github forge; a new test covers the codeberg forge config.

*Note:* Forgejo's `/repos/{owner}/{repo}/releases` accepts unauthenticated requests for public repos, so the unauthenticated fallback path is identical.

### D3: `KIND_GITHUB_RELEASE` kept, `forge` carried in config (lower-risk choice)
Keep the persisted binding `kind` value `'github-release'` and add `forge` as a config field rather than minting a new kind. Rationale: legacy rows are `{kind:'github-release', owner, repo}` with no forge; reading `forge` as `'github'` when absent makes every legacy row valid with zero data migration. A new kind would force a read-time translation of the legacy kind anyway, with more surface area. The binding stays a single `KIND_GITHUB_RELEASE` (semantically "forge release") that now also covers Codeberg.

- `SourceBinding` gains a `forge` accessor (`getForge()`, default `'github'`), validated to `github|codeberg`. `getId()` returns `"{forge}:owner/repo"` — for `github` this is byte-identical to today.
- `fromArray()` reads `forge` from the payload, defaulting to `'github'` when absent (legacy rows). `toArray()` writes `forge` (always, so new rows are explicit).
- The existing owner/repo path-traversal regex (`^[A-Za-z0-9_.\-]+$`) is preserved and applies to both forges.
- A `SourceBinding::codeberg(owner, repo, assetPattern)` factory mirrors `::github(...)`; both set `forge` in config.

### D4: Forge-qualified trusted allowlist with legacy-bare → github compat
`TrustedSourceList` patterns become `"{forge}:owner/repo"` globs. Matching is against the binding's `{forge}:owner/repo` id (no longer the bare owner/repo). `extractOwnerRepo` becomes "extract the comparable id":
- A stored pattern **with** a known forge prefix (`github:`/`codeberg:`) is used as-is.
- A stored pattern **without** a forge prefix (legacy bare `owner/repo`) is normalized to `github:owner/repo` before matching.
- The default changes from `['ConductionNL/*']` to `['github:ConductionNL/*', 'codeberg:Conduction/*']`. **Note the org differs per forge:** `ConductionNL` on GitHub, `Conduction` on Codeberg.
- `appstore` matching is preserved. fnmatch + the path-traversal protections in `SourceBinding` are preserved.

*Alternative considered:* keep bare patterns and match against bare owner/repo. Rejected — that cannot distinguish `github:Conduction/x` from `codeberg:Conduction/x`, defeating per-forge trust.

### D5: `SourceRegistry` resolves both forges to `ForgeReleaseSource`
`get()` returns the single `ForgeReleaseSource` for `KIND_GITHUB_RELEASE` (the driver reads the forge from the binding). `parseSourceId()` accepts `appstore`, `github:owner/repo`, and `codeberg:owner/repo`, rejecting unknown forge prefixes with `InvalidArgumentException`. `listAvailable()` adds a Codeberg entry (`id: codeberg`, label "Codeberg Releases (public)").

### D6: Forge-aware `PatValidator`
`validate()` takes the forge (defaulting to `github`) and reads `apiBaseUrl`, `authScheme`, `exposesScopeHeader` from the `Forge`. The probe hits `{apiBaseUrl}/user` with `Authorization: {Bearer|token} <token>`. SSRF guard preserved.
- **GitHub** (`exposesScopeHeader=true`): unchanged — classic `ghp_*` enforces `repo`/`public_repo` via `X-OAuth-Scopes`; fine-grained accepted with `unverifiable_scope` warning; expiry parsed from `github-authentication-token-expiration`.
- **Codeberg** (`exposesScopeHeader=false`): no scope header exists, so the token is accepted with the **same `unverifiable_scope` best-effort warning** (message reworded to name Codeberg/Forgejo). 401 → "Token is invalid or revoked".
- **Token-kind detection** stays GitHub-specific (`ghp_`/`github_pat_`). Codeberg tokens are opaque; `detectKind()` is only consulted on the GitHub path. Codeberg tokens get an opaque/forge-token kind (see DEFERRED_QUESTIONS).

### D7: `Pat` entity `forge` field + migration
`Pat` gains `protected string $forge = 'github';` with `addType('forge','string')`, getter/setter, and `forge` in `toRedacted()`. A new migration `Version1001Date20260609120000` adds column `forge` (`Types::STRING`, length 16, `notnull => true`, `default => 'github'`) to `app_versions_pats`, guarded by `hasColumn` for idempotent re-run. Existing rows take the `'github'` default — backward compatible, no data migration. `PatResolver::findFor(forge, ownerRepo, uid)` matches the binding's forge against the PAT's forge **and** the target pattern; legacy PAT rows (forge `'github'`) keep serving GitHub bindings.

*Note on bool-default quirk:* the existing migration omits the bool default because Nextcloud's MigrationService rejects a notnull bool with a default. A string column with a string default is fine, so `default => 'github'` is allowed here.

### D8: `PatDeeplinkBuilder` Codeberg deeplink
Add a Codeberg branch returning `url = https://codeberg.org/user/settings/applications` with Forgejo-specific instructions (create an access token, no scope prefill is possible). GitHub classic/fine-grained deeplinks unchanged. The builder reads the token-create URL from the `Forge` so the URL is not duplicated.

### ADR-016 (Seed Data): N/A
This app has no OpenRegister and no seed data; ADR-016 does not apply. No Seed Data section and no seed task are included.

### Discovery left as-is
`GithubSearchDiscovery` and `GithubPrivateDiscovery` remain GitHub-only for this change. Codeberg discovery is a future change.

## Risks / Trade-offs

- **[Codeberg tokens unverifiable]** Forgejo does not expose token scopes, so an over-privileged Codeberg token cannot be rejected on upload. → Accept with the explicit `unverifiable_scope` warning surfaced to the UI (same posture as GitHub fine-grained PATs); deeplink instructions steer admins to least privilege.
- **[Allowlist semantics change]** Matching moves from bare `owner/repo` to forge-qualified ids. A site that stored a custom bare pattern relies on the legacy-bare → github normalization. → Implement and unit-test the normalization explicitly; the default already covers both forges.
- **[Internal rename]** `GithubReleaseSource` → `ForgeReleaseSource` touches DI wiring and any test referencing the old class. → Compile-time/Psalm catches references; retarget the existing test; no persisted `kind` change so no data impact.
- **[New outbound host]** Calls to `codeberg.org` add an egress target. → SSRF guard `allow_local_address => false` applies identically; only public Forgejo hosts in the forge registry.
- **[Migration on large pat tables]** Adding a defaulted column locks briefly. → Single additive column with a constant default; negligible for an admin-utility table.

## Migration Plan

1. Ship code (`Forge`/`ForgeRegistry`, `ForgeReleaseSource`, updated `SourceBinding`/`SourceRegistry`/`TrustedSourceList`, `PatValidator`/`PatResolver`/`PatDeeplinkBuilder`, `Pat` entity).
2. `Version1001Date20260609120000` runs on `occ upgrade` / app enable, adding `forge` to `app_versions_pats` with default `'github'`. Idempotent via `hasColumn`.
3. Existing bindings load with `forge='github'` (absent → default); existing PAT rows get `forge='github'`; existing allowlist patterns (bare) normalize to `github:`.
4. **Rollback:** the column is additive and defaulted; reverting code leaves the column unused and harmless. If a hard rollback is required, a follow-up migration can drop `forge` (no code reads it after revert).
5. **Upgrade verification:** confirm the migration applied (`forge` column present, existing rows = `'github'`), a legacy github binding still lists versions, and a legacy GitHub PAT still authenticates.

## Open Questions

See DEFERRED_QUESTIONS in tasks.md for resolved-with-provisional-choice items (Codeberg `Pat::KIND_*` constant, migration class name/timestamp, keep-kind vs new-kind — decided keep-kind in D3).
