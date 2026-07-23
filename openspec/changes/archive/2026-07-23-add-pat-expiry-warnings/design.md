# Design: add-pat-expiry-warnings

## Threshold ledger
Additive migration: `warned_thresholds` TEXT column on `app_versions_pats` (JSON array, e.g. `["14d","3d","expired"]`), default `[]`, idempotent migration in the style of the existing `forge` column addition. Ledger reset on token update (a renewed token gets fresh warnings) — `PatManager::update` clears it whenever `expiresAt` changes.

Rationale vs config-ledger: expiry state is per-row token state; keeping it on the row survives token deletion cleanly (row gone → ledger gone) and avoids config-key sprawl.

## Job
`PatExpiryWarningJob extends TimedJob` (24 h, `info.xml`): iterate `PatMapper::findAll()`; skip `expiresAt === null`; compute days remaining; determine highest crossed threshold (`expired` > `3d` > `14d`); if not in ledger → notify owner (`uid` on the row) + append threshold. Lower thresholds are implied by higher ones (a token first seen at 2 days gets only the 3d warning, not 14d retroactively). Per-token try/catch.

## Notifications
`Notifier` subjects `pat_expiring` (params: label, forge, daysRemaining, deeplink) and `pat_expired` (label, forge, deeplink). Link = `PatDeeplinkBuilder` URL for the token's forge/kind. Notification recipient = token owner only (PATs are owner-scoped secrets; other admins see the badge in the shared Tokens panel).

## API/UI
- `expiryState` derived in the PAT serialization path (no storage): ok | expiring | expired | unknown; plus `daysRemaining` when known.
- TokensPanel: badge column — `NcNoteCard`-toned chips; neutral "expiry unknown" text for unknown. Deeplink button unchanged.

## Testing
- Unit: threshold crossing matrix (12 d, 2 d, expired, unknown, renewed-token ledger reset, once-per-threshold idempotence), serialization states.
- Vitest: badge rendering for the four states.
