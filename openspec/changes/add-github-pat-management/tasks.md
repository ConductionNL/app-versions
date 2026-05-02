# Tasks: add-github-pat-management

## Task 1: PAT entity + mapper + DB migration
- **Spec ref**: specs/pat-management/spec.md (Requirement: PAT storage)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Db/Pat.php` extends `Entity` with the columns from `design.md`
  - `lib/Db/PatMapper.php` extends `QBMapper`; provides `findById`, `findByOwner`, `findVisibleTo($uid)`, `findApplicableFor($ownerRepo, $uid)`
  - `lib/Migration/Version1000Date{...}AddPatTable.php` creates the table + index
  - Migration is idempotent (safe to re-run)

## Task 2: Encrypted storage + manager
- **Spec ref**: specs/pat-management/spec.md (Requirement: Encryption at rest)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Service/Pat/PatManager.php` encrypts via `ICrypto` on create
  - `useToken(Pat $pat, callable $callback)` decrypts only inside the callback; never stores plaintext on a property
  - Plaintext token NEVER appears in `Pat::jsonSerialize()` output
  - `getPatHint(string $token): string` returns first-4 + last-4 chars (e.g. `ghp_abcd...wxyz`)

## Task 3: Validator with classic + fine-grained scope detection
- **Spec ref**: specs/pat-management/spec.md (Requirement: PAT validation on upload)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Service/Pat/PatValidator.php::validate(string $token): ValidationResult`
  - Hits `GET https://api.github.com/user` with `Authorization: Bearer {token}`
  - For classic PATs: parses `X-OAuth-Scopes` header; rejects if any scope outside `{public_repo, repo}` is present
  - For fine-grained PATs: 200 = pass with `unverifiable_scope: true` warning; non-200 = reject
  - Reads `github-authentication-token-expiration` header into the result

## Task 4: PatResolver wiring into GithubReleaseSource
- **Spec ref**: specs/pat-management/spec.md (Requirement: Authenticated GitHub fetches)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Service/Pat/PatResolver.php` returns the highest-priority PAT visible to the current uid that matches the binding's `owner/repo`
  - `GithubReleaseSource` adds `Authorization: Bearer <decrypted>` only when a PAT was resolved
  - Unauthenticated path (no PAT) unchanged from proposal 1
  - PAT decryption happens inside `PatManager::useToken` and is discarded immediately after the HTTP call

## Task 5: PAT API endpoints
- **Spec ref**: specs/pat-management/spec.md (Requirement: PAT management API)
- **Status**: todo
- **Acceptance criteria**:
  - `GET /api/pats` returns redacted PATs (label, hint, kind, target_pattern, expires_at, last_used_at, last_validated_scopes) — visible to owner + shared
  - `POST /api/pats` validates and persists; returns 400 on overscoped classic PAT
  - `DELETE /api/pats/{id}` 403 if not owner
  - `PATCH /api/pats/{id}` (label, sharedWithAdmins) — owner only
  - `GET /api/pats/{id}/probe` — re-runs validator
  - `GET /api/pats/deeplink?kind=classic|fine-grained` — returns URL + instructions array
  - `PasswordConfirmationRequired` on POST/PATCH/DELETE

## Task 6: User-deleted hook
- **Spec ref**: specs/pat-management/spec.md (Requirement: Admin removal cleans up PATs)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Listener/UserDeletedListener.php` listens on `\OCP\User\Events\UserDeletedEvent`
  - Deletes all PATs where `owner_uid = $event->getUser()->getUID()`
  - Registered in `AppInfo\Application::register()`

## Task 7: Tests
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - Unit tests for `PatValidator::validate` covering: classic happy path, classic overscoped, classic invalid, fine-grained 200, fine-grained 401, expiration header parsed
  - Unit tests for `PatManager::useToken` ensuring plaintext never escapes the callback
  - Unit tests for `PatResolver::findFor` covering owner-only / shared / pattern matching / expired PAT exclusion
  - Unit tests for the deeplink builder
  - All tests pass via `tests/phpunit-unit-only.xml`

## Task 8: Browser verification
- **Spec ref**: all spec files
- **Status**: todo
- **Acceptance criteria**:
  - Run migration in container; confirm table created
  - `POST /api/pats` with a real (or test-issued) public_repo PAT → 200, hint returned
  - `POST /api/pats` with broadly-scoped classic PAT → 400 with overscope message
  - `POST /api/pats` with revoked / invalid token → 400
  - Bind a private repo with the PAT in place; `GET /api/app/{appId}/versions` returns versions
  - Without the PAT, the same private repo returns 404
  - `GET /api/pats/deeplink?kind=classic` returns the prefilled URL
