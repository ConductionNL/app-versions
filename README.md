# App Versions

Install any earlier or newer version of already-installed Nextcloud apps from the App Store, GitHub Releases, or any Gitea / Forgejo instance (including Codeberg). Bind each installed app to the source you trust; roll back to a specific release when a store update breaks something; ship dev-branch or private-repo builds onto a staging server without publishing them publicly.

> ⚠️ **Active development.** Although App Versions may carry a stable release status in the Nextcloud App Store, please do not use it in production environments before 17 June 2026. See [conduction.nl/apps](https://conduction.nl/apps) for release planning.

## What it does

By default, Nextcloud installs and updates apps only from the Nextcloud App Store, and only shows you the "latest" version. App Versions extends the App Store layer with three concrete capabilities:

- **Install a specific version.** Pick any published version from a dropdown — including older stable, beta, or nightly — and install it in place of the currently installed release. Useful for rolling back after a broken update, or pinning to a version whose API you rely on.
- **Bind an app to a non-App-Store source.** Each installed app can be bound to a GitHub release feed (`owner/repo`) or a Gitea / Forgejo release feed (`host/owner/repo`, incl. Codeberg) in addition to the App Store. Versions from the bound source appear alongside App Store versions in the dropdown.
- **Discover and install apps from GitHub or Codeberg.** Search across GitHub / Codeberg for Nextcloud apps that aren't in the App Store, view their release history, and install directly. PATs are supported for private repositories.

Trust is enforced by an allowlist (`trusted_sources` app config) — an admin must explicitly permit which owner/repo patterns App Versions may fetch from, so no arbitrary internet URL can be pointed at your instance.

## Quickstart

**1. Install the app** from the Nextcloud App Store or via `occ`:

```
sudo -u www-data php occ app:install app_versions
```

**2. Open Files → App Versions** in the Nextcloud sidebar. Pick an installed app from the "Pick an installed App" list, then choose a version from the dropdown. Click Install.

**3. Bind an alternative source** (optional). See [docs/tutorials/admin/02-bind-alternate-source](docs/tutorials/admin/02-bind-alternate-source.md) for how to install versions from GitHub or a Gitea/Forgejo instance (Codeberg, self-hosted Gitea, Forgejo).

## Supported sources

| Kind | Binding shape | Default allowlist |
|---|---|---|
| `appstore` | (no config) — the Nextcloud App Store | always trusted |
| `gitea-release` **(recommended)** | `gitea:<host>/<owner>/<repo>` | `codeberg.org/Conduction/*` |
| `github-release` | `github:<owner>/<repo>` | `ConductionNL/*` |

Custom allowlist patterns can be set with:

```
sudo -u www-data php occ config:app:set app_versions trusted_sources '["myorg/*","gitea.example.com/team/*"]'
```

## API

App Versions exposes 12 OCS endpoints under `/ocs/v2.php/apps/app_versions/api/` covering source binding, version listing, installation, PAT management, and discovery. See [`openapi.json`](openapi.json) for the full spec.

Common calls:

```
GET  /api/sources                            # available source kinds + trust patterns
GET  /api/source/{appId}/binding             # current binding for an app
POST /api/source/{appId}/bind                # bind an app to a source
GET  /api/app/{appId}/versions               # list available versions per binding
POST /api/app/{appId}/versions/{version}/install
```

Admin-only endpoints require `#[PasswordConfirmationRequired]`; either use basic auth (`curl -u admin:pw`) or authenticate a session that has recently confirmed its password.

## Documentation

- [Admin tutorials](docs/tutorials/admin/) — first-run setup, binding an alternate source, managing trust patterns.
- [User tutorials](docs/tutorials/user/) — installing an earlier version, rolling back an update.
- [CHANGELOG.md](CHANGELOG.md) — release-by-release change log.
- [openapi.json](openapi.json) — full API reference.

## Contributing

Issues and pull requests welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Report bugs at the Codeberg [issue tracker](https://codeberg.org/Conduction/app-versions/issues).

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).

## Resources

- Nextcloud developer documentation: <https://docs.nextcloud.com/server/latest/developer_manual>
- App Store: <https://apps.nextcloud.com/apps/app_versions>
- Source repository: <https://codeberg.org/Conduction/app-versions>
- Conduction app portfolio: <https://conduction.nl/apps>
