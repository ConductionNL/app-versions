# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **In-app source-binding UI** — the App Versions picker now shows a **Version source** card next to the selected app with quick-switch buttons (`App Store` · `Codeberg` · `GitHub` · `Advanced…`). Codeberg and GitHub quick-switches pre-fill Conduction's canonical defaults (`codeberg.org/Conduction/{appId}` and `ConductionNL/{appId}`); the `Advanced…` NcDialog gives full override of `kind`, `host`, `owner`, `repo`, and `assetPattern`. The dialog renders its source-kind options from `/api/sources` so labels + ordering stay in sync with `SourceRegistry::listAvailable()` (the "(recommended)" suffix on Gitea therefore appears automatically). On successful bind the versions list re-fetches in place — no page reload.
- **Admin tutorial for binding an alternate source** ([docs/tutorials/admin/02-bind-alternate-source.md](docs/tutorials/admin/02-bind-alternate-source.md)) — step-by-step walkthrough covering the three source kinds (`appstore`, `gitea-release`, `github-release`), trust-list configuration, the `POST /api/source/{appId}/bind` call in bash and PowerShell forms, verification in the UI, and a common-issues table. Updated to document both paths — UI (quick-switch buttons + Advanced dialog) and OCS API — side by side in step 3.
- **Product-oriented README.md** replacing the template scaffold — describes what App Versions does, the three source kinds side by side, quickstart, API summary, and pointers to the OpenAPI spec and admin tutorials.
- **Full OpenAPI spec** — `composer openapi` regenerated `openapi.json` from the current `#[ApiRoute]` attributes; the spec now covers all 12 endpoints (previously only a single placeholder route).

### Changed

- **Codeberg/Gitea is now the recommended alternate source; GitHub is the fallback.** `SourceRegistry::listAvailable()` returns the three kinds in the order App Store → Gitea → GitHub (the picker UI order), with the Gitea entry labelled "Codeberg / Gitea / Forgejo Releases (recommended)" and the GitHub entry simplified to "GitHub Releases". `TrustedSourceList::DEFAULT_PATTERNS` mirrors this — `codeberg.org/Conduction/*` first, `ConductionNL/*` second — reflecting that Conduction apps' source of truth moved to Codeberg after the GitHub org migration. Both changes are ordering-only; no source kind was removed and no trust pattern was dropped, so the change is fully backwards-compatible for existing bindings and custom allowlists.

## [1.1.0] - 2026-07-09

### Added

- **Gitea/Forgejo release source** — install app versions directly from any Gitea-family release feed (Codeberg, Forgejo, self-hosted Gitea). Introduces:
  - `SourceBinding::KIND_GITEA_RELEASE` (`gitea:host/owner/repo` identifier shape) with a new `SourceBinding::gitea($host, $owner, $repo)` factory.
  - `GiteaReleaseSource` driver talking to `https://{host}/api/v1/repos/{owner}/{repo}/releases` — public read only, no PAT support in this cut.
  - `POST /api/source/{appId}/bind` accepts `kind=gitea-release` with `host`, `owner`, `repo`, and optional `assetPattern`.
  - `TrustedSourceList` extended to recognise `gitea:host/owner/repo` identifiers; the default allowlist now permits `codeberg.org/Conduction/*` alongside the existing `ConductionNL/*` (GitHub).
- `SourceRegistry::listAvailable()` now surfaces the Gitea source alongside App Store and GitHub.

### Notes

- Unblocks canary-testing of app dev-releases that are intentionally NOT published to the Nextcloud App Store (e.g. per-push builds of `opencatalogi/development`).
- PAT support for private Gitea repositories is intentionally deferred — the `PatResolver` currently keys on `owner/repo`, which collides between GitHub and Gitea. Adding host-aware keying is tracked separately.

## [1.0.1]

### Added

- First release
