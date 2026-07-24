# Proposal: add-occ-cli-commands

## Summary
Give App Versions a first-class `occ` surface: `app_versions:versions` (list available versions of an app) and `app_versions:install` (install a specific version, with `--source`, `--dry-run`, `--allow-downgrade`, `--json`). Alongside, decouple dry-run from the debug toggle in the HTTP API (`dryRun` becomes an independent parameter; `debug` only controls verbosity).

## Motivation
Direct upstream demand: nextcloud/server#36940 requested `occ app:install` with a version parameter — motivated by **reproducible Docker image builds** — and the implementing PR #40857 was never merged; core's CLI remains latest-only (verified against the NC34 manual and core source in deepdive-2026-07-23-app-versions). Forum admins ask for the capability "with GUI or OCC commands". App Versions has the whole install machinery but exposes it exclusively over OCS HTTP, which:

- cannot be used in image builds, provisioning scripts, or CI (no session, password-confirmation middleware),
- leaves `occ`-native admins (the majority of NC ops tooling) without access to the app's core value.

CLI execution runs as the server user with full config access — the trust context is equivalent to `occ app:install`, so password confirmation is not applicable (matching core occ semantics).

Secondary cleanup with the same motive (scriptability): today `debug=1` doubles as dry-run in the install endpoint. A script that wants a real install with verbose diagnostics, or a silent dry-run, cannot express either. `dryRun` becomes independent; `debug` keeps back-compat as *implying* dry-run only when `dryRun` is not supplied, with a deprecation note.

## Scope
- `lib/Command/ListVersions.php` (`app_versions:versions <appId> [--source=] [--json]`)
- `lib/Command/InstallVersion.php` (`app_versions:install <appId> <version> [--source=] [--dry-run] [--allow-downgrade] [--json]`)
- Commands registered in `info.xml`; both delegate to `InstallerService` (no duplicated logic)
- Exit codes mapped from the existing failure-category taxonomy (0 success; distinct non-zero per category class)
- Downgrades refused without `--allow-downgrade` (server-side parity with the UI safe-mode confirmation)
- HTTP API: `dryRun` parameter independent of `debug`; `debug` without `dryRun` keeps legacy behavior (deprecated)
- Docs: `docs/` CLI page

## Non-goals
- No pin/audit occ commands here (those belong to their own changes; the install command integrates with the pin guard if present)
- No interactive prompts

## Impact
- New capability spec: `cli-commands`; MODIFIED requirement in `version-management` (dry-run decoupling)
- Touches `lib/Command/` (new), `appinfo/info.xml`, `lib/Controller/ApiController.php`, `lib/Service/InstallerService.php`, `src/App.vue` (send explicit dryRun)
