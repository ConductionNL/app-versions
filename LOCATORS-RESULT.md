# versioniq: global-container lookups retired

Branch `refactor/injected-container-lookups`, PR https://github.com/ConductionNL/versioniq/pull/382 against `development`.
Commit `9786bcb`. Not merged by this run: the merge is the main session's.

## Sites

Fleet no-service-locator sniff on `lib`: **13 before, 0 after**. No `phpcs:ignore` annotations, so the 0 is a true 0 rather than 0 after exemptions.

| file | sites | how |
|---|---|---|
| `lib/Service/SelectedReleaseInstallerService.php` | 8 | six lazy getters return injected platform services (`IFactory`, `IAppManager`, `IConfig`, `IAppConfig`, `ITempManager`, `IClientService`); `ServerVersion` injected; `FilenameValidator` through the injected container |
| `lib/Service/ExternalReleaseInstallerService.php` | 3 | `IFactory` and `ServerVersion` injected; `FilenameValidator` through the injected container |
| `lib/Service/Installer/InstallFinalizer.php` | 2 | `Coordinator` and the raw DB `Connection` through the injected container |

Three lookups stay dynamic, through the injected `ContainerInterface` rather than the global server, because the classes are private core API with no OCP interface to type-hint: `OC\Files\FilenameValidator` in both installers, and `OC\AppFramework\Bootstrap\Coordinator` plus `OC\DB\Connection` in the finalizer. The Coordinator is what registers app services, so making it an eager constructor dependency would invite the very bootstrap cycle this change closes.

## Left alone

Nothing. `lib/AppInfo`, `lib/Migration`, `lib/Repair` and `lib/Resources` are excluded from the sniff by path, and versioniq has no lookups in them anyway.

## Call sites

No test constructs the three classes. `InstallerRecoveryTest` reaches their private methods through `newInstanceWithoutConstructor()`; everything else uses `createMock()`. Nothing in `lib` constructs them either, so the wider constructors are resolved entirely by autowiring.

## Verification, by exit code

| check | scope | before | after |
|---|---|---|---|
| unit suite `tests/phpunit-unit-only.xml --no-coverage` | full | 575 tests, 1195 assertions, exit 0, RSS 53 MB | identical |
| fleet sniff | `lib` | 13 | 0 |
| php-cs-fixer `--dry-run --diff` | 3 touched files | | 0 files to fix, exit 0 |
| psalm `--no-cache --memory-limit=2G` | 3 touched files | | no errors, exit 0 |

`phpunit.xml` refuses to run outside a Nextcloud checkout, by design; `tests/phpunit-unit-only.xml` is the standalone suite and is what ran. The app has no phpstan and no phpmd, and its phpcs equivalent is php-cs-fixer. The three touched files carry psalm baseline entries; none cover code this change touched, so none went stale.

Whole-tree `composer psalm` and `check:strict` were not run, per the memory discipline for the day.

## Left to do

Merge PR #382, then watch the `development` push run of `code-quality.yml`.
