# Tasks: add-changelog-visibility

## Task 1: Source-level changelog extraction
- **Spec ref**: specs/changelog-visibility/spec.md (Requirement: Version listings carry release notes)
- **Status**: todo
- **Acceptance criteria**:
  - `AppStoreSource` maps `translations.{lang}.changelog` with `en` fallback, `null` when absent
  - `ForgeReleaseSource` maps release `body`, `null` when absent
  - Extraction wrapped fail-soft: a throwing mapper yields `null`, never a failed listing
  - Unit tests for both mappings + fallback + throw path

## Task 2: API envelope + truncation
- **Spec ref**: specs/changelog-visibility/spec.md (Requirement: Version listings carry release notes)
- **Status**: todo
- **Acceptance criteria**:
  - `GET /api/app/{appId}/versions` entries include nullable `changelog`
  - Shared truncation to 8 KiB + truncation marker in envelope assembly (`InstallerService::getAppVersions`)
  - `openapi.json` updated
  - Unit test: truncation boundary

## Task 3: Version-row changelog UI
- **Spec ref**: specs/changelog-visibility/spec.md (Requirement: Per-version changelog display)
- **Status**: todo
- **Acceptance criteria**:
  - Expandable disclosure per version row in `src/App.vue`, text-node rendering only (no v-html)
  - Localized "No release notes provided" placeholder
  - Vitest: expansion, placeholder, `<script>` body rendered inert

## Task 4: Aggregate range panel
- **Spec ref**: specs/changelog-visibility/spec.md (Requirement: Aggregate range changelog on target selection)
- **Status**: todo
- **Acceptance criteria**:
  - Computed from already-fetched versions array; zero extra requests
  - Upgrade: (installed, target] ascending towards target; downgrade: releases being rolled back, newest first
  - Coexists with the downgrade confirmation dialog (unchanged)
  - Vitest: ordering both directions, placeholder entries
