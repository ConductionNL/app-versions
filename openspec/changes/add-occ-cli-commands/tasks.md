# Tasks: add-occ-cli-commands

## Task 1: Shared manageability predicate
- **Spec ref**: specs/cli-commands/spec.md (Requirement: CLI trust context)
- **Status**: todo
- **Acceptance criteria**:
  - The self-app / core / always-enabled guard is extracted from `InstallerService::getInstalledApps` into a reusable method
  - Existing API behavior unchanged (unit-tested)

## Task 2: app_versions:versions command
- **Spec ref**: specs/cli-commands/spec.md (Requirement: List versions from the CLI)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Command/ListVersions.php` registered via `info.xml` `<commands>`
  - Table output + `--json`; `--source=` override; non-zero exit + stderr message on unknown app / source errors
  - Unit tests with mocked `InstallerService`

## Task 3: app_versions:install command
- **Spec ref**: specs/cli-commands/spec.md (Requirement: Install a specific version from the CLI)
- **Status**: todo
- **Acceptance criteria**:
  - `lib/Command/InstallVersion.php` with `--source`, `--dry-run`, `--allow-downgrade`, `--json`
  - Delegates to `InstallerService::installAppVersion`; no duplicated install logic
  - Documented exit-code map implemented (0/1/2/3/4/5/6/7/8/9 per design.md); downgrade refused without flag
  - Self/core guard refusal; unit tests cover the map and guards

## Task 4: dryRun decoupled from debug
- **Spec ref**: specs/cli-commands/spec.md (MODIFIED Requirement: Debug Mode)
- **Status**: todo
- **Acceptance criteria**:
  - API accepts independent `dryRun`; legacy `debug`-implies-dry-run only when `dryRun` absent
  - `debug=1&dryRun=0` performs a real install with debug timeline
  - Vue client sends explicit `dryRun` and `debug` from separate toggles
  - Unit tests for the resolution matrix; `openapi.json` updated

## Task 5: Docs
- **Spec ref**: specs/cli-commands/spec.md (all requirements)
- **Status**: todo
- **Acceptance criteria**:
  - `docs/` page documenting both commands, flags, exit codes, and a reproducible-provisioning example (Docker build snippet)
