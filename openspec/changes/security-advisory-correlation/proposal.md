---
kind: code
---

# Proposal: security-advisory-correlation

## Why

App Versions exists to give an administrator control over *which* version of each app is
installed — view all versions, pin/select one, roll back a broken update, and bind an app
to an authoritative source (App Store or external such as GitHub/Codeberg releases). The one
question it cannot yet answer is the most important one for a controlled-upgrade tool: **"is
the version I'm pinned to known to be unsafe?"** Today an admin can deliberately pin an app
to an older version (a first-class feature) with no signal that the pinned version has a
published security advisory — the tool that exists to hold a version steady has no way to
tell you when holding steady is dangerous. Dependency/version managers in every other
ecosystem (Dependabot, Renovate, Snyk) treat advisory correlation as core, not optional.

The data is available: the Nextcloud App Store publishes per-app-release security
information, and external release sources (GitHub/Codeberg) expose security advisories. App
Versions already knows each app's bound source and current/available versions, so
correlating the installed/pinned version against known advisories is a natural extension of
`version-management` — not a new subsystem.

## What Changes

- Add a `lib/Service/AdvisoryService.php` (SPDX docblock) that, for each installed app,
  resolves security advisories affecting the currently-installed/pinned version from the
  app's bound source: the Nextcloud App Store security feed for store-sourced apps, and the
  source adapter's advisory endpoint (e.g. GitHub/Codeberg security advisories) for
  external-sourced apps. External calls go through the app's existing source-adapter /
  credential path — no new bespoke HTTP client, no secret held outside the existing PAT
  management. The service is read-only and cache-backed with a scheduled refresh.
- Surface an advisory state per app in the version list: `none` | `advisory-available`
  (a newer safe version exists) | `pinned-to-vulnerable` (the admin has pinned to a version
  with an open advisory) — with the advisory id/severity/summary and a "safe version"
  recommendation. A **pinned-to-vulnerable** app MUST be visually prominent, because it is
  the failure mode this tool can uniquely create (deliberate pinning) and must therefore own.
- Add an optional admin notification (via the NC notification API) when a newly-published
  advisory affects an installed or pinned version.
- No auto-update: App Versions never silently changes a pinned version — it surfaces the
  advisory and the recommended safe version, and the admin decides (consistent with the
  tool's "administrator is in control" stance).

## Impact

- Affected: new `AdvisoryService`, an advisory field on the version-list read path, a UI
  badge/detail in the app list, an optional notification, a scheduled refresh job, and their
  tests. Reuses the existing source-adapter + PAT/credential path for external calls.
- Out of scope: CVE database ingestion of arbitrary packages (this correlates *app-release*
  advisories from the bound source, not a general SCA scanner), and any automatic
  version change.
