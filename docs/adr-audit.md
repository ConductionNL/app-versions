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
| **003** Backend | Controller → Service → Mapper layering | ✅ | controller delegates to `InstallerService` |
| 003 | Thin controllers (< 10 lines / method) | ⚠️ | `installVersion` is larger due to request-body reconciliation (targetVersion/version/route alignment) |
| 003 | DI via constructor + `private readonly` | ✅ | post-PR: 4 services injected through `ApiController::__construct` |
| 003 | No `\OC::$server` or static locators | ✅ | post-PR: grep clean |
| 003 | `@spec` on every class + public method | ❌ | deferred — no openspec change drove this cleanup PR |
| 003 | Specific routes before wildcard | ✅ | single page route, no wildcard |
| **004** Frontend | Vue 3 + `<script setup>` | ✅ | `src/main.ts` + `src/App.vue` |
| 004 | Never import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` | ✅ | post-PR |
| 004 | All user-visible strings via `t(appName, '…')` | ⚠️ | post-PR: infrastructure in place + primary strings extracted; remaining strings in `App.vue` (~60) still to wire |
| 004 | CSS uses NC variables only | ✅ | verified across `App.vue` |
| 004 | Never `window.confirm()` / `alert()` — use `NcDialog` | ✅ | uses `NcDialog` for downgrade warning |
| **005** Security | Admin check on backend, not frontend | ✅ | `isAdmin()` in `ApiController` |
| 005 | `#[NoAdminRequired]` paired with per-body auth check | ✅ | every mutation endpoint re-checks |
| 005 | `#[PasswordConfirmationRequired]` on install mutations | ✅ | `installVersion` |
| 005 | No stack traces in API responses | ✅ | generic messages |
| **006** Metrics | `/api/metrics` + `/api/health` | N/A | stateless admin utility; no monitoring surface |
| **007** i18n | English primary + Dutch required | ⚠️ | post-PR: `l10n/en.json` + `l10n/nl.json` created; primary UI strings covered; tail of `App.vue` strings pending follow-up |
| 007 | Frontend `t(appName, 'key')` | ⚠️ | same — partial extraction |
| 007 | Backend `$this->l10n->t('key')` | N/A | no user-visible backend strings; only OCS JSON |
| **008** Testing | PHPUnit coverage per service / controller | ⚠️ | single `ApiTest::testAdminCheckReturnsFalseWhenNotSignedIn` — expanding coverage deferred |
| 008 | Newman/Postman collection | ❌ | none — deferred |
| **009** Docs | User-facing features documented | ✅ | post-PR: [`docs/`](.) now covers index / installation / usage / api / architecture |
| **010** NL Design | CSS custom properties, no hardcoded colors | ✅ | verified |
| 010 | `scoped` on every `<style>` block | ✅ | single `<style module>` block in `App.vue` |
| 010 | WCAG AA | ⚠️ | form controls are labelled; `<select>` elements use `<label>` wrappers; deeper a11y audit not done |
| **011** Schema standards | schema.org vocabulary, explicit types | N/A | no domain schemas |
| **012** Dedup | Reuse analysis in OpenSpec changes | N/A | no domain code to dedup against OpenRegister |
| **013** Container pool | Hydra infra concern | N/A | not an app concern |
| **014** Licensing | EUPL-1.2 SPDX header on every source file | ✅ | post-PR: `@license EUPL-1.2` in every PHP docblock; `SPDX-License-Identifier` on Vue/TS; `REUSE.toml` covers the rest |
| 014 | `info.xml` licence element | ⚠️ | currently `AGPL-3.0-or-later`; template uses `agpl` but Conduction prescribes `EUPL-1.2`. Flagged for Barry — Nextcloud app-store may require AGPL for publication |
| **015** Common patterns | Static generic error messages | ✅ | `message: 'Forbidden'` etc.; real errors logged |
| 015 | No raw `fetch()` — use `@nextcloud/axios` | ⚠️ | deferred — `App.vue` uses native `fetch()` in ~20 places; migration postponed to a focused PR |
| 015 | EUPL headers | ✅ | see ADR-014 |
| **016** Routes | `appinfo/routes.php` is the only registration path | ✅ | post-PR: `#[ApiRoute]` / `#[FrontpageRoute]` removed; all 6 routes in `routes.php` |
| **017** Component composition | Do not wrap self-contained components in `NcAppContent` | ⚠️ | `App.vue` still wraps in `NcAppContent` — refactor to `CnDetailPage`/`CnIndexPage` is a follow-up (UI decomposition is out of scope here) |
| **018** Widget header actions | `header-actions` slot on cards | N/A | no dashboard widget |
| **019** Integration registry | Sidebar tabs / linked items | N/A | no integration registry usage |
| **020** Gate scope | Hydra gate scope is PR diff | N/A | reviewer guidance, not app code |
| **021** Bounded fix scope | Reviewer bounded-fix by change shape | N/A | reviewer guidance, not app code |
| **022** Apps consume OR abstractions | RBAC / audit / archival via OR | N/A | app has no domain data |
| **023** Action authorization | Admin-configured action/group mappings | N/A | admin-only app; no user-delegated actions |

## Summary

- **Compliant:** 18 rules
- **Partial:** 7 rules (i18n tail, thin-controller in `installVersion`, a11y
  audit, PHPUnit coverage, `fetch()` → axios migration, `NcAppContent` wrapper,
  info.xml licence)
- **Gaps:** 2 rules (`@spec` tags — no OpenSpec change drives this PR;
  Postman collection)
- **N/A (infrastructure / domain-less):** 14 rules

## Follow-ups (tracked separately)

1. Decompose `App.vue` (2060 lines) into components — refactor candidate.
2. Finish l10n extraction for the remaining ~60 strings in `App.vue`.
3. Swap native `fetch()` calls to `@nextcloud/axios`.
4. Decide whether `<licence>agpl</licence>` stays (app-store requirement) or
   moves to `EUPL-1.2` (Conduction standard).
5. Newman/Postman collection covering the 5 OCS endpoints.
6. Backfill PHPUnit coverage for `InstallerService` and
   `SelectedReleaseInstallerService`.
7. `@spec` tags once an OpenSpec change exists for the app.
