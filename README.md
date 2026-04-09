# App Versions

**Browse every installed app. Inspect available releases. Jump to the exact version you want.**

`App Versions` is a Nextcloud admin tool for exploring installed apps and switching to a specific release from the app store metadata. It is built for local development stacks, debugging sessions, upgrade rehearsals, and those moments where "latest" is not good enough.

Instead of a plain selector, the UI opens with a searchable card grid of installed apps. Each card shows the app name, id, icon, and summary so you can move fast without guessing.

## What It Does

- Lists installed apps in a full-width card view with search and filters.
- Pulls app metadata such as title, summary, description, and preview icon.
- Shows available versions for a selected app.
- Supports install, update, and downgrade flows from the same screen.
- Includes a dry-run mode with debug output for safer testing.
- Warns about downgrade ranges and lets admins confirm intentionally.
- Marks protected core apps in the picker and blocks management of them in the backend.

## Guard Rails

This app is intentionally opinionated.

- `app_versions` cannot manage itself.
- always-enabled Nextcloud core apps are shown in the card view, but clearly marked as `CORE`
- protected apps do not expose a `Choose app` action
- backend checks still reject direct requests against protected apps

That means the UI is honest and the backend is not trusting the UI.

## Interface Snapshot

The flow is intentionally split in two modes:

1. **Discovery mode**
   Search and browse the app cards across the full page width.
2. **Action mode**
   After selecting an app, the screen switches to the familiar split view for version inspection, install actions, and debug/result output.

Extra controls live above the search:

- update channel
- safe mode
- install dry-run
- core app visibility filter

## Why You'd Use It

- reproduce a customer issue on an older app release
- validate an upgrade path before touching production
- compare app-store availability with what is installed locally
- dry-run version changes and inspect the installer debug output
- keep sharp boundaries around Nextcloud core apps

## Development

Frontend:

```bash
cd app_versions
npm install
npm run build
```

Useful frontend commands:

```bash
npm run watch
npm run lint
npm run stylelint
```

Backend quality checks:

```bash
composer install
composer lint
composer test:unit
composer psalm
```

## Stack

- Vue 3
- `@nextcloud/vue`
- Vite
- PHP 8.1+
- Nextcloud app framework

## Status

This app is currently shaped as an admin-focused utility for development and controlled environments. It is useful precisely because it gives more direct power than the default app management flow, so the protected-app restrictions are part of the design, not an afterthought.

## License

AGPL-3.0-or-later
