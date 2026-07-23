# Proposal: add-pat-expiry-warnings

## Summary
Warn before PATs lapse instead of failing at download time: a daily job notifies token owners at 14 and 3 days before a known expiry (once per threshold), and the Tokens UI badges expiring/expired tokens with the renewal deeplink.

## Motivation
`PatValidator` already captures `expiresAt` on upload and `PatResolver` already skips expired tokens — so today an expiring PAT degrades **silently**: the first symptom is a failed external install or an empty private-discovery result, exactly when an admin is trying to fix something. The pat-management spec noted expiry warnings as an out-of-scope follow-up; this change closes it. Small surface, disproportionate operational value (external installs and advisories for private repos all hang off PAT health).

## Scope
- `lib/BackgroundJob/PatExpiryWarningJob.php` — daily `TimedJob`: for every PAT with non-null `expiresAt`, notify its owner at ≤14 days and again at ≤3 days before expiry, and once upon expiry; one notification per threshold per token (ledger on the PAT row or config)
- `Notifier` subjects `pat_expiring` / `pat_expired` including token label, forge, days remaining, and the per-forge renewal deeplink as the notification link
- Tokens UI: "expires in N days" badge (warning ≤14 d), "expired" badge (error tone); deeplink button already exists
- `GET /api/pats` exposes derived `expiryState` (ok|expiring|expired) so the UI stays dumb

## Non-goals
- No auto-renewal, no probing forges for tokens without a known expiry (fine-grained/Codeberg tokens whose expiry the API didn't disclose stay unmonitored — honest limitation, shown as "expiry unknown")

## Impact
- MODIFIED/ADDED requirements in `pat-management`
- Touches `lib/BackgroundJob/` (new), `lib/Notification/Notifier.php`, `lib/Db/Pat*.php` (threshold ledger column or config ledger), `src/components/TokensPanel.vue`, `info.xml`
