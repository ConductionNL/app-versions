---
sidebar_position: 1
---

# Versioniq

Install any earlier or newer version of already installed Nextcloud
apps. Essential for debugging, testing compatibility, and recovering
from a breaking change in a minor release.

> **Status: in development.** This documentation site is up so the
> brand surface and the eventual journeydoc tutorials have a stable
> home. Step-by-step walkthroughs and screenshots land as the admin UI
> matures. Follow the [GitHub repository](https://github.com/ConductionNL/versioniq)
> for milestones.

## What does it do?

Versioniq gives Nextcloud admins a version picker over every
installed app, with three things working together:

- **Multi-source.** Versioniq queries the Nextcloud App Store and,
  per app, optionally a GitHub releases endpoint — public or, with a
  stored personal access token, private. The version list is merged
  and labelled by origin.
- **Rollback or pin.** Picking a version replaces the currently
  installed one. That works in both directions: roll back to a
  known-good release after an update breaks production, or pin to a
  newer release candidate to test compatibility before the rest of the
  fleet catches up. A pin is **enforced inside Versioniq and
  monitored elsewhere**: Versioniq's own install path refuses to
  overwrite a pinned app without an explicit override, but Nextcloud
  core exposes no hook to veto its own updater — so if the regular
  Apps page (or `occ app:update`) updates a pinned app anyway, admins
  are notified immediately and offered a one-click re-pin. The UI says
  this plainly; a pin is a guardrail Versioniq controls, not a lock
  on the whole instance.
- **Audit-trailed.** Every install, downgrade, or pin is logged with
  who, what, and when — so a Friday-evening rollback by an on-call
  admin is visible Monday morning without digging through server logs.

The app is built around several specs (see the openspec tracker on
Codeberg):

- [`version-management`](https://github.com/ConductionNL/versioniq/blob/development/openspec/specs/version-management) — list installed apps, pick a version, install.
- [`external-sources`](https://github.com/ConductionNL/versioniq/blob/development/openspec/specs/external-sources) — GitHub releases as a source alongside the App Store.
- [`pat-management`](https://github.com/ConductionNL/versioniq/blob/development/openspec/specs/pat-management) — encrypted PAT storage for private GitHub repos.
- [`app-discovery`](https://github.com/ConductionNL/versioniq/blob/development/openspec/specs/app-discovery) — a single search aggregator over the App Store, your PAT-visible repos, and (opt-in) public GitHub topic search.
- [`version-pinning`](https://github.com/ConductionNL/versioniq/blob/development/openspec/specs/version-pinning) — pin an app to a version, self-enforced on Versioniq's own install path, with drift detection and notification for changes made elsewhere.

## Getting started

The admin UI is being built. Once the first usable build is tagged, the
tutorials below will be filled in — they are placeholders today, marked
clearly as such.

- Setting things up? The **[Admin guide](/docs/category/admin-guide)**
  will cover the first launch, picking a version, and connecting a
  GitHub release source.
- Curious how it works at the API level? The specs linked above are
  the source of truth while the user-facing documentation catches up.

Free and open source under the EUPL-1.2 license. For support,
contact support@conduction.nl.
