# Design — retrofit-frontend-context-endpoints

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Context

The App Versions app's `version-management/spec.md` covers the four feature Requirements that make the app useful: List Installed Apps, Fetch Available Versions, Install Specific Version, Debug Mode. But the Vue UI also needs to ask the backend two bootstrap questions — "am I an admin?" and "which update channel am I on?" — in order to render the list correctly. The two endpoints that answer those questions (`adminCheck` and `updateChannel`) were written before the spec existed (or were considered too trivial to spec) and have never been documented.

This retrofit pins their observed behaviour as one new Requirement under the existing capability.

## Approach — what was written

- One new Requirement `Frontend Context Endpoints [MVP]` in
  `openspec/changes/retrofit-frontend-context-endpoints-2026-04-24/specs/version-management/spec.md` with three scenarios
- `retrofit_extensions: ["Frontend Context Endpoints"]` marker in the delta frontmatter so Specter can flag the row as a retrofit cohort
- `tasks.md` with one `[x]` task
- `@spec` annotations in `lib/Controller/ApiController.php` docblocks for `adminCheck` and `updateChannel`
- No other files touched; no Bucket 1 annotations in this change

## Deliberate judgement calls

- **One Requirement, not two.** The two endpoints are the same kind of thing (read-only bootstrap context for the UI). Two Requirements would inflate the spec without adding clarity. The asymmetric non-admin behaviour is pinned in a Note on the single Requirement.
- **Format match: `### Requirement: Title [Tier]`, not `REQ-NNN`.** The existing capability uses the forward-building format. The skill's stated rule is "match sibling voice, format, granularity" — so the retrofit delta matches. This differs from the mydash `legacy-widget-bridge` run (which used `REQ-LWB-001` style) because mydash's existing specs use REQ-NNN.
- **Scope excludes Bucket 1 annotation.** The coverage report identifies 9 Bucket 1 methods that map to the existing four Requirements. Annotating those is `/opsx-annotate`'s job, not reverse-spec's. Keeping the change tight makes the PR reviewable.
- **Stale `status: idea` not fixed here.** The existing spec is marked `status: idea` but implementation exists. Fixing that is out of scope — it would imply judgement about whether the whole capability is `in-progress` or `done`, which is a call for the app maintainer.

## Archive behaviour

On `/opsx-archive retrofit-frontend-context-endpoints-2026-04-24`:

- The new Requirement merges into `openspec/specs/version-management/spec.md`
- `retrofit_extensions` frontmatter merges alongside any existing frontmatter keys
- Specter sync reads `retrofit_extensions` and flags the app_specs row
