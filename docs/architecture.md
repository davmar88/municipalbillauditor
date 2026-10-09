# Architecture

```
backend/   Laravel API (PHP 8.3), Sanctum tokens, SQLite for local dev and tests
web/       React + TypeScript single-page app built with Vite
mobile/    React Native app (Expo, TypeScript) for Android and iOS
docs/      API contract, audit rules, this file
```

The web and mobile apps are thin clients over the same API (`docs/api.md`). All business logic (audit rules, deadlines, letters) lives in the backend so both clients behave the same.

## Backend
- **Auth**: Sanctum personal access tokens for both clients. Register/login are rate-limited.
- **Audit engine**: one class per rule implementing a shared interface, run by an `AuditService` that rebuilds a bill's findings while preserving the status of findings that still apply. Rules and thresholds: `docs/audit-rules.md`, `config/audit.php`.
- **Metros**: `config/metros.php` holds per-municipality dispute windows, response waits, channels and escalation steps. Values are defaults marked `verified: false` until checked against each metro's credit control by-law.
- **Bill extraction**: a `BillExtractor` interface. The Claude-based extractor reads a photo or PDF of a bill and returns structured fields. It only runs when an Anthropic API key is configured **and** the user opted in (`ai_extraction_consent`). Without it, users type in line items.
- **Dispute letters**: generated from a Blade text template using the bill, property and selected findings. They ask for correction, an actual meter reading, a written acknowledgement with a reference number, and note that the municipality should not take credit-control action on the disputed amount while the dispute is open (Municipal Systems Act 32 of 2000, s102(2)). Letters carry a "not legal advice" footer.
- **Deadlines**: lodge deadline = bill date + metro dispute window. Escalation due = submission (or last escalation) + metro response wait. A daily scheduled command emails reminders for deadlines due within 5 days.

## POPIA from day one
- **Consent**: registration requires explicit POPIA consent; the consent version and timestamp are stored. AI extraction is a separate opt-in because the bill image is sent to a third-party processor outside South Africa (POPIA s72).
- **Minimal, protected data**: account numbers and addresses are encrypted at rest (Laravel `encrypted` casts). Bill files are stored on a private disk under random names, never publicly reachable, and are only streamed to their owner.
- **Data subject rights**: users can export all their data (`GET /me/export`) and delete their account, which removes every row and stored file (`DELETE /me`).
- **Least exposure**: list responses mask account numbers; logs must not contain bill contents, account numbers or tokens.
- Still to do before launch: appoint and register an Information Officer, a privacy notice, an operator agreement with the AI provider, a retention schedule, and a breach-notification procedure.
