---
sidebar_position: 1
title: Open App Versions for the first time
description: Open App Versions from the admin settings, see the list of installed apps, and confirm the App Store source is reachable.
---

# Open App Versions for the first time

A first look at the App Versions admin panel — where it lives, what
the installed-apps list looks like, and how to tell the App Store
source is wired up.

> **This guide is being written as App Versions approaches feature-completeness.**
> Bodies and screenshots fill in once the admin UI lands. Follow the
> [GitHub repository](https://github.com/ConductionNL/app-versions) for
> milestones.

## Goal

By the end of this guide you will have opened App Versions from the
admin settings, recognised the alphabetical list of installed apps and
their current versions, and confirmed that the App Store source
responds without an error banner.

## Prerequisites

- A Nextcloud **admin** account on an instance where the App Versions
  app is installed and enabled. Non-admins are blocked at the API
  level (see the [`version-management`](https://github.com/ConductionNL/app-versions/tree/development/openspec/specs/version-management) spec).
- Outbound HTTPS to `apps.nextcloud.com` (the App Store source).

## Steps

The numbered steps land here once the UI is built. Each step that
warrants a screenshot will get a matching shoot() call in the
`docs-screenshots` capture spec (see ADR-030 / journeydoc).

## Verification

You are set up correctly when: the App Versions panel renders the
installed-apps grid (without the App Versions app itself in the list —
it excludes itself by design), each card shows a current version, and
opening the picker on any app returns a populated version list rather
than an error banner.

## Common issues

| Symptom | Fix |
|---|---|
| App Versions missing from admin settings | The app is not enabled — enable it from **Apps → Disabled apps**. |
| "Forbidden" message on load | The signed-in account is not an admin — App Versions is admin-only. |
| Installed apps load but version pickers are empty | Outbound HTTPS to `apps.nextcloud.com` is blocked at the network layer; allow it and reload. |

## Reference

- [Roll back an app to a previous version](./02-rollback.md) — the next step.
- [`version-management`](https://github.com/ConductionNL/app-versions/tree/development/openspec/specs/version-management) spec.
