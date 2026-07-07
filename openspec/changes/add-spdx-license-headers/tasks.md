# Tasks: add-spdx-license-headers

- [ ] 1.1 Add the AGPL-3.0-or-later licence/copyright header docblock (`@copyright` Conduction B.V., `@license AGPL-3.0-or-later`, `SPDX-License-Identifier: AGPL-3.0-or-later`, `SPDX-FileCopyrightText`) to the top of every PHP file under `lib/` (38 files). Preserve existing docblock content; do NOT alter code logic. Edit/Write tools file-by-file — never a blind regex.
  - **spec_ref**: `specs/source-license-headers/spec.md#requirement-every-lib-php-file-carries-an-agpl-30-licence-and-copyright-header`
  - **acceptance_criteria**:
    - All 38 `lib/**/*.php` contain `@license AGPL-3.0-or-later` + `@copyright` + `SPDX-License-Identifier: AGPL-3.0-or-later`
    - Header-only diff (no logic change); value matches LICENSE/composer.json (AGPL)
- [ ] 1.2 Verify: `grep -rL 'SPDX-License-Identifier' lib --include='*.php'` returns nothing; `spdx-headers` gate green; `openspec validate add-spdx-license-headers --strict` clean.
  - **spec_ref**: `specs/source-license-headers/spec.md#requirement-the-spdx-headers-gate-passes`
  - **acceptance_criteria**:
    - Zero lib PHP files missing the SPDX header; gate green
