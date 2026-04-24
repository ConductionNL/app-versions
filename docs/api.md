# API reference

All endpoints are OCS routes under
`/ocs/v2.php/apps/app_versions/…`. Requests need the standard Nextcloud OCS
headers:

```
OCS-APIRequest: true
Accept: application/json
```

Every mutation endpoint additionally requires a valid session for an admin user.

## `GET /api/admin-check`

Returns whether the currently authenticated user is an administrator. Used by
the UI to gate the admin-only features on first load without round-tripping
`/ocs/v2.php/cloud/user`.

```json
{ "ocs": { "data": { "isAdmin": true } } }
```

## `GET /api/apps`

Returns every installed app as `{ id, label, isCore, preview, description }`.
The `isCore` flag distinguishes Nextcloud-shipped apps from custom / app-store
apps. The `preview` field is a base64 icon suitable for an `<img src>`.

## `GET /api/update-channel`

Returns the current Nextcloud update channel (`stable` / `beta` / `daily`).
The installer uses this to scope available versions.

```json
{ "ocs": { "data": { "updateChannel": "stable" } } }
```

## `GET /api/app/{appId}/versions`

Returns every version available for `{appId}` on the current channel, sorted
newest first. Each entry includes `{ version, downloadUrl, size, signature }`.

`{appId}` is the Nextcloud app id (the `<id>` in its `info.xml`).

## `POST /api/app/{appId}/versions/{version}/install`

Installs the named `{version}` of `{appId}`. The endpoint is annotated with
`#[PasswordConfirmationRequired(strict: false)]` — the UI re-prompts for the
admin password before firing the request.

**Body parameters:**

| Name | Type | Purpose |
|---|---|---|
| `targetVersion` | string | overrides `{version}` in the URL; the UI sends both so the server can reconcile if the user retried after a redirect |
| `version` | string | fallback for `targetVersion` |
| `debug` | bool | run dry-run; returns the per-step install plan without writing |

**Response shape (live install):**

```json
{
  "ocs": {
    "data": {
      "installStatus": "success",
      "appId": "notes",
      "fromVersion": "4.11.0",
      "toVersion": "4.10.2",
      "installedVersion": "4.10.2",
      "message": "Installed version 4.10.2 of notes"
    }
  }
}
```

**Response shape (dry run):**

Same envelope, with `installStatus: "dry-run"` and a `debug` array containing
each step the installer would execute.

## Errors

| Status | When |
|---|---|
| `400` | invalid `{version}` or `{appId}` |
| `403` | caller is not an administrator |
| `404` | `{appId}` is not installed on this server |
| `422` | version is outside the current update channel (safe mode) |
| `500` | install pipeline failed mid-flight — see `message` and Nextcloud log |

Error responses carry a generic `message` field; the real exception stays in
the server log (per ADR-005).
