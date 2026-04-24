# ADR compliance audit — app-versions

Audit of the 23 org-wide ADRs (`hydra/openspec/architecture/adr-*.md`) against
what this app actually does. App Versions is an admin-only utility with no
domain data — many ADRs are legitimately N/A.

**Legend:** ✅ compliant · ⚠️ partial · ❌ gap · N/A out of scope

| ADR | Rule (short) | Status | Note |
|---|---|---|---|
| **001** Data layer | App config → `IAppConfig`, not OpenRegister | N/A | app has no persisted state of its own |
| 001 | Register JSON at `lib/Settings/{app}_register.json` | N/A | no domain schemas |
| **002** API | URL pattern `/api/{resource}`, standard verbs | ✅ | [`appinfo/routes.php`](../appinfo/routes.php) |
| 002 | No stack traces in error responses | ✅ | generic `message` strings; real errors to server log |
| 002 | Pagination | N/A | installed-app list is small; no pagination needed |
| 002 | POST `/install` RPC-style action | ⚠️ | operational action on existing resource, not pure-REST create — tolerated by ADR body but worth surfacing if ADR-002 is refined |
| **003** Backend | Controller → Service → Mapper layering | ✅ | controller delegates to `InstallerService` |
| 003 | Thin controllers (< 10 lines / method) | ✅ | `installVersion` refactored via `resolveRequestedVersion()` helper |
| 003 | DI via constructor + `private readonly` | ✅ | 4 services injected through `ApiController::__construct` |
| 003 | No `\OC::$server` or static locators | ✅ | also no `new \OC_App()` — refactored to use `IAppManager::getAppInfo()` |
| 003 | `@spec` on every class + public method | ✅ | 11 public methods tagged (2 via retrofit + 9 via annotate pass) |
| 003 | Specific routes before wildcard | ✅ | single page route, no wildcard |
| **004** Frontend | Vue 3 + `<script setup>` | ✅ | `src/main.ts` + `src/App.vue` + 7 child components |
| 004 | Never import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` | ✅ | `NcAppContent`, `NcContent`, `NcDialog` route through the wrapper |
| 004 | All user-visible strings via `t(appName, '…')` | ✅ | every string in `App.vue` + 7 children routed through `t()` / `n()` |
| 004 | CSS uses NC variables only | ✅ | verified across App.vue + children |
| 004 | Never `window.confirm()` / `alert()` — use `NcDialog` | ✅ | `DowngradeConfirmDialog` wraps `NcDialog` |
| **005** Security | Admin check on backend, not frontend | ✅ | `isAdmin()` in `ApiController` |
| 005 | `#[NoAdminRequired]` paired with per-body auth check | ✅ | every mutation endpoint re-checks |
| 005 | `#[PasswordConfirmationRequired]` on install mutations | ✅ | `installVersion` |
| 005 | No stack traces in API responses | ✅ | generic messages |
| **006** Metrics | `/api/metrics` + `/api/health` | N/A | stateless admin utility; no monitoring surface |
| **007** i18n | English primary + Dutch required | ✅ | `l10n/en.json` + `l10n/nl.json` with 58 keys + 5 plural entries |
| 007 | Frontend `t(appName, 'key')` + `n(...)` for plurals | ✅ | `versionRangeText()` uses `translatePlural` |
| 007 | Backend `$this->l10n->t('key')` | N/A | no user-visible backend strings; only OCS JSON |
| **008** Testing | PHPUnit coverage per service / controller | ⚠️ | `ApiControllerTest` (12 tests) + `InstallerServiceTest` (3 tests) land; `SelectedReleaseInstallerService` still uncovered due to NC-internal deps (`OC\Archive\TAR`, `OC\DB\MigrationService`) — integration-test territory |
| 008 | Newman/Postman collection | ✅ | `tests/integration/app-versions.postman_collection.json` covers the 5 OCS read endpoints |
| **009** Docs | User-facing features documented | ✅ | [`docs/`](.) covers index / installation / usage / api / architecture |
| **010** NL Design | CSS custom properties, no hardcoded colors | ✅ | verified |
| 010 | `scoped` / `module` on every `<style>` block | ✅ | every component uses `<style module>` |
| 010 | WCAG AA | ⚠️ | form controls are labelled; deeper a11y audit not done |
| **011** Schema standards | schema.org vocabulary, explicit types | N/A | no domain schemas |
| **012** Dedup | Reuse analysis in OpenSpec changes | N/A | no domain code to dedup against OpenRegister |
| **013** Container pool | Hydra infra concern | N/A | not an app concern |
| **014** Licensing | EUPL-1.2 SPDX header on every source file | ✅ | `@license EUPL-1.2` in every PHP docblock; `SPDX-License-Identifier` on Vue/TS; `REUSE.toml` covers the rest |
| 014 | `info.xml` licence element | ✅ | `<licence>eupl</licence>` — matches code headers and composer/package manifests |
| **015** Common patterns | Static generic error messages | ✅ | `message: 'Forbidden'` etc.; real errors logged |
| 015 | No raw `fetch()` — use `@nextcloud/axios` | ✅ | all 5 HTTP call sites migrated to `axios` with `validateStatus: () => true` to preserve OCS-failure parsing |
| 015 | EUPL headers | ✅ | see ADR-014 |
| **016** Routes | `appinfo/routes.php` is the only registration path | ✅ | `#[ApiRoute]` / `#[FrontpageRoute]` removed; all 6 routes in `routes.php` |
| **017** Component composition | Do not wrap self-contained components in `NcAppContent` | ⚠️ | `App.vue` still wraps in `NcAppContent` — adopting `CnDetailPage` / `CnIndexPage` would be a visual-regression refactor; deferred |
| **018** Widget header actions | `header-actions` slot on cards | N/A | no dashboard widget |
| **019** Integration registry | Sidebar tabs / linked items | N/A | no integration registry usage |
| **020** Gate scope | Hydra gate scope is PR diff | N/A | reviewer guidance, not app code |
| **021** Bounded fix scope | Reviewer bounded-fix by change shape | N/A | reviewer guidance, not app code |
| **022** Apps consume OR abstractions | RBAC / audit / archival via OR | N/A | app has no domain data |
| **023** Action authorization | Admin-configured action/group mappings | N/A | admin-only app; no user-delegated actions |

## Summary

- **Compliant:** 29 rules
- **Partial:** 3 rules (ADR-002 RPC-style install endpoint, ADR-008 `SelectedReleaseInstallerService` unit coverage, ADR-010 deep a11y audit, ADR-017 `NcAppContent` wrapping)
- **Gaps:** 0
- **N/A (infrastructure / domain-less):** 14 rules

## Follow-ups (tracked separately)

1. **`SelectedReleaseInstallerService` unit coverage.** The service touches `OC\Archive\TAR`, `OC\DB\MigrationService`, `OC\AppFramework\Bootstrap\Coordinator` — too many NC internals to mock without a Nextcloud integration-test harness. Integration coverage via Newman is the realistic path.
2. **Deep WCAG AA audit.** Form controls are labelled and focusable, but a structured a11y pass (screen-reader traversal, colour-contrast ratios, keyboard trap check) has not been run.
3. **`NcAppContent` wrapper review (ADR-017).** Switching to `CnDetailPage` / `CnIndexPage` from `@conduction/nextcloud-vue` would align with the composition rule but risks visual regressions — warrants a dedicated UI-refactor PR.
4. **ADR-002 RPC-style POST clarification.** Document whether operational POSTs on existing resources (e.g., `POST /apps/{id}/versions/{v}/install`) fall within ADR-002's scope, or flag in the ADR next time it's refined.
