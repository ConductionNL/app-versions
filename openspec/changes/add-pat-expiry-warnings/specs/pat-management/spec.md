---
status: proposed
---

# PAT Management Specification (delta)

**Status**: proposed
**Standards**: OCP\BackgroundJob\TimedJob, OCP\Notification\IManager
**Feature tier**: MVP

## Purpose

Close the silent-degradation gap for expiring PATs: proactive owner notifications at fixed thresholds and visible expiry state in the Tokens UI, using expiry data the validator already captures.

## ADDED Requirements

### Requirement: PAT expiry warnings [MVP]

A daily background job MUST, for every PAT with a known `expiresAt`, notify the token's owner when expiry is ≤14 days away, again when ≤3 days away, and once upon/after expiry — at most one notification per threshold per token, tracked persistently. Notifications MUST name the token label and forge, state days remaining (or "expired"), and link the per-forge token-renewal deeplink. Tokens without a known expiry MUST NOT be probed or notified.

#### Scenario: 14-day warning fires once

- GIVEN a GitHub PAT "conduction-bot" expiring in 12 days, not yet warned
- WHEN the job runs on two consecutive days
- THEN the owner MUST receive exactly one `pat_expiring` notification (from the first run) naming the token, forge, and days remaining, linking the renewal deeplink

#### Scenario: Escalation at 3 days and at expiry

- GIVEN the same token reaches 2 days remaining, then expires
- WHEN the job runs on each of those days
- THEN one 3-day-threshold notification and one `pat_expired` notification MUST be delivered (each once)

#### Scenario: Unknown expiry is left alone

- GIVEN a Codeberg token whose validation captured no expiry
- WHEN the job runs
- THEN no notification MUST be sent for it

---

### Requirement: Expiry state in the PAT API and UI [MVP]

`GET /api/pats` MUST expose a derived `expiryState` (`ok` | `expiring` (≤14 d) | `expired` | `unknown`) per token. The Tokens panel MUST badge `expiring` tokens with days remaining (warning tone) and `expired` tokens (error tone), and MUST show "expiry unknown" neutrally for `unknown`.

#### Scenario: Badges reflect state

- GIVEN tokens in states ok, expiring (5 d), expired, unknown
- WHEN the Tokens panel renders
- THEN the expiring token MUST show a warning badge "expires in 5 days", the expired one an error badge, the unknown one a neutral "expiry unknown", and the ok one no expiry badge
