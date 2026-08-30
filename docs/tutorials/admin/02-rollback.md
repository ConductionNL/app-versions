---
sidebar_position: 2
title: Roll back an app to a previous version
description: Pick an earlier version of an installed app from the App Store and replace the current install.
---

# Roll back an app to a previous version

The core workflow: a recent update broke something, and you want the
previous version back without waiting for the upstream maintainer to
ship a fix.

> **This guide is being written as Versioniq approaches feature-completeness.**
> Bodies and screenshots fill in once the admin UI lands. Follow the
> [GitHub repository](https://github.com/ConductionNL/versioniq) for
> milestones.

## Goal

By the end of this guide you will have opened the version picker for an
installed app, selected an earlier release, installed it, and
confirmed that the app is now running the older version.

## Prerequisites

- Versioniq is installed, enabled, and reachable as an admin (see
  [Open Versioniq for the first time](./01-admin-settings.md)).
- The app you want to roll back was installed from the Nextcloud App
  Store. (For GitHub-hosted apps, see [Connect a GitHub release source](./03-github-source.md).)
- A few minutes of maintenance window — the rollback replaces the
  active install.

## Steps

The numbered steps land here once the UI is built. The flow follows the
spec scenarios in [`version-management`](https://github.com/ConductionNL/versioniq/blob/development/openspec/specs/version-management):
open the app card → version picker → pick a version → confirm →
install → return to the list, the card now shows the older version.

## Verification

You are set up correctly when: the app's card in Versioniq shows the
new (older) version number, the app's own About / Settings panel shows
the same version, and the rollback is recorded in the audit log.

## Common issues

| Symptom | Fix |
|---|---|
| Version picker is empty | The app has only one published version in the App Store — there is nothing to roll back to. |
| Install fails with "incompatible with this Nextcloud version" | The older release predates your Nextcloud version; pick a release with a compatible `max-version` or hold off until the upstream maintainer ships a fix. |
| App is missing data after rollback | The older release has a different database schema; restore from a database backup taken before the original update. |

## Reference

- [`version-management`](https://github.com/ConductionNL/versioniq/blob/development/openspec/specs/version-management) spec — every scenario the UI implements.
- [Connect a GitHub release source](./03-github-source.md) — for apps that live outside the App Store.
