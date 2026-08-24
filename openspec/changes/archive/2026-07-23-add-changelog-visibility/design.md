# Design: add-changelog-visibility

## Data flow
Both sources already fetch full release metadata; the changelog is present in memory and currently dropped.

- `AppStoreSource`: App Store release objects carry `translations.{lang}.changelog`. Map requested UI language → fallback `en` → `null`. No extra HTTP call.
- `ForgeReleaseSource`: GitHub/Codeberg release JSON carries `body`. Already downloaded when listing releases. No extra HTTP call.

`SourceInterface::listVersions()` return shape gains `changelog: ?string` per entry; `InstallerService::getAppVersions` passes it through. Truncation (8 KiB + ` …[truncated]`) happens server-side in the envelope assembly so both sources share one code path.

## UI
- Version row: disclosure chevron → `<pre>`-style sanitized text block. We render notes as plain text with minimal markdown affordance (line breaks, bullet passthrough) — no HTML rendering, no external markdown lib. This makes the XSS scenario trivially safe (text nodes only).
- Aggregate panel: computed from the already-loaded versions array — slice between installed and target (exclusive/inclusive respectively), sort by version (downgrade = newest first), render label + notes per entry. Zero extra requests.

## Fail-soft rules
- try/catch around changelog extraction per release; failure → `null`, listing proceeds.
- Aggregate panel renders placeholder rows for `null` notes.

## Testing
- Unit: source mapping (translations fallback, body mapping, truncation, extraction throw → null).
- Vitest: row expansion, placeholder, aggregate ordering for upgrade and downgrade, script-tag inertness.

## Rejected alternatives
- Client-side fetching per release row (N+1 requests, PAT exposure surface) — rejected.
- Full markdown renderer — rejected for MVP; text rendering is safe and sufficient.
