# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Admin tutorial for binding an alternate source** ([docs/tutorials/admin/02-bind-alternate-source.md](docs/tutorials/admin/02-bind-alternate-source.md)) — step-by-step walkthrough covering the three source kinds (`appstore`, `github-release`, `gitea-release`), trust-list configuration, the `POST /api/source/{appId}/bind` call in bash and PowerShell forms, verification in the UI, and a common-issues table.
- **Product-oriented README.md** replacing the template scaffold — describes what App Versions does, the three source kinds side by side, quickstart, API summary, and pointers to the OpenAPI spec and admin tutorials.
- **Full OpenAPI spec** — `composer openapi` regenerated `openapi.json` from the current `#[ApiRoute]` attributes; the spec now covers all 12 endpoints (previously only a single placeholder route).

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
