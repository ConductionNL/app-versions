# source-license-headers Specification

## Purpose
TBD - created by archiving change add-spdx-license-headers. Update Purpose after archive.
## Requirements
### Requirement: Every lib PHP file carries an AGPL-3.0 licence and copyright header

Every PHP file under `lib/` MUST carry a licence/copyright header in its top docblock
declaring the repository licence (AGPL-3.0-or-later): a `@copyright` tag (Conduction B.V.),
a `@license AGPL-3.0-or-later` tag, and the REUSE `SPDX-License-Identifier:
AGPL-3.0-or-later` and `SPDX-FileCopyrightText` tags. The declared per-file licence MUST
match the `LICENSE` file and `composer.json` (both AGPL-3.0). No `lib/` PHP file may ship
with an absent or contradictory licence header.

#### Scenario: A lib source file declares its AGPL licence

- **WHEN** any `lib/**/*.php` file is inspected
- **THEN** its top docblock MUST contain `@license AGPL-3.0-or-later`, `@copyright`, and `SPDX-License-Identifier: AGPL-3.0-or-later`
- **AND** the value MUST match the repository `LICENSE` (AGPL-3.0) and `composer.json`

@e2e exclude source-header presence is a static REUSE/gate check, not a runtime UI flow.

#### Scenario: The spdx-headers gate passes

- **WHEN** the `spdx-headers` quality gate scans `lib/`
- **THEN** it MUST report zero files missing `@license`/`@copyright`
- **AND** the count of `lib/**/*.php` files with `SPDX-License-Identifier` MUST equal the total count of such files

@e2e exclude source-header presence is a static REUSE/gate check, not a runtime UI flow.

