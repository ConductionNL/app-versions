## Context

App Versions is a Nextcloud admin utility that installs specific versions of Nextcloud apps. Currently it registers a top-level navigation entry (via `<navigations>` in `info.xml`) and serves its Vue SPA through `PageController::index()` — a `FrontpageRoute` with `#[NoAdminRequired]`. This placement is inconsistent with Nextcloud conventions: admin utilities belong in **Settings → Administration**, not the global app menu.

The change relocates the UI to the admin settings panel. The Vue SPA itself is unchanged; only the host/registration mechanism changes.

**Current state:**
- `appinfo/info.xml` — has `<navigations>` block, no `<settings>` block
- `lib/Controller/PageController.php` — `index()` with `#[FrontpageRoute]` and `#[NoAdminRequired]`; admin-only enforcement is delegated to API layer
- `lib/AppInfo/Application.php` — registers only `UserDeletedListener`; no settings classes registered here

**Sibling pattern (openconnector, opencatalogi):** register settings entirely via `info.xml` — a `Sections/<Name>.php` (IIconSection) and `Settings/<Name>.php` (ISettings); no `IRegistrationContext` call needed.

## Goals / Non-Goals

**Goals:**
- Register App Versions under Settings → Administration using `IIconSection` + `ISettings`
- Remove the top-level navigation entry
- Enforce admin-only access at the Nextcloud framework level (settings panel is inherently admin-gated)
- Reuse existing `templates/index.php` and Vue bundle without modification

**Non-Goals:**
- Any changes to the frontend Vue SPA or API endpoints
- Database or data model changes
- OpenRegister integration (N/A — this app has no schemas or DB)
- ADR-016 (mandatory seed data) — not applicable; no schemas or database tables exist

## Decisions

### Decision 1: Registration via info.xml (not IRegistrationContext)

**Choice:** Declare settings classes in `appinfo/info.xml` under `<settings>`.

**Rationale:** This is the Nextcloud-standard mechanism and matches the pattern used by sibling Conduction apps (openconnector, opencatalogi). Using `IRegistrationContext` is not needed and would be redundant. The framework auto-discovers and instantiates classes listed in `info.xml`.

**Alternative considered:** Registering via `IRegistrationContext::registerSettings()` — rejected because info.xml is the canonical mechanism and is simpler.

### Decision 2: Separate Section and Settings classes

**Choice:** `lib/Sections/AdminSection.php` (IIconSection) + `lib/Settings/Admin.php` (ISettings).

**Rationale:** Nextcloud requires distinct classes for the sidebar section and the settings form panel. The section (`AdminSection`) provides the sidebar entry with icon, translated name, and priority. The settings class (`Admin`) provides the form body (the Vue SPA template).

**Class details:**
- `AdminSection::getID()` → `'app_versions'`
- `AdminSection::getName()` → `$l->t('App Versions')` (translatable, per ADR-005)
- `AdminSection::getPriority()` → `98` (near top; administrative utilities)
- `AdminSection::getIcon()` → `$urlGenerator->imagePath('app_versions', 'app.svg')` (the app ships `img/app.svg`)
- `Admin::getForm()` → `new TemplateResponse(Application::APP_ID, 'index', [], '')` (empty `renderAs` string embeds inside settings page)
- `Admin::getSection()` → `'app_versions'`
- `Admin::getPriority()` → `10`

### Decision 3: Remove PageController and its FrontpageRoute

**Choice:** Delete `lib/Controller/PageController.php` and the `GET /` `FrontpageRoute` entirely.

**Rationale:** `Admin::getForm()` constructs its `TemplateResponse` directly and never invokes the controller route, so once the nav entry is gone the `GET /` route is a dead entry point. Rather than leave an orphaned `#[NoAdminRequired]` route that non-admins could still hit to load a (data-less) shell, we remove it so the **only** entry point is the admin settings section. This is the cleaner, more secure end state (decided with the user).

**Consequence:** Any pre-existing bookmark to `…/apps/app_versions/` stops resolving. Acceptable: the app is admin-only and pre-1.0, with no external integrations depending on that URL.

**Alternative considered:** Retain the route tagged `@deprecated`. Rejected — it leaves a non-admin-reachable shell and a dead entry point.

### Decision 4: Access control model change

**Before:** `PageController::index()` is `#[NoAdminRequired]` — any logged-in user can load the page shell. Admin enforcement is done in each API endpoint via `IGroupManager::isAdmin()`.

**After:** The UI exists only as an admin Settings section, which the Nextcloud framework makes visible/reachable to admins only. With the page route removed (Decision 3), non-admins have no shell to load at all. The API layer's `IGroupManager::isAdmin()` checks remain unchanged as defence in depth.

## Risks / Trade-offs

- **Removed route breaks old bookmarks** — deleting `GET /` means any bookmark to `…/apps/app_versions/` stops resolving. Accepted: admin-only, pre-1.0, no known external dependants. The admin settings section is the documented entry point.
- **Template reuse** — `templates/index.php` calls `Util::addScript` and `Util::addStyle`, appropriate for a full-page render. When embedded as a settings form (empty `renderAs`), Nextcloud still loads the JS/CSS assets correctly, as settings pages are full Nextcloud page renders. No change needed.

## Migration Plan

1. Add new classes (`lib/Sections/AdminSection.php`, `lib/Settings/Admin.php`).
2. Edit `appinfo/info.xml` — remove `<navigations>` block, add `<settings>` block.
3. Remove `lib/Controller/PageController.php` (and the `GET /` FrontpageRoute); verify nothing else references it.
4. Run quality checks (`composer cs:check`, `composer psalm`, `composer test:unit`).
5. Manual verification: open Settings → Administration → App Versions; confirm SPA loads; confirm top-menu entry is gone; confirm the old `GET /` route no longer serves the UI.

**Rollback:** Revert the `info.xml` change, restore `PageController.php`, and delete the two new class files. No database or data is affected.

## Open Questions

None — the PageController removal and the capability placement (folded into `version-management`) were confirmed with the user.
