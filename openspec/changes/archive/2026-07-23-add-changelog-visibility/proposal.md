# Proposal: add-changelog-visibility

## Summary
Show release notes in the version picker: every version row can expand its changelog (App Store release changelog or forge release body), and when a target version is selected the picker aggregates the notes of every release between installed and target. Admins stop choosing versions blind.

## Motivation
Market research (deepdive-2026-07-23-app-versions, spectr register) identified changelog visibility as the highest-leverage missing UI capability. WP Rollback — the closest analogue, 300k+ active installs at 4.9/5 — headlines "Changelog viewing within the rollback screen" as a core feature. App Versions today shows version numbers and a major/minor/patch step count, but nothing about *what changed*, even though both source kinds already carry the data:

- **App Store**: the releases API exposes per-release `translations.{lang}.changelog`.
- **Forges**: GitHub/Codeberg release objects carry a `body` (release notes markdown) that `ForgeReleaseSource` already downloads and discards.

The rollback flow is exactly where an admin needs to read "what did 2.4.0 change?" before deciding whether to jump back past it.

## Scope
- `SourceInterface` version listings gain an optional `changelog` per version; `AppStoreSource` maps release translations (en fallback), `ForgeReleaseSource` maps release body
- `GET /api/app/{appId}/versions` returns `changelog` per version, server-side truncated (8 KiB) and null-safe
- UI: expandable changelog per version row; on target selection, an aggregate "changes between installed → target" panel concatenating intermediate release notes (ordered, labeled per version)
- Markdown rendered safely (escaped / sanitized — no raw HTML injection)
- Fail-soft: absent changelogs show "No release notes provided"; changelog fetching never delays or blocks the version list or install

## Non-goals
- No changelog parsing/semantics (no breaking-change detection)
- No caching beyond what version listing already does

## Impact
- New capability spec: `changelog-visibility`
- Touches `lib/Service/Source/*`, `lib/Service/InstallerService.php` (envelope), `src/App.vue`
