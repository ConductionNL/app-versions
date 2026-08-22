---
sidebar_position: 1
title: Open Versioniq for the first time
description: Open Versioniq from the admin settings, see the list of installed apps, and confirm the App Store source is reachable.
---

# Open Versioniq for the first time

A first look at the Versioniq admin panel — where it lives, what
the installed-apps list looks like, and how to tell the App Store
source is wired up.

## Goal

By the end of this guide you will have opened Versioniq from the
admin settings, recognised the alphabetical list of installed apps and
their current versions, and confirmed that the App Store source
responds without an error banner.

## Prerequisites

- A Nextcloud **admin** account on an instance where the Versioniq
  app is installed and enabled. Non-admins are blocked at the API
  level (see the [`version-management`](https://github.com/ConductionNL/versioniq/blob/development/openspec/specs/version-management) spec).
- Outbound HTTPS to `apps.nextcloud.com` (the App Store source).

## Steps

1. Sign in as an admin and open **Settings → Administration → App
   Versions** (it has its own section in the left sidebar — App
   Versions deliberately has no entry in the top app navigation).
2. The panel opens on the **Apps** tab: the update channel and safe-mode
   toggles at the top, then the searchable grid of installed apps. The
   other tabs (**Sources**, **Tokens**, **Trusted sources**) configure
   [external forge sources](./03-github-source.md).
3. Pick any app card via **Choose app**. A status note shows while the
   version list is fetched from the active source; the footer then names
   that source (`appstore` by default).

![The Versioniq admin panel on the Apps tab](/img/tutorials/admin-overview.png)

## Verification

You are set up correctly when: the Versioniq panel renders the
installed-apps grid (without the Versioniq app itself in the list —
it excludes itself by design), each card shows a current version, and
opening the picker on any app returns a populated version list rather
than an error banner.

## Common issues

| Symptom | Fix |
|---|---|
| Versioniq missing from admin settings | The app is not enabled — enable it from **Apps → Disabled apps**. |
| "Forbidden" message on load | The signed-in account is not an admin — Versioniq is admin-only. |
| Installed apps load but version pickers are empty | Outbound HTTPS to `apps.nextcloud.com` is blocked at the network layer; allow it and reload. |

## Reference

- [Roll back an app to a previous version](./02-rollback.md) — the next step.
- [`version-management`](https://github.com/ConductionNL/versioniq/blob/development/openspec/specs/version-management) spec.
