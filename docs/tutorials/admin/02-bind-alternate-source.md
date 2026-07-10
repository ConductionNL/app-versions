---
sidebar_position: 2
title: Bind an app to an alternate source
description: Install an app from GitHub Releases or a Gitea/Forgejo instance (e.g. Codeberg) instead of the Nextcloud App Store — via the in-app UI or via the OCS API
---

# Bind an app to an alternate source

Step-by-step guide for installing an app version from a **GitHub release feed** or a **Gitea / Forgejo release feed** (including Codeberg) instead of — or alongside — the Nextcloud App Store. Two equivalent paths: click through the App Versions UI, or POST to the OCS API for scripted / CI use.

## Goal

By the end of this tutorial you will have:

1. Understood the difference between the three source kinds App Versions supports (`appstore`, `github-release`, `gitea-release`).
2. Reviewed and, if needed, extended the `trusted_sources` allowlist so App Versions is permitted to fetch from your chosen source.
3. Bound one installed app to a `github-release` OR `gitea-release` source — either via the App Versions UI's **Version source** card or via the OCS `POST /api/source/{appId}/bind` endpoint.
4. Verified in the App Versions UI that the versions dropdown now shows releases from the new source.
5. Installed a specific version from the new source.

The canonical worked example throughout is: **bind `opencatalogi` to `codeberg.org/Conduction/opencatalogi` and install the latest dev-release**.

## Which path — UI or API?

App Versions ships a first-class UI for source binding as of 1.2.0. Both paths hit the same endpoint (`POST /api/source/{appId}/bind`) with the same payload shape — pick whichever fits the situation:

- **UI** — recommended for one-off admin actions. Open `/apps/app_versions/`, pick an installed app, use the quick-switch buttons in the **Version source** card (`App Store` · `Codeberg` · `GitHub`), or click **Advanced…** to override host / owner / repo / asset pattern. The Codeberg and GitHub quick-switch buttons pre-fill Conduction's defaults (`codeberg.org/Conduction/{appId}` and `ConductionNL/{appId}` respectively) so a single click is enough for Conduction apps.
- **API** — recommended for automation, CI, config-management, or any environment where clicking a button doesn't fit. The step-by-step below walks the API flow.

Steps 1 (trust list) and 2 (find the identifier) apply to both paths. Step 3 shows both the UI and the API side by side.

## When to use this

Use an alternate source when the version you need is not available on the Nextcloud App Store. Common cases:

- **Per-push dev builds** — a Conduction app publishes signed `.tar.gz` releases to Codeberg for every merge into `development` without pushing them to the App Store. Staging/canary environments consume those to test unreleased code.
- **Private repositories** — apps developed inside a company can publish releases to a private GitHub / Gitea repo. App Versions supports PATs (Personal Access Tokens) for those; see the [PAT reference](https://codeberg.org/Conduction/app-versions/src/branch/main/lib/Service/Pat).
- **Forks or beta channels** — you maintain a fork with a hotfix your organisation needs before it lands upstream.

The App Store binding (the default) is always available in parallel — App Versions merges versions from every configured source into the same dropdown.

## Prerequisites

- **App Versions ≥ 1.1.0** installed and enabled on your Nextcloud (`gitea-release` support was added in 1.1.0). Check with:
  ```
  sudo -u www-data php occ app:list | grep app_versions
  ```
- **Admin rights** on the Nextcloud. All bind endpoints require admin + `#[PasswordConfirmationRequired]`.
- **Network reachability** — the Nextcloud host must be able to reach `api.github.com` (for GitHub) or the Gitea/Forgejo host you're binding to.
- **The target app must already be installed** — App Versions binds sources per installed app. Install the app once from any channel first, then rebind.

## The three source kinds

| Kind | Source-ID shape | Example | Backing driver |
|---|---|---|---|
| `appstore` | `appstore` | (implicit) | `AppStoreSource` — reads the Nextcloud App Store info JSON |
| `gitea-release` **(recommended)** | `gitea:<host>/<owner>/<repo>` | `gitea:codeberg.org/Conduction/opencatalogi` | `GiteaReleaseSource` — reads `https://<host>/api/v1/repos/<owner>/<repo>/releases` |
| `github-release` | `github:<owner>/<repo>` | `github:ConductionNL/openregister` | `GithubReleaseSource` — reads `https://api.github.com/repos/<owner>/<repo>/releases` |

For Conduction apps, `gitea-release` pointed at `codeberg.org/Conduction/<app>` is the recommended default — that's the source of truth after the ConductionNL GitHub → Codeberg migration. `github-release` is retained for apps that still publish releases to GitHub.

Every source binding stores an optional `assetPattern` (default `*.tar.gz`) that decides which release asset to download.

## Steps

### 1. Verify or extend the trust allowlist

Before it fetches anything, App Versions runs the source identifier against the `trusted_sources` allowlist. If the owner/repo (or host/owner/repo, for Gitea) does not match any pattern, the bind is rejected with HTTP 403 — no network call is made.

Inspect the current allowlist:

```
sudo -u www-data php occ config:app:get app_versions trusted_sources
```

Empty response = the built-in defaults apply, which are:

```
codeberg.org/Conduction/*
ConductionNL/*
```

If your target does not match, extend the list. **Setting the value replaces the entire list** — always include the built-ins you want to keep:

```
sudo -u www-data php occ config:app:set app_versions trusted_sources \
  '["codeberg.org/Conduction/*","ConductionNL/*","myorg/*","gitea.example.com/team/*"]'
```

Glob wildcards are shell-style (`*` matches any characters except `/`). One pattern per line — no whitespace in the JSON.

### 2. Find the exact source identifier

For **GitHub**: browse to the repo on `github.com` and read the owner/repo from the URL — e.g. `https://github.com/ConductionNL/openregister` → `github:ConductionNL/openregister`.

For **Gitea / Forgejo / Codeberg**: same principle, but include the host — e.g. `https://codeberg.org/Conduction/opencatalogi` → `gitea:codeberg.org/Conduction/opencatalogi`.

Optional: verify the API responds with releases before you bind. For Codeberg:

```
curl -s "https://codeberg.org/api/v1/repos/Conduction/opencatalogi/releases?limit=5" | jq '.[].tag_name'
```

You should see a list of tag names (`v1.0.4`, `v1.0.5-dev.20260708205504`, …). If the response is `[]` or an error, fix that before binding — App Versions will show an empty dropdown otherwise.

### 3. Bind the app to the source

The bind endpoint is `POST /ocs/v2.php/apps/app_versions/api/source/{appId}/bind`. Payload varies by kind — full field list in [`openapi.json`](../../openapi.json), summary below:

| Kind | Required fields | Optional |
|---|---|---|
| `appstore` | `kind=appstore` | — |
| `gitea-release` | `kind=gitea-release, host, owner, repo` | `assetPattern` |
| `github-release` | `kind=github-release, owner, repo` | `assetPattern` |

#### Path A — via the UI (recommended for one-offs)

1. Open `https://YOUR-NEXTCLOUD/apps/app_versions/` in the browser.
2. Pick the target app from the "Pick an installed App" list (e.g. `opencatalogi`).
3. In the info panel on the right you'll see a **Version source** card with the current binding label (e.g. *Nextcloud App Store*) and four buttons: `App Store` · `Codeberg` · `GitHub` · `Advanced…`.
4. Click **Codeberg** for the recommended path — this posts `{kind:"gitea-release", host:"codeberg.org", owner:"Conduction", repo:"{appId}", assetPattern:"*.tar.gz"}` in the background and re-fetches the versions list from the new source. The active binding gets a blue highlight.
5. If your target repo is not `codeberg.org/Conduction/{appId}` (self-hosted Gitea, different owner, private repo) click **Advanced…** instead — the dialog gives you full override of `kind`, `host`, `owner`, `repo`, and `assetPattern`, pre-populated from the current binding.
6. On failure (403 from an untrusted source, 400 from a bad payload, network error) the response message surfaces inline in the dialog / card — the OCS meta status is not swallowed.

Skip to step 4 to verify.

#### Path B — via the OCS API (recommended for automation / CI)

**Worked example — Codeberg via bash:**

```bash
curl -u 'ADMIN_USER:ADMIN_PASSWORD' \
  -H "OCS-APIRequest: true" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -X POST \
  "https://YOUR-NEXTCLOUD/ocs/v2.php/apps/app_versions/api/source/opencatalogi/bind?format=json" \
  -d '{
    "kind": "gitea-release",
    "host": "codeberg.org",
    "owner": "Conduction",
    "repo": "opencatalogi"
  }'
```

**Same example — Windows PowerShell:**

```powershell
curl.exe -u "ADMIN_USER:ADMIN_PASSWORD" -H "OCS-APIRequest: true" -H "Content-Type: application/json" -H "Accept: application/json" -X POST "https://YOUR-NEXTCLOUD/ocs/v2.php/apps/app_versions/api/source/opencatalogi/bind?format=json" -d '{\"kind\":\"gitea-release\",\"host\":\"codeberg.org\",\"owner\":\"Conduction\",\"repo\":\"opencatalogi\"}'
```

Two notes:

- **`curl.exe` on Windows.** PowerShell's `curl` is an alias for `Invoke-WebRequest` with different flags — always call `curl.exe` explicitly, or use `Invoke-RestMethod` instead.
- **`?format=json`** — OCS defaults to XML. Add this to get a JSON body back.

Expected 200 response (JSON):

```json
{
  "ocs": {
    "meta": {"status":"ok","statuscode":200,"message":"OK"},
    "data": {
      "appId": "opencatalogi",
      "sourceId": "gitea:codeberg.org/Conduction/opencatalogi",
      "binding": {
        "kind": "gitea-release",
        "host": "codeberg.org",
        "owner": "Conduction",
        "repo": "opencatalogi",
        "assetPattern": "*.tar.gz",
        "boundAt": "2026-07-10T08:25:50+00:00"
      }
    }
  }
}
```

### 4. Verify in the UI

If you took **Path A (UI)**: the picker already updated in place — the `Codeberg` button carries the blue "active" highlight and the version list re-fetched immediately. Nothing more to do.

If you took **Path B (API)**: open `https://YOUR-NEXTCLOUD/apps/app_versions/` in the browser (hard refresh — Ctrl+Shift+R — to bypass caching). Pick OpenCatalogi from the "Pick an installed App" list. Two things should now be different:

- The **Version source** card reads `Codeberg / Gitea (codeberg.org/Conduction/opencatalogi)` (previously *Nextcloud App Store*), with the `Codeberg` quick-switch button highlighted.
- The **versions dropdown** shows tags from Codeberg — including any `-beta.*` and `-dev.*` releases that never made it to the App Store.

### 5. Install the version you want

Select a version from the dropdown and click Install. App Versions:

1. Fetches the matching asset from the release (matching `assetPattern`, default `*.tar.gz`).
2. Verifies the tarball's app-id and version match `appinfo/info.xml`.
3. Optionally verifies a sibling `.sha256` if one is published in the same release.
4. Extracts into the Nextcloud apps directory and runs the standard NC upgrade + repair steps.
5. Records the new installation with the bound source-id so subsequent version checks use the same driver.

## Verification

Confirm the install:

```
sudo -u www-data php occ app:list | grep opencatalogi
```

Expected: the version you selected. Reload the app in the browser and confirm the new build renders.

## Common issues

| Symptom | Fix |
|---|---|
| HTTP 403 on bind with body mentioning "allowlist patterns" | The identifier is not in `trusted_sources`. Extend the allowlist (Step 1) and retry. |
| HTTP 404 on bind (Nextcloud login page returned) | URL is wrong. OCS routes live under `/ocs/v2.php/apps/app_versions/api/...`, not `/apps/app_versions/api/...`. |
| HTTP 401 or CSRF error | Password confirmation isn't fresh. Use basic auth (`curl -u user:pw`) or reauthenticate the session. |
| Dropdown empty after bind | (a) The upstream API returned zero releases — verify with the `curl` call in Step 2. (b) All releases are drafts — App Versions filters drafts. |
| "Multiple matching assets" error on install | The release publishes several `*.tar.gz` — set a more specific `assetPattern` in the binding (e.g. `opencatalogi-*.tar.gz`) so exactly one matches. |
| Install fails with "class not found" style errors after upgrade | Cross-app dependency — the source you installed depends on newer classes in another app. Upgrade that dependency (via App Versions itself) to a matching version. |
| GitHub rate-limit (HTTP 403 during listVersions) | Unauthenticated GitHub API is limited to 60/hr. Configure a PAT via `POST /api/pats` and it will be resolved automatically for matching `owner/repo`. |

## Reference

- Full endpoint reference: [`openapi.json`](../../openapi.json)
- Source drivers: [`lib/Service/Source/`](https://codeberg.org/Conduction/app-versions/src/branch/main/lib/Service/Source)
- Trust list configuration: [`lib/Service/Source/TrustedSourceList.php`](https://codeberg.org/Conduction/app-versions/src/branch/main/lib/Service/Source/TrustedSourceList.php)
- Related tutorial: [Manage settings](./01-admin-settings.md)
- Introduction of gitea-release: [PR #21](https://codeberg.org/Conduction/app-versions/pulls/21) — original motivation was staging OpenCatalogi dev builds onto a canary environment without shipping them to the public App Store.
