# Retrofit — frontend-context-endpoints

Describes observed behaviour of 2 methods on `ApiController` as 1 new Requirement under the existing `version-management` capability. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Controller/ApiController.php::adminCheck`
- `lib/Controller/ApiController.php::updateChannel`

Consumed by the Vue UI at page bootstrap (`src/` components that drive the version-list view). No other consumers.

## Why `--extend` rather than `--cluster`

The nearest existing capability is `version-management`. The two endpoints are UI-bootstrap helpers specifically for the App Versions UI — they don't make sense in any other capability context, and the App Versions UI is what the capability is about. Creating a new capability for two thin reader endpoints would fragment the spec without adding clarity. Per the skill's bias-toward-`--extend` guardrail, `--extend version-management` is the right call.

## Approach

- Drafted one Requirement that covers both endpoints, with three scenarios (admin-check happy path, update-channel happy path, update-channel forbidden)
- The Requirement makes the **deliberate asymmetry** between the two endpoints explicit in a Note: `admin-check` returns 200 for non-admins so the UI can branch; `update-channel` returns 403 because the channel value is operationally sensitive
- Matched the existing spec's format (`### Requirement: Title [Tier]`) rather than the REQ-NNN form used elsewhere — the skill's guardrail about matching sibling voice/format applies even across apps
- Annotated each method's docblock inline with `@spec openspec/changes/retrofit-frontend-context-endpoints-2026-04-24/tasks.md#task-1`
- The existing 9 Bucket 1 methods (ApiController.apps / appVersions / installVersion, InstallerService's three methods, SelectedReleaseInstallerService's three methods) are NOT annotated by this change — that belongs to a separate `/opsx-annotate` pass against the existing four Requirements

## Notes

- Spec frontmatter `status: idea` is stale — code is implemented. Out of scope here; follow-up should update to `in-progress` or `done` after Bucket 1 annotation lands.
- Delta uses `retrofit_extensions: ["Frontend Context Endpoints"]` rather than numeric REQ IDs because the capability's Requirements are named, not numbered. The archive step still picks up the marker for Specter sync.

Source: `openspec/coverage-report.md` generated 2026-04-24.
