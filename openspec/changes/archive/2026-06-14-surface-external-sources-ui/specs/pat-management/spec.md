## ADDED Requirements

### Requirement: PAT management UI surfacing [MVP]

The admin UI MUST surface access-token (PAT) management so an admin can list redacted tokens, add a token, edit its label and share flag, delete it, and open a per-forge token-creation deeplink — using the existing PAT endpoints with no backend change beyond what `codeberg-forge-support` (#2) added. The UI MUST let the admin choose the forge (github|codeberg) when adding a token and when requesting a deeplink.

#### Scenario: List tokens redacted

- **GIVEN** PATs visible to the current admin exist
- **WHEN** the admin opens the Tokens tab
- **THEN** the UI MUST list the tokens from `GET /api/pats`
- **AND** MUST display only redacted fields (label, forge, token hint, share flag) and never a plaintext token or encrypted bytes

#### Scenario: Add a token via the UI

- **GIVEN** an admin opens the add-token form
- **WHEN** the admin selects forge `codeberg`, enters a label and target (owner [+ optional repo]), pastes a token, and submits with a confirmed password
- **THEN** the UI MUST call `POST /api/pats` with `forge`, `label`, derived `targetPattern`, and `token`
- **AND** on success the new redacted token MUST appear in the list

#### Scenario: Edit label and share flag

- **GIVEN** a token owned by the current admin is listed
- **WHEN** the admin changes its label or toggles share-with-admins and confirms their password
- **THEN** the UI MUST call `PATCH /api/pats/{id}` with the changed fields
- **AND** the updated redacted record MUST be shown

#### Scenario: Delete a token via the UI

- **GIVEN** a token owned by the current admin is listed
- **WHEN** the admin deletes it and confirms their password
- **THEN** the UI MUST call `DELETE /api/pats/{id}`
- **AND** the token MUST be removed from the list on success

#### Scenario: Per-forge deeplink

- **GIVEN** an admin clicks "create a token" for forge `codeberg`
- **WHEN** the UI requests `GET /api/pats/deeplink?forge=codeberg`
- **THEN** the UI MUST present the returned `url` and `instructions` for that forge
