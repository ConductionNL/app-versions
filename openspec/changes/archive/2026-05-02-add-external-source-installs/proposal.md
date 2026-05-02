# Proposal: add-external-source-installs

## Summary
Add a parallel install path that lets admins install (and roll back) Nextcloud apps from sources outside the Nextcloud App Store — primarily GitHub releases — while preserving the App Store install path with its full code-signing chain. Sources are restricted to a configurable trusted-source allowlist (default `ConductionNL/*`), and once an app is installed from an external source, that source is sticky for future version queries.

## Motivation
The current installer ([`SelectedReleaseInstallerService`](../../../lib/Service/SelectedReleaseInstallerService.php)) requires every artifact to carry a Nextcloud-issued code-signing certificate whose CN matches the appId. This is correct for App Store apps but locks out:

- ConductionNL apps (and other vendors) that release on GitHub but do not currently sign artifacts with a Nextcloud-issued cert
- Pre-release / development builds an admin wants to test before they hit the App Store
- Private builds for organizations running internal forks
- Rollback of an app that was originally installed from GitHub — the App Store may not even know that version exists

This proposal introduces an explicit **external-source** install path that makes the trust trade-off visible (no Nextcloud cert chain) but compensates with: a trusted-source allowlist, archive integrity checks (appId match, version match, optional SHA-256), and clear UI labelling.

## Scope
- A `SourceInterface` abstraction so the version picker is no longer hard-coded to the App Store
- `AppStoreSource` (refactor of the current `InstallerService` fetch logic) and `GithubReleaseSource` (new) implementations
- `TrustedSourceList` enforcing a configurable allowlist of `owner/repo` globs
- `ExternalReleaseInstallerService` — parallel to `SelectedReleaseInstallerService`, running the same backup-and-replace flow but with integrity checks instead of certificate verification
- Sticky source binding per installed app, persisted via `IConfig::setAppValue('app_versions', "source.{appId}", json)`
- New API endpoints: `GET /api/sources`, `POST /api/source/{appId}/bind`, the existing `appVersions` and `installVersion` endpoints accept a `source` param
- Update channel and trusted-source admin settings (config-only for now; admin UI lives in proposal 3)

## Out of scope
- PAT upload, encrypted storage, scope validation — this proposal covers public GitHub releases only. PAT support is proposal 2 (`add-github-pat-management`).
- Discovery / search UI — covered by proposal 3 (`add-app-discovery-search`).
- Public Software Catalogus integration — tracked in [issue #24](https://github.com/ConductionNL/app-versions/issues/24).
- Auto-update from external sources (cron job that polls bound sources for new releases). Future work.
