## 1. Create AdminSection class

- [x] 1.1 Create directory `lib/Sections/` if it does not already exist
- [x] 1.2 Create `lib/Sections/AdminSection.php` implementing `OCP\Settings\IIconSection` with constructor injecting `IL10N $l` and `IURLGenerator $urlGenerator`
- [x] 1.3 Implement `getID(): string` — return `'app_versions'`
- [x] 1.4 Implement `getName(): string` — return `$this->l->t('App Versions')`
- [x] 1.5 Implement `getPriority(): int` — return `98`
- [x] 1.6 Implement `getIcon(): string` — return `$this->urlGenerator->imagePath('app_versions', 'app.svg')`

## 2. Create Admin Settings class

- [x] 2.1 Create directory `lib/Settings/` if it does not already exist
- [x] 2.2 Create `lib/Settings/Admin.php` implementing `OCP\Settings\ISettings` with namespace `OCA\AppVersions\Settings`
- [x] 2.3 Implement `getForm(): TemplateResponse` — return `new TemplateResponse(Application::APP_ID, 'index', [], '')` (empty renderAs string embeds form inside the settings page)
- [x] 2.4 Implement `getSection(): string` — return `'app_versions'`
- [x] 2.5 Implement `getPriority(): int` — return `10`

## 3. Update appinfo/info.xml

- [x] 3.1 Remove the entire `<navigations>` block (including its `<navigation>` child) from `appinfo/info.xml`
- [x] 3.2 Add a `<settings>` block declaring `<admin>OCA\AppVersions\Settings\Admin</admin>` and `<admin-section>OCA\AppVersions\Sections\AdminSection</admin-section>`

## 4. Remove PageController / FrontpageRoute

- [x] 4.1 Delete `lib/Controller/PageController.php` (removes the `#[FrontpageRoute(verb: 'GET', url: '/')]` entry point; the UI is now served only via the admin settings form)
- [x] 4.2 Grep the codebase for references to `PageController`, `page.index`, and the `GET /` route; confirm nothing else depends on them (e.g. `appinfo/info.xml` navigation `<route>` is removed in task 3.1)

## 5. Unit tests

- [x] 5.1 Create `tests/unit/Sections/AdminSectionTest.php` — test that `getID()` returns `'app_versions'`, `getPriority()` returns an int, `getName()` returns a non-empty string, `getIcon()` returns a non-empty string
- [x] 5.2 Create `tests/unit/Settings/AdminTest.php` — test that `getSection()` returns `'app_versions'`, `getPriority()` returns an int, `getForm()` returns a `TemplateResponse` instance

## 6. Quality checks

- [x] 6.1 Run `composer cs:check` and fix any coding-style violations in the new files
- [x] 6.2 Run `composer psalm` and resolve any type errors or warnings in the new files
- [x] 6.3 Run `composer test:unit` and confirm all unit tests pass (including the new Section and Settings tests)

## 7. Manual verification

- [ ] 7.1 Enable the app on a running Nextcloud instance and open **Settings → Administration** — confirm "App Versions" appears in the sidebar with icon
- [ ] 7.2 Click **App Versions** in the administration sidebar — confirm the Vue SPA renders correctly inside the settings panel (app list loads, version picker works)
- [ ] 7.3 Open the top-level Nextcloud navigation menu — confirm no "App Versions" entry appears
- [ ] 7.4 Log in as a non-admin user — confirm the Administration section does not show App Versions and direct API calls return 403 Forbidden
- [ ] 7.5 Navigate to `<base_url>/index.php/apps/app_versions/` directly — confirm the old front-page route no longer serves the UI (route removed)
