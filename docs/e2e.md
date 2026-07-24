# End-to-end tests

The Playwright suite in `tests/e2e/` drives the real admin UI against a running
Nextcloud instance with App Versions enabled. It covers every capability the app
ships: the settings shell, version listing and release notes, safe mode and the
downgrade guard, pinning (including its audit entries), auto-update policies,
discovery, and the history / artifact-cache / tokens / trusted-source panels.

> **Never point the suite at production.** The specs create and clean up their
> own state (pins, policies, auto-update settings), but they write to the
> instance they are given.

## Bootstrapping a disposable instance

```bash
docker run -d --name av-e2e -p 8099:80 \
  -e SQLITE_DATABASE=nc.db \
  -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=adminadmin123 \
  -e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" \
  nextcloud:34.0.0-apache

# wait for the install to finish
until docker exec av-e2e php occ status 2>/dev/null | grep -q 'installed: true'; do sleep 5; done
```

Build the app and copy it in (a production build — `--no-dev` keeps
`symfony/console` out of the app's own `vendor/`, since Nextcloud already
provides it):

```bash
composer install --no-dev
npm ci && npm run build

docker cp . av-e2e:/var/www/html/custom_apps/app_versions
docker exec av-e2e rm -rf /var/www/html/custom_apps/app_versions/{node_modules,.git}
docker exec av-e2e chown -R www-data:www-data /var/www/html/custom_apps/app_versions
docker exec av-e2e php occ config:system:set apps_paths 1 path --value=/var/www/html/custom_apps
docker exec av-e2e php occ config:system:set apps_paths 1 url --value=/custom_apps
docker exec av-e2e php occ config:system:set apps_paths 1 writable --value=true --type=boolean
docker exec -u www-data av-e2e php occ app:enable app_versions

# The first-run wizard is a modal that covers the page and swallows clicks.
docker exec -u www-data av-e2e php occ app:disable firstrunwizard

# The version specs need a genuine App Store app with real release history.
# Shipped apps (dashboard, files, …) are unsuitable: they follow the server
# release, so the picker reports that rather than listing versions.
docker exec -u www-data av-e2e php occ app:install notes
```

### Warm the App Store caches first

The App Store catalogue endpoint answers with the *entire* store (~30 MB) and
ignores its `filter` parameter, so the very first version listing and the first
discovery search pay a large download. Both results are cached for an hour, so
warm them once before running the suite to keep it fast and predictable:

```bash
docker exec av-e2e curl -s -o /dev/null -u admin:adminadmin123 -H 'OCS-APIRequest: true' \
  'http://localhost/ocs/v2.php/apps/app_versions/api/app/notes/versions?format=json'
docker exec av-e2e curl -s -o /dev/null -u admin:adminadmin123 -H 'OCS-APIRequest: true' \
  'http://localhost/ocs/v2.php/apps/app_versions/api/discover?q=calendar&format=json'
```

## Running

```bash
npm run test:e2e          # headless
npm run test:e2e:ui       # Playwright UI mode
npx playwright test pinning   # a single spec
```

Configuration comes from the environment, so the same suite runs against any
instance:

| Variable        | Default                  |
| --------------- | ------------------------ |
| `NC_BASE_URL`   | `http://localhost:8099`  |
| `NC_ADMIN_USER` | `admin`                  |
| `NC_ADMIN_PASS` | `adminadmin123`          |

`tests/e2e/auth.setup.ts` logs in once and stores the session in
`tests/e2e/.auth/admin.json` (git-ignored); every other spec reuses it.

## Conventions

- Specs are **serial** (`workers: 1`): they share one admin settings page and
  several mutate server-side state.
- Prefer `data-testid` over text where a control has no stable accessible name;
  add the attribute to the component rather than writing a brittle selector.
- Each spec cleans up what it creates — pins and policies are removed in
  `afterEach`, and toggles are restored to their default.
- Assertions that depend on the App Store are given generous timeouts because
  the upstream payload is large; they are never silently skipped.
