---
retrofit_extensions:
  - "Frontend Context Endpoints"
---

# Version Management — retrofit delta

This delta adds one new Requirement to the existing `version-management` capability. It does not duplicate the four Requirements already in `openspec/specs/version-management/spec.md`; on archive, only the new Requirement below will be merged into the main spec.

## Requirements (delta)

### Requirement: Frontend Context Endpoints [MVP]

The system MUST expose two thin read-only endpoints that the Vue UI calls at page-bootstrap time to contextualise what it renders. Both endpoints return a single scalar value wrapped in a JSON envelope; neither performs side effects. These are UI-support endpoints, not feature behaviours — the UI uses the values to decide which controls to render and how to label the version list, not to gate access (admin gating is enforced per-request on the feature endpoints themselves).

#### Scenario: UI reads caller admin status

- GIVEN any authenticated user (admin or not) loads the App Versions page
- WHEN the UI sends `GET /api/admin-check`
- THEN the system MUST return HTTP 200 with body `{"isAdmin": <bool>}`
- AND the boolean MUST reflect whether the caller is a member of the Nextcloud `admin` group
- AND the endpoint MUST NOT return 403 for non-admins — it is designed to be safely callable by anyone so the UI can branch on the result

#### Scenario: UI reads the server's update channel

- GIVEN an admin user has loaded the App Versions page
- WHEN the UI sends `GET /api/update-channel`
- THEN the system MUST return HTTP 200 with body `{"updateChannel": "<channel-id>"}`
- AND the channel id MUST be the value returned by `IServerVersion::getChannel()` (e.g. `stable`, `beta`, `daily`)

#### Scenario: Non-admin requests the update channel

- GIVEN a signed-in non-admin user
- WHEN they send `GET /api/update-channel`
- THEN the system MUST return HTTP 403 with body `{"message": "Forbidden"}`
- AND the server's channel MUST NOT be disclosed
- **Note**: the two endpoints differ deliberately on non-admin handling. `admin-check` stays 200 so the UI can branch; `update-channel` returns 403 because the channel is operationally sensitive and non-admins have no legitimate use for it.
