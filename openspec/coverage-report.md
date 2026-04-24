# Coverage Report — app-versions

Generated: 2026-04-24 10:30 UTC
Branch: feature/template-and-adr-cleanup
Scanner: opsx-coverage-scan v1 (manual drive)

> **Scanner note**: this run was a focused manual drive to test
> `/opsx-reverse-spec` downstream on a small app with a Requirement-style
> feature spec. Pass A classification complete; Bucket 3 (unimplemented) and
> Bucket 4 (ADR conformance) intentionally skipped.

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 0 | — (no `@spec` tags in app-versions yet) |
| plumbing | 3 | — (never tagged) |
| 1 — Requirement matched | 9 | `/opsx-annotate app-versions` (follow-up, not in this run) |
| 2a — existing capability, no Requirement | 0 | — |
| 2b — no capability owner | 2 (1 cluster: `frontend-context-endpoints`) | `/opsx-reverse-spec app-versions --extend version-management` |
| 3 — Requirement broken / unimplemented | — (skipped) | — |
| 4 — ADR conformance | — (skipped) | — |

## Bucket 1 — Ready to annotate (9 methods)

All map to the four existing Requirements in `version-management/spec.md`:

- **List Installed Apps** — `ApiController::apps`, `InstallerService::getInstalledApps`
- **Fetch Available Versions** — `ApiController::appVersions`, `InstallerService::getAppVersions`
- **Install Specific Version** — `ApiController::installVersion`, `InstallerService::installAppVersion`, `SelectedReleaseInstallerService::installFromSelectedRelease`, `SelectedReleaseInstallerService::replaceWithSelectedRelease`
- **Debug Mode** — `SelectedReleaseInstallerService::getDebugLog`

No confidence below 0.85. Annotation is a separate pass via `/opsx-annotate`.

## Bucket 2b — No capability owner (2 methods) ⭐ TEST TARGET

### cluster: frontend-context-endpoints (2 methods)

File: `lib/Controller/ApiController.php`. Zero spec references.

- `adminCheck` — Returns `{isAdmin: bool}` derived from `isAdmin()` helper. Always 200 (never 403) — the UI uses the boolean to decide which controls to render, not to gate access. `#[NoAdminRequired]` because non-admins need a non-error response.
- `updateChannel` — Returns `{updateChannel: string}` from `IServerVersion::getChannel()`. Admin-only inside the method (returns 403 to non-admins despite `#[NoAdminRequired]`). Consumed by the UI's version filter.

Both endpoints are UI-bootstrap context readers. Related to but distinct from the existing Requirement "Fetch Available Versions" (which has a "Respect update channel" scenario about filtering, not about exposing the channel to the UI). Running reverse-spec with `--extend version-management` adds one new Requirement covering both, following the skill's bias-toward-`--extend` guardrail.

## Notes for the human reviewer

- **Spec format**: `version-management/spec.md` uses `### Requirement: Title [Tier]` (not `REQ-NNN`) with `status: idea` frontmatter — a forward-building feature-spec format. The reverse-spec output in this run matches that format so the capability stays internally consistent.
- **Stale `status: idea`**: the 4 Requirements are implemented in code. Flagged for a follow-up to update the status to `in-progress` or `done` once Bucket 1 annotations land.
- **Small app surface**: 5 PHP files, no Vue component classification needed (Vue files scanned separately in a full scan; skipped here).
