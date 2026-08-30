## MODIFIED Requirements

### Requirement: Install Specific Version [MVP]

The system MUST allow an admin to install any available version of an app, replacing the currently installed version. This operation MUST require password confirmation for security. Every install failure MUST return a structured payload — independent of the debug toggle — carrying `stage` (the last installer stage reached, e.g. `backup`, `download`, `checksum`, `archive-extracted`, `info-validated`, `finalize`), `category` (one of `preflight_permission | download | checksum_mismatch | extract | appid_mismatch | version_mismatch | incompatible | finalize | unknown`), and a `hint` (a human, actionable, translatable remediation string). The HTTP status MUST reflect the category and MUST NOT be a blanket 500 for classified failures; 500 is reserved for `unknown`/unexpected errors.

#### Scenario: Install an older version (rollback)

- GIVEN OpenRegister is currently at version 2.5.0
- WHEN the admin selects version 2.3.0 and confirms their password
- THEN the system MUST download version 2.3.0 from the app store
- AND replace the current app files with the downloaded version
- AND show a success message with the new version number
- AND the app MUST remain enabled after the version change
- AND the payload `installStatus` MUST be `installed`

#### Scenario: Install a newer version (upgrade)

- GIVEN OpenRegister is currently at version 2.3.0 and version 2.5.0 is available
- WHEN the admin selects version 2.5.0 and confirms
- THEN the system MUST download and install version 2.5.0
- AND any database migrations for the new version MUST be triggered

#### Scenario: Installation fails

- GIVEN a download or extraction error occurs during installation
- WHEN the admin attempts to install a version
- THEN the system MUST show a clear error message
- AND the previous version MUST remain intact (no partial installs)
- AND the response payload MUST include `stage`, `category`, and `hint` fields even when debug mode is OFF
- AND the `hint` MUST be an actionable, translatable remediation string

#### Scenario: Failure category drives HTTP status

- GIVEN an install fails with category `preflight_permission`
- WHEN the response is returned
- THEN the HTTP status MUST NOT be 500
- AND the status MUST be a category-appropriate code (e.g. 409 for `preflight_permission`, 422 for `incompatible`/`version_mismatch`/`appid_mismatch`/`checksum_mismatch`)
- AND a genuinely unexpected error MUST map to category `unknown` with HTTP 500

#### Scenario: Frontend surfaces the structured message

- GIVEN the backend returns a failure payload with `message` and `hint`
- WHEN the frontend renders the install result
- THEN the frontend MUST display the backend `message`/`hint` rather than the generic OCS meta message
- AND the result card MUST render the `stage`, `category`, and `hint`

#### Scenario: Password confirmation required

- GIVEN an admin clicks "Install" for a specific version
- WHEN the install action is triggered
- THEN the system MUST require password re-confirmation before proceeding
- AND the install MUST NOT proceed without valid password confirmation

## ADDED Requirements

### Requirement: Pre-flight Environment Checks [MVP]

The system MUST detect environment conditions that prevent a successful install before downloading a release, and surface them both proactively (on the app card) and as a fail-fast guard at install time. The authoritative functional check MUST be whether the parent directory of the app's install destination is writable by the web-server user (`is_writable(dirname($destination))`, because `rename()` of the existing folder requires write permission on the parent). Dev-checkout heuristics — presence of a `.git` directory in the app folder, and/or the app-folder owner differing from the web-server uid — MAY be used to enrich the human-readable warning but MUST NOT, by themselves, block an install.

#### Scenario: Non-manageable app is flagged on its card

- GIVEN an app folder whose parent directory is not writable by the web-server user (e.g. a bind-mounted dev checkout)
- WHEN the installed-apps list loads
- THEN that app's card data MUST include `manageable: false`
- AND a `warning` reason MUST be present explaining that the folder is not writable and installs will fail
- AND the warning MUST be a translatable string
- AND the warning MUST NOT prevent the card from being displayed

#### Scenario: Writable app reports manageable

- GIVEN an app folder whose parent directory is writable by the web-server user
- WHEN the installed-apps list loads
- THEN that app's card data MUST include `manageable: true`
- AND no blocking `warning` MUST be set for writability

#### Scenario: Install aborts fast on non-writable destination

- GIVEN an app folder whose parent directory is not writable by the web-server user
- WHEN the admin attempts to install a version of that app
- THEN the system MUST abort before downloading any release
- AND the failure payload MUST have category `preflight_permission`
- AND the HTTP status MUST NOT be 500
- AND the `hint` MUST advise fixing folder ownership/permissions (likely a bind-mounted dev checkout)

#### Scenario: Dev-checkout heuristics enrich but do not block

- GIVEN an app folder that is writable but contains a `.git` directory
- WHEN the installed-apps list loads
- THEN the install MUST NOT be blocked by the guard
- AND any `warning` derived from the `.git`/owner heuristic MUST be advisory only

### Requirement: Install Outcome Taxonomy and Finalize-Phase Recovery [MVP]

The install result MUST report one of three outcomes via `installStatus`: `installed` (clean success), `reverted` (a pre-finalize failure occurred and the previous files were restored from backup — fully safe), or `installed-but-broken` (the finalize phase failed, or the previous files could not be cleanly restored, leaving state uncertain). To make `reverted` possible for finalize-phase failures, the backup folder (`.appversion-backup`) MUST be retained until `finalize()` succeeds in BOTH installers; on a finalize-phase throw the system MUST attempt to restore the previous files from the backup. Because Nextcloud database migrations are forward-only, the system MUST NOT claim a clean rollback after a finalize-phase failure; the outcome MUST surface that files were reverted but database state is uncertain and a manual check is advised. The system does NOT handle database migration rollbacks.

#### Scenario: Clean install reports installed

- GIVEN a download, extraction, file-swap, and finalize all succeed
- WHEN the install completes
- THEN `installStatus` MUST be `installed`
- AND the backup folder MUST have been removed only after `finalize()` succeeded

#### Scenario: Pre-finalize failure reports reverted

- GIVEN the file copy fails before `finalize()` runs
- WHEN the failure is handled
- THEN the previous app files MUST be restored from the backup
- AND `installStatus` MUST be `reverted`
- AND the message MUST indicate the previous version is intact

#### Scenario: Finalize-phase failure reports installed-but-broken

- GIVEN `finalize()` throws (e.g. a declared migration or repair step fails)
- WHEN the failure is handled
- THEN the system MUST attempt to restore the previous files from the retained backup
- AND `installStatus` MUST be `installed-but-broken`
- AND the failure `category` MUST be `finalize`
- AND the `hint` MUST state that files were reverted but database migrations may have partially applied and cannot be rolled back automatically, advising a manual check

#### Scenario: Finalize failure with failed restore reports indeterminate state

- GIVEN `finalize()` throws AND restoring the previous files from backup also fails
- WHEN the failure is handled
- THEN `installStatus` MUST be `installed-but-broken`
- AND the `hint` MUST state that the install is in an indeterminate state requiring manual intervention

#### Scenario: App that swallows its own init error is out of scope

- GIVEN an installed app catches and logs its own initialization/boot exception (no exception propagates to App Versions)
- WHEN the install otherwise completes the file swap and finalize successfully
- THEN App Versions MUST report `installed`
- AND App Versions is NOT required to detect the app's internal init failure (this is explicitly out of scope and undetectable)