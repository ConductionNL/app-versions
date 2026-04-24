# Usage

## Opening the app

Sign in as an administrator and click **App Versions** in the top navigation.

## Picking an app

The left column lists every installed app. Use the search box to narrow the
list; toggle **Show filters** to hide core apps (which are typically managed
through Nextcloud's own upgrade path, not this tool).

Click **Choose app** on a card to load its available versions.

## Picking a version

The right column shows every version the app store offers for the selected app
on the server's current update channel. Each row shows the version number and
a **Select** button.

When a version is selected, two action buttons appear:

- **Update / Degrade** — performs the install. The button label changes to
  *Degrade* when the selected version is older than the currently installed
  one; a warning banner explains the database risks.
- **Pick other** — clears the selection so you can choose a different version.

## Safe mode

The **Safe mode** toggle (on by default) blocks downgrades and rejects versions
outside the current update channel. Turn it off only when you know what you are
doing.

## Dry-run (debug) mode

The **Enable install dry-run** toggle runs the full install pipeline without
writing anything to disk or the database. The result panel shows every step the
installer would perform — useful for validating a risky downgrade before
committing.

## Reading the result panel

After an install completes, the result panel summarises:

- **App** — id of the app that was operated on
- **Transition** — `from_version → to_version`
- **Mode** — `Live install` or `Dry-run (no write)`
- **Result** — the version now installed (or that would be installed, for dry
  runs)

If dry-run mode is on, the panel also exposes per-step debug output: file
extraction, signature verification, migration planning, and repair steps.
