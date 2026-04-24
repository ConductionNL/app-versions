# Installation

## From the Nextcloud app store

1. Sign in as administrator.
2. Open **Apps** → **Tools** category.
3. Find *App Versions* and click **Download and enable**.

## From source (developers)

```bash
cd /var/www/html/custom_apps
git clone https://github.com/ConductionNL/app-versions.git
cd app-versions
make dev-link
composer install --no-dev
npm install
npm run build
```

The `make dev-link` step creates a `../app_versions` symlink so Nextcloud loads
the app under its `<id>` (`app_versions`) even though the repo is cloned as
`app-versions` (dashes for GitHub, underscore for Nextcloud).

Then enable the app:

```bash
docker exec -u www-data nextcloud php occ app:enable app_versions
```

## Compatibility

- Nextcloud **31** – **33** (see `appinfo/info.xml` for the authoritative range)
- PHP **8.1** or newer
- Node **24+**, npm **11+** (build only — not required at runtime)

## Uninstall

```bash
docker exec -u www-data nextcloud php occ app:remove app_versions
```
