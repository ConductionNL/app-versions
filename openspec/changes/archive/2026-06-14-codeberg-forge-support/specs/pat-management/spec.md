## ADDED Requirements

### Requirement: Forge attribution on PATs [MVP]

Each stored PAT MUST carry a `forge` attribute (`github` | `codeberg`, default `github`) so a token is matched only to bindings on the same forge. The `app_versions_pats` table MUST gain a `forge` column via an additive, idempotent migration with a `github` default, so existing rows remain valid without a data migration.

#### Scenario: Migration adds forge column with github default

- **GIVEN** an existing `app_versions_pats` table with rows that predate this change
- **WHEN** the `forge`-column migration runs
- **THEN** the column MUST be added as a non-null string with default `github`
- **AND** existing rows MUST read back `forge = github`
- **AND** re-running the migration MUST be a no-op (guarded by `hasColumn`)

#### Scenario: PAT resolution is forge-scoped

- **GIVEN** a stored Codeberg PAT (`forge = codeberg`, `target_pattern = Conduction/*`) and a stored GitHub PAT (`forge = github`, `target_pattern = ConductionNL/*`)
- **WHEN** `PatResolver::findFor` resolves a token for binding `codeberg:Conduction/private-build`
- **THEN** only the Codeberg PAT MUST be considered (forge MUST match in addition to owner/repo glob)
- **AND** a GitHub PAT MUST NOT authenticate a Codeberg request

## MODIFIED Requirements

### Requirement: PAT validation on upload [MVP]

The system MUST probe a PAT against `GET {forge.apiBaseUrl}/user` (using the forge's auth scheme — `Bearer` for GitHub, `token` for Codeberg) before persisting, with the SSRF guard `nextcloud: ['allow_local_address' => false]`. For forges that expose token scopes (GitHub), the system MUST reject tokens with broader scope than App Versions needs. For forges that do NOT expose token scopes (Codeberg/Forgejo, `exposesScopeHeader = false`), the system MUST accept a valid token with an `unverifiable_scope` best-effort warning. GitHub token-kind prefix detection (`ghp_` / `github_pat_`) remains GitHub-specific; Codeberg tokens are treated as opaque.

#### Scenario: Classic PAT with `repo` scope only — accepted

- **GIVEN** the admin uploads a GitHub `ghp_*` token with `X-OAuth-Scopes: repo`
- **THEN** the system MUST accept the PAT
- **AND** `last_validated_scopes` MUST contain `["repo"]`

#### Scenario: Classic PAT with extra write scope — rejected

- **GIVEN** the admin uploads a GitHub `ghp_*` token with `X-OAuth-Scopes: repo, write:packages, admin:org`
- **THEN** the system MUST reject with HTTP 400
- **AND** the error message MUST list the disallowed scopes (`write:packages, admin:org`)

#### Scenario: Fine-grained GitHub PAT — best-effort acceptance

- **GIVEN** the admin uploads a GitHub `github_pat_*` token and the User endpoint returns 200
- **THEN** the system MUST accept the PAT
- **AND** record `unverifiable_scope: true` in `last_validated_scopes.warnings`
- **AND** the API response MUST surface this warning so the UI can display "GitHub did not expose configured permissions; please verify they are read-only."

#### Scenario: Codeberg token — validated via Forgejo, accepted unverifiable-scope

- **GIVEN** the admin uploads a Codeberg access token for `forge = codeberg`
- **WHEN** the system probes `https://codeberg.org/api/v1/user` with `Authorization: token <token>` and the endpoint returns 200
- **THEN** the system MUST accept the PAT
- **AND** because Forgejo does not expose token scopes (no `X-OAuth-Scopes`), the system MUST record an `unverifiable_scope` warning (worded for Codeberg/Forgejo)
- **AND** the API response MUST surface this warning to the UI

#### Scenario: Invalid or revoked token

- **GIVEN** the forge User endpoint returns 401
- **THEN** the system MUST reject with HTTP 400
- **AND** the error message MUST be "Token is invalid or revoked"

#### Scenario: Expiration captured

- **GIVEN** a GitHub response includes `github-authentication-token-expiration: 2026-08-15 12:00:00 UTC`
- **THEN** the system MUST parse and persist `expires_at = 2026-08-15T12:00:00Z`

### Requirement: Authenticated GitHub fetches [MVP]

When a PAT visible to the current admin matches the source binding's forge AND `owner/repo`, the system MUST attach the forge's auth header (`Authorization: Bearer <token>` for GitHub, `Authorization: token <token>` for Codeberg) to the forge request and MUST update the PAT's `last_used_at` timestamp. PAT matching MUST be forge-scoped: a PAT only authenticates a binding on the same forge.

#### Scenario: Private GitHub repo accessible via PAT

- **GIVEN** admin A is bound to source `github:ConductionNL/private-build` and has uploaded a GitHub PAT with `target_pattern = ConductionNL/*`
- **WHEN** `GET /api/app/private-build/versions` runs
- **THEN** the GitHub API request MUST include `Authorization: Bearer <token>`
- **AND** the system MUST return the private repo's releases
- **AND** `pats.last_used_at` MUST be updated for that PAT

#### Scenario: Private Codeberg repo accessible via Codeberg PAT

- **GIVEN** admin A is bound to source `codeberg:Conduction/private-build` and has uploaded a Codeberg token with `forge = codeberg`, `target_pattern = Conduction/*`
- **WHEN** the version list runs
- **THEN** the Codeberg request MUST include `Authorization: token <token>`
- **AND** the system MUST return the private repo's releases
- **AND** `pats.last_used_at` MUST be updated for that PAT

#### Scenario: No matching PAT — unauthenticated path

- **GIVEN** there is no PAT covering `codeberg:Conduction/openregister`
- **WHEN** the version list runs
- **THEN** the request MUST be unauthenticated (public repo path)

#### Scenario: Expired PAT skipped

- **GIVEN** a PAT with `expires_at` in the past
- **WHEN** `PatResolver::findFor` is called
- **THEN** the expired PAT MUST be skipped
- **AND** the next-priority PAT (or unauthenticated) MUST be used

#### Scenario: Legacy github PAT still authenticates (backward compat)

- **GIVEN** a PAT row that predates this change (no `forge` set, reads back as `github`)
- **WHEN** it is resolved for a `github:ConductionNL/private-build` binding
- **THEN** it MUST match and authenticate exactly as before

### Requirement: PAT management API [MVP]

The system MUST expose endpoints for listing, creating, updating, deleting, and re-probing PATs, plus a deeplink helper for the forge token-creation flow. The deeplink helper MUST support GitHub (classic and fine-grained) and Codeberg, reading the token-create URL from the forge configuration.

#### Scenario: Deeplink for classic GitHub PAT

- **GIVEN** an admin calls `GET /api/pats/deeplink?kind=classic`
- **THEN** the response MUST contain a `url` of the form `https://github.com/settings/tokens/new?scopes=repo&description=Nextcloud%20App%20Versions...`
- **AND** the response MUST contain an `instructions` array

#### Scenario: Deeplink for fine-grained GitHub PAT

- **GIVEN** an admin calls `GET /api/pats/deeplink?kind=fine-grained`
- **THEN** the response MUST contain `url = https://github.com/settings/personal-access-tokens/new`
- **AND** an `instructions` array describing the required Contents+Metadata read-only permissions

#### Scenario: Deeplink for Codeberg token

- **GIVEN** an admin requests a Codeberg token-creation deeplink
- **THEN** the response MUST contain `url = https://codeberg.org/user/settings/applications`
- **AND** an `instructions` array describing creating a least-privilege access token and pasting it back into App Versions

#### Scenario: Delete restricted to owner

- **GIVEN** admin A's PAT exists, shared with admins
- **WHEN** admin B calls `DELETE /api/pats/{id}`
- **THEN** the system MUST return 403
- **AND** the PAT MUST remain in the database
