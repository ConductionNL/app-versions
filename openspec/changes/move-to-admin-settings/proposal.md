## Why

App Versions is an admin-only utility that currently registers itself as a top-level navigation entry (the main app menu). This placement is inconsistent with Nextcloud conventions — admin utilities belong in the Administration section of Settings, not the global nav. Moving it there makes it idiomatic, improves discoverability for administrators, and enforces admin-only access at the framework level rather than relying solely on per-API checks.

## What Changes

- **Remove** the `<navigations>` block from `appinfo/info.xml` — the top-menu entry is eliminated.
- **Add** a `<settings>` block to `appinfo/info.xml` declaring `OCA\AppVersions\Settings\Admin` (ISettings) and `OCA\AppVersions\Sections\AdminSection` (IIconSection).
- **New file** `lib/Sections/AdminSection.php` — implements `OCP\Settings\IIconSection`; provides the App Versions section in the Administration panel.
- **New file** `lib/Settings/Admin.php` — implements `OCP\Settings\ISettings`; serves the existing Vue SPA template as the settings form body.
- **Remove** `lib/Controller/PageController.php` and its `FrontpageRoute` (`GET /`) — with the UI living in admin settings, the standalone page route is no longer an entry point and is deleted (no orphan route remains).
- **`lib/AppInfo/Application.php`** — no change required; settings classes are registered via `info.xml`, not `IRegistrationContext`.
- **Access model change**: the UI becomes admin-gated at the Nextcloud framework level (admin settings are inherently protected). Removing the `#[NoAdminRequired]` page route means non-admins have no shell to load at all; the API layer's `IGroupManager::isAdmin()` checks remain as defence in depth.

## Capabilities

### Modified Capabilities

- `version-management`: Adds **Admin Settings Placement** and **Settings Form Renders the Existing SPA** requirements (the UI moves from a top-nav entry to a Nextcloud admin Settings section, registered via `info.xml` `<settings>`, with the page route removed), and updates the "Non-admin user is blocked" scenario so access is enforced structurally (the settings section is invisible to non-admins) in addition to the existing API checks.

## Impact

- **`appinfo/info.xml`**: remove `<navigations>` block, add `<settings>` block.
- **New `lib/Sections/AdminSection.php`**: implements `IIconSection` — `getID()`, `getName()` (translatable via `IL10N`), `getPriority()`, `getIcon()` (via `IURLGenerator`).
- **New `lib/Settings/Admin.php`**: implements `ISettings` — `getForm()` returns `TemplateResponse` embedding existing `templates/index.php`, `getSection()` returns `'app_versions'`, `getPriority()` returns int.
- **`lib/Controller/PageController.php`**: removed (along with the `GET /` FrontpageRoute).
- **`lib/AppInfo/Application.php`**: no change.
- **Frontend (`src/`, `templates/index.php`)**: unchanged — the Vue SPA and its mount point are reused as-is.
- **No database changes**, no OpenRegister schemas, no API contract changes.
