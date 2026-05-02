---
status: implemented
---

# PAT Management Specification

**Status**: proposed
**Standards**: GitHub REST API v2022-11-28 (User endpoint, OAuth-Scopes header), Nextcloud `OCP\Security\ICrypto`
**Feature tier**: MVP

## Purpose

Encrypted Personal Access Token storage for the App Versions app, so admins can install apps from private GitHub repositories. Tokens are validated for least-privilege scope on upload, encrypted at rest, never returned over the API in plaintext, and automatically picked up by `GithubReleaseSource` when the bound `owner/repo` matches a stored PAT.

## ADDED Requirements

### Requirement: PAT storage [MVP]

The system MUST persist PATs in a dedicated table with encrypted token bytes, owner attribution, and a `target_pattern` glob that scopes the PAT to specific `owner/repo` paths.

#### Scenario: Upload a classic PAT

- **GIVEN** an admin POSTs `{label: "ConductionNL prod", kind: "classic", targetPattern: "ConductionNL/*", token: "ghp_abc..."}`
- **WHEN** the system validates and encrypts the token
- **THEN** a row MUST be inserted with `owner_uid = current admin uid`, `encrypted_token = ICrypto::encrypt(token)`, `token_hint = "ghp_abcd...xxxx"`, `shared_with_admins = false`
- **AND** the response MUST contain only the redacted record (no `encrypted_token`, no plaintext)

#### Scenario: PAT not exposed via API after creation

- **GIVEN** a PAT exists in the database
- **WHEN** the admin calls `GET /api/pats`
- **THEN** the response MUST NOT contain `encrypted_token`
- **AND** the response MUST NOT contain the plaintext token
- **AND** the response MUST contain `tokenHint` (first 4 + last 4 chars of plaintext, captured at upload)

#### Scenario: Per-admin default; optional share

- **GIVEN** admin A uploads a PAT with `sharedWithAdmins = false`
- **WHEN** admin B calls `GET /api/pats`
- **THEN** admin A's PAT MUST NOT appear in the response
- **GIVEN** admin A then PATCHes the PAT with `sharedWithAdmins = true`
- **WHEN** admin B calls `GET /api/pats`
- **THEN** admin A's PAT MUST appear in the response
- **AND** the row MUST still show `owner_uid = A` (admin B can use it but not delete it)

### Requirement: Encryption at rest [MVP]

PATs MUST be encrypted via `\OCP\Security\ICrypto::encrypt()` before persistence and decrypted only inside a tightly scoped callback in `PatManager::useToken()`.

#### Scenario: Plaintext never reaches a property

- **GIVEN** a PAT is being used to authenticate a GitHub fetch
- **WHEN** the request runs
- **THEN** the plaintext value MUST NOT be stored on any class property
- **AND** MUST NOT be returned across a method boundary except as the argument to the `useToken` callback
- **AND** MUST NOT be logged

### Requirement: PAT validation on upload [MVP]

The system MUST probe a PAT against `GET https://api.github.com/user` before persisting and reject tokens with broader scope than App Versions needs.

#### Scenario: Classic PAT with `repo` scope only — accepted

- **GIVEN** the admin uploads `ghp_*` with `X-OAuth-Scopes: repo`
- **THEN** the system MUST accept the PAT
- **AND** `last_validated_scopes` MUST contain `["repo"]`

#### Scenario: Classic PAT with extra write scope — rejected

- **GIVEN** the admin uploads `ghp_*` with `X-OAuth-Scopes: repo, write:packages, admin:org`
- **THEN** the system MUST reject with HTTP 400
- **AND** the error message MUST list the disallowed scopes (`write:packages, admin:org`)

#### Scenario: Fine-grained PAT — best-effort acceptance

- **GIVEN** the admin uploads `github_pat_*` and the User endpoint returns 200
- **THEN** the system MUST accept the PAT
- **AND** record `unverifiable_scope: true` in `last_validated_scopes.warnings`
- **AND** the API response MUST surface this warning so the UI can display "GitHub did not expose configured permissions; please verify they are read-only."

#### Scenario: Invalid or revoked token

- **GIVEN** the User endpoint returns 401
- **THEN** the system MUST reject with HTTP 400
- **AND** the error message MUST be "Token is invalid or revoked"

#### Scenario: Expiration captured

- **GIVEN** the response includes `github-authentication-token-expiration: 2026-08-15 12:00:00 UTC`
- **THEN** the system MUST parse and persist `expires_at = 2026-08-15T12:00:00Z`

### Requirement: Authenticated GitHub fetches [MVP]

When a PAT visible to the current admin matches the source binding's `owner/repo`, the system MUST attach `Authorization: Bearer <decrypted>` to the GitHub request and MUST update the PAT's `last_used_at` timestamp.

#### Scenario: Private repo accessible via PAT

- **GIVEN** admin A is bound to source `github:ConductionNL/private-build` and has uploaded a PAT with `target_pattern = ConductionNL/*`
- **WHEN** `GET /api/app/private-build/versions` runs
- **THEN** the GitHub API request MUST include `Authorization: Bearer <token>`
- **AND** the system MUST return the private repo's releases
- **AND** `pats.last_used_at` MUST be updated for that PAT

#### Scenario: No matching PAT — unauthenticated path

- **GIVEN** there is no PAT covering `github:ConductionNL/openregister`
- **WHEN** the version list runs
- **THEN** the request MUST be unauthenticated (matches proposal 1 behaviour)

#### Scenario: Expired PAT skipped

- **GIVEN** a PAT with `expires_at` in the past
- **WHEN** `PatResolver::findFor` is called
- **THEN** the expired PAT MUST be skipped
- **AND** the next-priority PAT (or unauthenticated) MUST be used

### Requirement: PAT management API [MVP]

The system MUST expose endpoints for listing, creating, updating, deleting, and re-probing PATs, plus a deeplink helper for the GitHub creation flow.

#### Scenario: Deeplink for classic PAT

- **GIVEN** an admin calls `GET /api/pats/deeplink?kind=classic`
- **THEN** the response MUST contain a `url` of the form `https://github.com/settings/tokens/new?scopes=repo&description=Nextcloud%20App%20Versions...`
- **AND** the response MUST contain an `instructions` array

#### Scenario: Deeplink for fine-grained PAT

- **GIVEN** an admin calls `GET /api/pats/deeplink?kind=fine-grained`
- **THEN** the response MUST contain `url = https://github.com/settings/personal-access-tokens/new`
- **AND** an `instructions` array describing the required Contents+Metadata read-only permissions

#### Scenario: Delete restricted to owner

- **GIVEN** admin A's PAT exists, shared with admins
- **WHEN** admin B calls `DELETE /api/pats/{id}`
- **THEN** the system MUST return 403
- **AND** the PAT MUST remain in the database

### Requirement: Admin removal cleans up PATs [MVP]

When a Nextcloud user is deleted, all PATs owned by that uid MUST be deleted.

#### Scenario: User deletion sweeps PATs

- **GIVEN** admin A owns PATs P1 (private) and P2 (shared)
- **WHEN** an admin deletes user A
- **THEN** the user-deleted listener MUST delete P1 and P2
- **AND** subsequent calls to `GET /api/pats` from admin B MUST NOT return P2

## User Stories

1. As an admin, I want to install apps from private ConductionNL repos so I can deploy customer-specific builds without leaving Nextcloud.
2. As a security-conscious admin, I want App Versions to refuse PATs with more rights than it needs so I cannot accidentally grant write access.
3. As an admin who left a team, I want my uploaded PATs to disappear when my account is removed so they don't outlive my access.

## Acceptance Criteria

- [ ] PAT table created via migration; idempotent re-run
- [ ] Classic PATs with non-`repo`/`public_repo` scopes are rejected on upload
- [ ] Fine-grained PATs are accepted with an `unverifiable_scope` warning surfaced to the UI
- [ ] Plaintext tokens never appear in API responses or logs
- [ ] Bound private repo lists versions when matching PAT exists; falls back unauthenticated otherwise
- [ ] Per-admin scoping by default; share-with-admins flag works
- [ ] User deletion sweeps the deleted user's PATs

## Notes

- This proposal does not handle GitHub Apps or OAuth flows. PATs only.
- Token storage is deliberately **not** the Nextcloud per-user crypto chain — `ICrypto` uses the system secret so PATs survive password changes (and admin handover, via the share toggle).
- A weekly background job that warns on expiring PATs is **out of scope** here; tracked as a follow-up task in tasks.md.
