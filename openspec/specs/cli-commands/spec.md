---
status: implemented
---

# CLI Commands Specification

**Status**: implemented
**Standards**: Symfony Console (OC\Core\Command\Base), occ command registration via info.xml
**Feature tier**: MVP

## Purpose

Expose App Versions' version listing and version-specific install through `occ`, so provisioning scripts, CI pipelines, and Docker image builds can reproduce exact app versions — the capability core `occ app:install` lacks and declined to add (nextcloud/server#36940, PR #40857 unmerged). Commands are thin adapters over `InstallerService`; every integrity check, source binding rule, and failure classification of the HTTP path applies identically.

## Requirements

### Requirement: List versions from the CLI [MVP]

`occ app_versions:versions <appId>` MUST print the available versions of the app from its bound source (or `--source=<sourceId>` override), including installed version, compatibility markers, and source id. `--json` MUST emit the same data as machine-readable JSON. Errors (unknown app, unreachable source) MUST exit non-zero with the classified message on stderr.

#### Scenario: Human listing

- GIVEN `openregister` is installed and bound to the App Store
- WHEN `occ app_versions:versions openregister` runs
- THEN it MUST print the installed version and the available versions with compatibility markers
- AND exit code MUST be 0

#### Scenario: JSON listing

- WHEN `occ app_versions:versions openregister --json` runs
- THEN stdout MUST be valid JSON containing `installedVersion`, `availableVersions`, `sourceId`

#### Scenario: Unknown app

- WHEN `occ app_versions:versions nope` runs
- THEN the exit code MUST be non-zero and stderr MUST name the problem

### Requirement: Install a specific version from the CLI [MVP]

`occ app_versions:install <appId> <version>` MUST install the requested version through `InstallerService::installAppVersion` — same source resolution, allowlist, integrity verification, backup/restore, maintenance-mode, and finalize behavior as the HTTP path. `--source=` MUST act as the one-off source override; `--dry-run` MUST run the existing dry-run path without swapping files; `--json` MUST emit the structured outcome. A downgrade (target lower than installed) MUST be refused with a distinct exit code unless `--allow-downgrade` is passed. Exit codes MUST map the failure-category taxonomy: 0 success/dry-run-ok, and documented distinct non-zero codes for at least `preflight_permission`, `download`, integrity failures (`checksum_mismatch`/`appid_mismatch`/`version_mismatch`), `incompatible`, `finalize`, downgrade-refused, and unknown.

#### Scenario: Reproducible pinned install

- GIVEN a provisioning script for a fresh instance
- WHEN `occ app_versions:install openregister 2.3.0` runs and the source delivers a valid signed release
- THEN version 2.3.0 MUST be installed and the exit code MUST be 0

#### Scenario: Downgrade requires the flag

- GIVEN `openregister` installed at 2.5.0
- WHEN `occ app_versions:install openregister 2.3.0` runs without `--allow-downgrade`
- THEN no install MUST happen and the exit code MUST be the documented downgrade-refused code
- AND rerunning with `--allow-downgrade` MUST proceed

#### Scenario: Dry run leaves the instance untouched

- WHEN `occ app_versions:install openregister 2.3.0 --dry-run --json` runs
- THEN stdout MUST report the dry-run outcome (`updateType`, checks passed)
- AND the installed version MUST remain unchanged

#### Scenario: Integrity failure exits distinctly

- GIVEN the downloaded artifact fails its checksum
- WHEN the install command runs
- THEN the exit code MUST be the documented integrity code and the app MUST remain at its prior version (restore guarantee)

### Requirement: CLI trust context [MVP]

Commands MUST run without password confirmation (CLI executes as the server user, matching core `occ app:install` semantics) and MUST be registered via `info.xml` so they exist wherever the app is enabled. The command MUST refuse to run when the app being managed is App Versions itself or a core/always-enabled app, mirroring the API guard.

#### Scenario: Self-management refused

- WHEN `occ app_versions:install app_versions 1.0.0` runs
- THEN it MUST refuse with a non-zero exit code

## Implementation Notes

- `lib/Command/ListVersions.php` and `lib/Command/InstallVersion.php`, registered via `<commands>` in `appinfo/info.xml`; both delegate to `InstallerService` (no duplicated logic).
- Documented exit-code map: `0` ok · `1` unknown/unclassified · `2` unknown app / bad arguments (includes the self/core-app guard) · `3` downgrade refused · `4` preflight_permission · `5` download · `6` integrity (`checksum_mismatch`/`appid_mismatch`/`version_mismatch`/`sha_mismatch`) · `7` incompatible · `8` finalize (installStatus — `reverted` vs `installed-but-broken` — printed explicitly) · `9` untrusted source.
- `InstallerService::isManageableApp()` is the single shared self/core-app predicate, reused by `getAppVersions()`, `installAppVersion()`, and `InstallVersion`'s CLI pre-check (no duplicated guard logic).
- The MODIFIED "Debug Mode" requirement in `version-management` (dry-run decoupled from `debug`) is the API-side counterpart this capability depends on — see that spec's "Debug Mode" requirement.
