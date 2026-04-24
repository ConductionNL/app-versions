# App Versions

Install any earlier or newer version of already installed Nextcloud apps.

## What this app does

Nextcloud's built-in app management only lets administrators install the latest
release per update channel. App Versions adds two capabilities on top of that:

- **Roll back** a broken or incompatible update to a known-good earlier release.
- **Install a specific newer version** (beta / pre-stable) without changing the
  server's global update channel.

Essential for debugging, compatibility testing, and recovering from updates that
introduced regressions.

## Audience

Nextcloud server administrators. The app adds an admin-only navigation entry;
non-admin users never see the UI.

## Scope

- Works against the Nextcloud app store API, scoped to the server's current
  update channel.
- Does **not** replace Nextcloud's built-in app manager — it complements it.
- Does **not** manage app configuration migrations — schema changes introduced
  by later versions are the administrator's responsibility when downgrading.

## Safety

Downgrading can corrupt the database if schema migrations have already been
applied. The UI surfaces a downgrade warning and offers a dry-run install mode
so you can preview the operation without touching disk.

## Table of contents

- [Installation](installation.md)
- [Usage](usage.md)
- [API reference](api.md)
- [Architecture](architecture.md)
- [ADR compliance audit](adr-audit.md)
