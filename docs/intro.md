---
sidebar_position: 1
title: Introduction
description: Version manifest registry for Conduction's Nextcloud apps. The source of truth for which app version pairs with which Nextcloud release, which Conduction stack version, and which dependent app versions.
---

# AppVersions

Version manifest registry for Conduction's Nextcloud apps. The source of truth for which app version pairs with which Nextcloud release, which Conduction stack version, and which dependent app versions.

## What this site is

`app-versions` is the **version manifest registry** for the Conduction Nextcloud app fleet. Every app, every release, every Nextcloud-version compatibility window, every cross-app pinning is recorded here so that:

- An admin installing the stack knows which versions pair correctly.
- A CI job in any app can query "what's the latest stable I should pin against?" without scraping the Nextcloud app store.
- A migration tool can plan the upgrade path from one stack release to the next.

## Where to go from here

The sidebar on the left lists every available page. If you're integrating, start with the [architecture](./architecture/) section. If you're publishing a new manifest, see the [tutorials](./tutorials/) for the publish flow.

Source for this site lives at
[codeberg.org/Conduction/app-versions](https://codeberg.org/Conduction/app-versions)
on the `main` branch under `docs/`. Every push to `documentation`, `main`, or
`development` rebuilds and republishes via the central
`Conduction/.github/.forgejo/workflows/documentation.yml` workflow.
