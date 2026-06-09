## 1. Forge config & registry

- [x] 1.1 Add `lib/Service/Source/Forge.php` — immutable value object: `id`, `apiBaseUrl`, `webBaseUrl`, `authScheme` (`Bearer`|`token`), `exposesScopeHeader` (bool), `tokenCreateUrl`.
- [x] 1.2 Add `lib/Service/Source/ForgeRegistry.php` (DI singleton) with the `github` and `codeberg` entries from design D1; `get(string $forgeId): Forge` throws on unknown; add `has()`.
- [x] 1.3 Add `authHeaderValue(string $token): string` (or equivalent) so the auth scheme is derived from the `Forge`, not duplicated in callers.

## 2. Refactor GithubReleaseSource → ForgeReleaseSource

- [x] 2.1 Rename `lib/Service/Source/GithubReleaseSource.php` to `ForgeReleaseSource.php` (class + namespace usage), inject `ForgeRegistry`.
- [x] 2.2 Parameterize the API base (`{forge.apiBaseUrl}/repos/{owner}/{repo}/releases`) and auth header (`Bearer`|`token`) by the binding's forge; keep parsing/dedupe/sort/asset-selection/`.sha256` capture/error humanizing unchanged.
- [x] 2.3 Keep `getInstallerKind()` returning `INSTALLER_EXTERNAL`; keep unauthenticated fallback for public repos on both forges.
- [x] 2.4 Update DI wiring / any references to the old class name.
- [x] 2.5 Retarget the existing GitHub source unit test to `ForgeReleaseSource` (github forge) — behaviour must stay identical.

## 3. SourceBinding forge field & backward compat

- [x] 3.1 Add a `forge` discriminator (config field, values `github`|`codeberg`, default `github`) with a `getForge()` accessor, validated to the two values.
- [x] 3.2 Change `getId()` to `"{forge}:owner/repo"`; for `github` keep the exact prior string. Preserve the owner/repo path-traversal regex for both forges.
- [x] 3.3 `fromArray()` reads `forge` (default `github` when absent → legacy compat); `toArray()` always writes `forge`.
- [x] 3.4 Add `SourceBinding::codeberg(owner, repo, assetPattern)` factory mirroring `::github(...)`.

## 4. SourceRegistry / parseSourceId / listAvailable

- [x] 4.1 `get()` resolves `KIND_GITHUB_RELEASE` bindings to the single `ForgeReleaseSource` (driver reads forge from binding).
- [x] 4.2 `parseSourceId()` parses `appstore`, `github:owner/repo`, `codeberg:owner/repo`; reject unknown forge prefixes with `InvalidArgumentException`.
- [x] 4.3 `listAvailable()` adds a Codeberg entry (`id: codeberg`, label "Codeberg Releases (public)").

## 5. TrustedSourceList forge-qualified + legacy-bare compat

- [x] 5.1 Change matching to compare against the binding's `{forge}:owner/repo` id; patterns are forge-qualified globs.
- [x] 5.2 Normalize a stored bare `owner/repo` pattern (no forge prefix) to `github:owner/repo` before matching.
- [x] 5.3 Change `DEFAULT_PATTERNS` to `['github:ConductionNL/*', 'codeberg:Conduction/*']` (note the org differs per forge).
- [x] 5.4 Preserve `appstore` matching, fnmatch, and path-traversal protections.

## 6. Pat entity forge field

- [x] 6.1 Add `protected string $forge = 'github';` to `lib/Db/Pat.php` with `addType('forge','string')`, getter/setter `@method` annotations, and `forge` in `toRedacted()`.

## 7. DB migration

- [x] 7.1 Add `lib/Migration/Version1001Date20260609120000.php` adding column `forge` (`Types::STRING`, length 16, `notnull => true`, `default => 'github'`) to `app_versions_pats`, guarded by `hasColumn` for idempotent re-run.

## 8. PatValidator forge-aware (Codeberg path)

- [x] 8.1 Make `validate()` accept/derive the forge (default `github`) and read `apiBaseUrl`/`authScheme`/`exposesScopeHeader` from the `Forge`; probe `{apiBaseUrl}/user` with the forge auth header; keep SSRF guard `allow_local_address => false`.
- [x] 8.2 GitHub path unchanged (classic scope enforcement, fine-grained unverifiable-scope, expiry header).
- [x] 8.3 Codeberg path (`exposesScopeHeader=false`): accept valid token with the `unverifiable_scope` warning (Codeberg/Forgejo wording); 401 → "Token is invalid or revoked"; keep token-kind prefix detection GitHub-only.

## 9. PatDeeplinkBuilder Codeberg deeplink

- [x] 9.1 Add a Codeberg branch returning `url = https://codeberg.org/user/settings/applications` (read from the `Forge` tokenCreateUrl) with Forgejo least-privilege instructions; keep GitHub deeplinks unchanged.

## 10. PatResolver forge matching

- [x] 10.1 `findFor(...)` matches a PAT's `forge` against the binding's forge in addition to the owner/repo glob; legacy PAT rows (forge `github`) keep serving GitHub bindings.

## 11. Unit tests (ADR-009)

- [x] 11.1 `ForgeRegistry`: github/codeberg config values; unknown forge throws.
- [x] 11.2 `ForgeReleaseSource` for the codeberg config: lists releases from `codeberg.org/api/v1/...`, normalizes tag/assets, uses `Authorization: token`.
- [x] 11.3 `TrustedSourceList`: forge-qualified match (github + codeberg), untrusted codeberg rejected, legacy-bare → github, cross-forge isolation, default fallback.
- [x] 11.4 `SourceBinding`: codeberg id, legacy load (forge absent → github, id unchanged), path-traversal still rejected.
- [x] 11.5 `SourceRegistry::parseSourceId`: codeberg parsed, unknown forge rejected; `listAvailable` includes codeberg.
- [x] 11.6 `PatValidator`: Codeberg token validated via Forgejo, accepted with unverifiable-scope warning; GitHub path regression unchanged.
- [x] 11.7 `PatResolver`: forge-scoped matching (codeberg PAT ↔ codeberg binding); legacy github PAT still matches github binding.

## 12. Quality gates

- [x] 12.1 `composer cs:check` passes (this app has NO `composer check:strict`).
- [x] 12.2 `composer psalm` passes.
- [x] 12.3 `composer test:unit` passes.

## 13. Manual verification

- [ ] 13.1 Bind a real public Codeberg repo as a source (`codeberg:Conduction/<repo>`), confirm the version list loads.
- [ ] 13.2 Install a selected Codeberg release end to end (download + integrity checks + install).
- [ ] 13.3 (Optional) Upload a Codeberg access token, confirm it validates with the unverifiable-scope warning and authenticates a private-repo listing.
- [ ] 13.4 Migration-upgrade verification: run `occ upgrade` (or re-enable the app), confirm the `forge` column exists on `app_versions_pats`, existing PAT rows read back `forge = github`, and a legacy `github:` binding + legacy GitHub PAT still list versions.

## DEFERRED_QUESTIONS

- **Q:** Keep `KIND_GITHUB_RELEASE` with a `forge` config field, or mint a new forge-release kind? — **Choice:** keep `KIND_GITHUB_RELEASE` and carry `forge` in config (design D3), lower-risk for reading legacy persisted bindings with zero data migration. — **Affected:** design.md, `SourceBinding`, `SourceRegistry`, tasks §3–4.
- **Q:** Exact new migration class name/timestamp? — **Choice:** `Version1001Date20260609120000` (today's date 2026-06-09). — **Affected:** tasks §7, design.md (Migration Plan). Adjust if a later same-app migration timestamp collides.
- **Q:** Does the Codeberg token kind get its own `Pat::KIND_*` constant? — **Choice:** add a `Pat::KIND_FORGE_TOKEN` (opaque) constant for Codeberg tokens rather than reusing the GitHub `classic`/`fine-grained` kinds, since prefix detection is GitHub-specific and Codeberg tokens are opaque. The `forge` column is the authoritative discriminator; the kind is descriptive. — **Affected:** `lib/Db/Pat.php`, `PatValidator`, tasks §6/§8. (Implementation may keep the kind as `fine-grained`-equivalent if a constant proves unnecessary; revisit during §8.)
