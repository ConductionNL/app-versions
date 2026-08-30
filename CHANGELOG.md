# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- First release
- Structured install-failure diagnostics: every failed install now returns a
  `stage`, `category` (`preflight_permission`/`download`/`checksum_mismatch`/
  `extract`/`appid_mismatch`/`version_mismatch`/`incompatible`/`finalize`/
  `unknown`) and an actionable `hint`, regardless of the debug toggle. The HTTP
  status reflects the category (e.g. 409, 422, 502) instead of a blanket 500.
- Pre-flight environment checks: app cards show a warning when an app's folder
  is not writable by the web-server user (e.g. a bind-mounted dev checkout), and
  installs abort early with a clear `preflight_permission` error before
  downloading anything.
- Install outcome taxonomy (`installed` / `reverted` / `installed-but-broken`):
  the backup of the previous app version is now retained until finalization
  (migrations + repair steps) succeeds, and is restored on a finalize-phase
  failure. The result honestly reports when files were reverted but database
  state may be uncertain.

### Fixed

- The install result no longer overwrites the backend's actionable message with
  the generic "OCS request failed" text; the structured message and hint are
  shown instead.

### Changed

- Versioniq UI moved from the top-level navigation to Settings → Administration (admin-only). Non-admin users who previously had the app in their navigation will no longer see it there.
- **Renamed from "App Versions" to "Versioniq"** with the rest of the Conduction
  fleet. The Nextcloud app id changes from `app_versions` to `versioniq`, which
  also moves the API routes (`/apps/app_versions/...` → `/apps/versioniq/...`)
  and the `occ` command prefix (`occ app_versions:*` → `occ versioniq:*`).
  Nextcloud has no in-place app-id upgrade, so two repair steps run on install
  to copy stored settings from the old id to the new one; nothing has to be
  reconfigured by hand. Stored personal access tokens and the audit trail are
  unaffected — they live in tables the rename does not touch.
