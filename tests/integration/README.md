# Integration Tests (Newman / Postman)

Newman collection covering the 5 OCS endpoints on `app_versions`. CI runs
this via the reusable `code-quality.yml` workflow; locally, run with:

```bash
npm install -g newman
newman run tests/integration/app-versions.postman_collection.json \
  --env-var base_url=http://nextcloud.local \
  --env-var admin_user=admin \
  --env-var admin_password=admin
```

CI passes `base_url=http://localhost:8080`; locally use your dev instance.

## Coverage

| Folder | Endpoint | Purpose |
|---|---|---|
| Health Check | `GET /status.php` | Verifies Nextcloud is up before running the rest |
| Admin Check | `GET /api/admin-check` | Asserts the 200 / `isAdmin:true` happy path |
| Update Channel | `GET /api/update-channel` | Asserts the channel is returned for admins |
| Apps | `GET /api/apps` | Asserts the installed-apps list is an array |
| App Versions | `GET /api/app/{appId}/versions` | Asserts versions payload for a stable app (`files`) |

Not covered in the collection (require side effects / password confirm):
- `POST /api/app/{appId}/versions/{version}/install` — destructive, uses
  `#[PasswordConfirmationRequired]`; not safe to fire from a smoke test
  without dry-run scaffolding on the server.

## Enabling in CI

Already wired — `.github/workflows/code-quality.yml` has `enable-newman: true`.
The runner picks up every `*.postman_collection.json` in this directory.

## Adding tests

New cases go in this collection. Keep env placeholders; never bake credentials.
