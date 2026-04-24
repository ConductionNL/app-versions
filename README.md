# App Versions

> Install any earlier or newer version of already installed Nextcloud apps.

A ConductionNL Nextcloud app that lets administrators roll back broken app
updates or pin specific newer releases without changing the server's global
update channel.

## Why

Nextcloud's built-in app manager only installs the latest release per update
channel. When an update breaks compatibility, introduces regressions, or ships
a schema change the administrator isn't ready for, there's no built-in way to
go back. App Versions closes that gap.

## Features

- **Rollback** to any earlier release the app store still offers.
- **Install a specific newer version** (beta, pre-stable) without touching the
  server's update channel.
- **Safe mode** (default on) blocks downgrades and rejects versions outside
  the current channel.
- **Dry-run mode** previews the full install pipeline — download, signature
  verification, extraction, migrations, repair steps — without writing to
  disk.
- **Admin-only**: non-admin users never see the UI or the API.

## Install

Via the Nextcloud app store: **Apps → Tools → App Versions → Download and
enable**.

From source: see [docs/installation.md](docs/installation.md).

## Usage

[docs/usage.md](docs/usage.md) walks through picking an app, selecting a
version, safe / dry-run modes, and reading the result panel.

## API

Five OCS endpoints under `/ocs/v2.php/apps/app_versions/api/…`. Full
request/response shapes + error codes in [docs/api.md](docs/api.md).

## Architecture

The app has no domain data of its own. Every call is a live query against the
Nextcloud app store via `IClientService`. See
[docs/architecture.md](docs/architecture.md) for the component diagram and
auth/DI posture.

## Development

```bash
composer install            # installs dev tooling (phpcs, phpmd, phpstan, psalm, phpunit)
npm install                 # installs the Vite + @conduction/nextcloud-vue stack
make dev-link               # symlink `../app_versions` → this repo (Nextcloud loads apps by <id>)
npm run dev                 # build the admin UI (rebuild on source change with `npm run watch`)
composer check:strict       # full quality pipeline: phpcs + phpmd + phpstan + psalm + phpunit
```

CI runs the same `composer check:strict` + `npm run lint` + Newman via
[.github/workflows/code-quality.yml](.github/workflows/code-quality.yml), the
consolidated ConductionNL quality gate. Individual linters are not separate
workflows — everything runs in one job.

## Compliance

Every PHP file carries an `@license EUPL-1.2` PHPDoc tag; Vue/TS carry SPDX
comments. REUSE.toml covers non-source paths. The ADR compliance matrix is in
[docs/adr-audit.md](docs/adr-audit.md) and tracks the partial / deferred items
from this cleanup PR.

## Licence

[EUPL-1.2](https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12). The
`<licence>agpl</licence>` in `appinfo/info.xml` is the Nextcloud schema
element for app-store publication — not the source licence.

## Support

support@conduction.nl
