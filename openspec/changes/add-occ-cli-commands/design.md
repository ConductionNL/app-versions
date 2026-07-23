# Design: add-occ-cli-commands

## Command layer
Two Symfony Console commands in `lib/Command/`, registered via `<commands>` in `info.xml`:

- `ListVersions` — wraps `InstallerService::getAppVersions($appId, $sourceOverride)`; table output via `Symfony\Component\Console\Helper\Table`, `--json` short-circuits to `json_encode` of the envelope.
- `InstallVersion` — wraps `InstallerService::installAppVersion(...)` with `dryRun` and source override; maps the returned structured outcome / thrown classified failure to exit codes.

Exit-code map (documented in command help + docs page):
`0` ok · `1` unknown/unclassified · `2` unknown app / bad arguments · `3` downgrade refused · `4` preflight_permission · `5` download · `6` integrity (checksum_mismatch|appid_mismatch|version_mismatch) · `7` incompatible · `8` finalize (check `installStatus`: reverted vs installed-but-broken, printed explicitly) · `9` untrusted source.

## Guards
- Self/core app guard reuses the `manageable` logic from `InstallerService::getInstalledApps` — refactor the predicate into a shared method rather than duplicating.
- Downgrade detection: `version_compare(target, installed, '<')` in the command (server-side guard for the API arrives in add-migration-safety-guard; the CLI flag is normative here regardless of that change's state).
- No `PasswordConfirmationRequired` — not applicable to CLI (documented in the spec's trust-context requirement).

## dryRun decoupling
`ApiController::installVersion` reads `dryRun` (bool, default null). Resolution: `dryRun ?? ($debug ? true : false)` — preserving legacy `debug⇒dry-run` only when `dryRun` is absent. `InstallerService` signature gains the explicit flag; the Vue client sends `dryRun` explicitly from the (renamed) "Dry run" toggle and `debug` from a separate verbosity toggle.

## Maintenance mode & concurrency
`installAppVersion` already wraps maintenance mode; CLI reuses it unchanged. Concurrent occ invocations are serialized by the same config-level maintenance flag; a second invocation during install fails preflight with a clear message.

## Testing
- Unit tests per command with a mocked `InstallerService` (exit-code map, JSON shape, guard refusals).
- One integration-style test running the command via the console application against the container (documented in tasks; CI-safe with App Store mocked).

## Rejected alternatives
- Extending core `occ app:install` upstream — already rejected upstream (PR #40857).
- A generic `app_versions:rollback` alias — deferred; `install <prior-version> --allow-downgrade` covers it until last-known-good lands (add-migration-safety-guard).
