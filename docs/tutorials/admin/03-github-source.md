---
sidebar_position: 3
title: Connect a GitHub release source
description: Bind an installed app to GitHub releases so App Versions can install from there alongside the App Store.
---

# Connect a GitHub release source

App Store releases are the default, but some apps ship faster on GitHub
— or live there exclusively. This guide binds an installed app to a
GitHub repository so its release list lands in the version picker.

> **This guide is being written as App Versions approaches feature-completeness.**
> Bodies and screenshots fill in once the admin UI lands. Follow the
> [Codeberg repository](https://codeberg.org/Conduction/app-versions) for
> milestones.

## Goal

By the end of this guide you will have:

1. Bound an installed app to a source of the form
   `github:{owner}/{repo}`.
2. (Optional) Uploaded a personal access token so the source can reach
   a private repo.
3. Confirmed that the app's version picker now lists GitHub releases
   alongside (or instead of) App Store releases.

## Prerequisites

- App Versions is installed, enabled, and reachable as an admin (see
  [Open App Versions for the first time](./01-admin-settings.md)).
- The GitHub repository's release page is reachable — public, or
  private with a PAT that has `Contents: Read` for that repo (classic
  PATs need `repo` scope).
- For private repos: a personal access token ready to paste in. App
  Versions encrypts it at rest via Nextcloud's `ICrypto` and never
  returns it in plaintext over the API (see the
  [`pat-management`](https://codeberg.org/Conduction/app-versions/src/branch/development/openspec/specs/pat-management) spec).

## Steps

The numbered steps land here once the UI is built. The flow follows
the spec scenarios in [`external-sources`](https://codeberg.org/Conduction/app-versions/src/branch/development/openspec/specs/external-sources)
and [`pat-management`](https://codeberg.org/Conduction/app-versions/src/branch/development/openspec/specs/pat-management):

1. Open the app card and pick "Bind to GitHub release source".
2. Enter `owner/repo` (e.g. `ConductionNL/openregister`).
3. If the repo is private, upload a PAT — set a label, the target
   pattern, and paste the token. The token hint (first 4 + last 4
   chars) is stored alongside the encrypted bytes; the plaintext is
   never returned again.
4. Reopen the version picker. Releases are now sorted newest-first,
   `tag_name` becomes the version string (stripping a leading `v`),
   and the source column reads `github`.

## Verification

You are set up correctly when: the version picker shows GitHub release
tags as version options, picking one installs it (and the active
version reflects it afterwards), and the audit log captures the
install with the source recorded as `github`.

## Common issues

| Symptom | Fix |
|---|---|
| "Source returned 404" | The `owner/repo` is wrong, or the repo is private and no PAT covers it. |
| "Source rate-limited" | The unauthenticated GitHub rate limit (60 req/h) is exhausted; upload a PAT or wait an hour. |
| Releases are listed but install fails with "missing app.tar.gz" | The release does not attach a Nextcloud-format archive; ask the maintainer to attach one or use a release that does. |
| PAT validates on upload but stops working later | The token has been revoked or expired on the GitHub side; re-upload a fresh PAT with the same label. |

## Reference

- [`external-sources`](https://codeberg.org/Conduction/app-versions/src/branch/development/openspec/specs/external-sources) spec — the GitHub release source contract.
- [`pat-management`](https://codeberg.org/Conduction/app-versions/src/branch/development/openspec/specs/pat-management) spec — encrypted storage, redaction, scope validation.
- [Roll back an app to a previous version](./02-rollback.md) — the App Store flow.
