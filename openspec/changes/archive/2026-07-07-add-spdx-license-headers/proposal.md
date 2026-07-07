---
kind: config
---

# Proposal: add-spdx-license-headers

## Why

App Versions is licensed **AGPL-3.0-or-later** — and, unusually for the fleet,
*consistently* so: the `LICENSE` file is the GNU Affero GPL, `composer.json` declares
`"license": "AGPL-3.0-or-later"`, and `appinfo/info.xml` declares `<licence>agpl</licence>`.
There is **no licence drift to fix** here (this app is genuinely AGPL, not EUPL-with-a-wrong-
manifest like the rest of the fleet; any move to EUPL would be a deliberate relicense
decision, out of scope for this audit).

What *is* wrong is that **not one of the 38 PHP files under `lib/` carries a licence/
copyright header** (`grep -rl 'SPDX-License' lib/` returns 0). Every source file ships with
no machine-readable provenance, which fails REUSE compliance and the fleet's `spdx-headers`
quality gate (which requires `@license` + `@copyright` PHPDoc on every `lib/` PHP file). The
header MUST state the app's actual licence — **AGPL-3.0-or-later** — not EUPL.

## What Changes

- Add a licence/copyright header to every PHP file under `lib/` (38 files): a PHPDoc block
  with `@copyright` (Conduction B.V.) and `@license AGPL-3.0-or-later …`, plus the REUSE
  `SPDX-License-Identifier: AGPL-3.0-or-later` and `SPDX-FileCopyrightText` tags. No code
  logic changes. The declared licence matches this repo's `LICENSE`/`composer.json` (AGPL),
  NOT the fleet EUPL default.
- No `appinfo/info.xml` change (already `agpl`, consistent); no `LICENSE`/`composer.json`
  change (already AGPL).

## Impact

- Affected: 38 `lib/**/*.php` files (header docblock only). No behavioural change.
- Brings the app into REUSE compliance and green on the `spdx-headers` gate; makes the
  per-file licence explicit and consistent with the repository's AGPL-3.0 licence.
- Note (for product owner, not this change): App Versions is the one fleet app licensed
  AGPL rather than EUPL-1.2 — if that is unintended, a separate relicense decision (with
  contributor sign-off) is required; this change deliberately does NOT relicense, only
  states the licence that is actually in force.
