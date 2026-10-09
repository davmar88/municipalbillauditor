# API contract (v1)

This is the contract between the Laravel API (`backend/`), the React web app (`web/`) and the mobile app (`mobile/`). If code and this document disagree, this document wins; change it deliberately and update all three apps together.

## Conventions

- Base URL: `/api/v1`. All request and response bodies are JSON unless stated (file upload is `multipart/form-data`).
- Auth: Laravel Sanctum personal access tokens sent as `Authorization: Bearer <token>`. Both web and mobile use tokens (no cookie/session auth).
- Single resources are wrapped: `{ "data": { ... } }`. Collections: `{ "data": [ ... ] }`. Collections are not paginated in v1.
- Money is always an integer number of **cents** in fields ending `_cents` (ZAR). Never floats.
- Dates are `YYYY-MM-DD`. Timestamps are ISO-8601 UTC strings (`2026-10-09T05:17:14.000000Z`, Laravel's default).
- IDs are integers.
- Errors use Laravel's defaults:
  - `401` `{ "message": "Unauthenticated." }`
  - `403` `{ "message": "..." }` (also used when a resource belongs to another user; returning `404` instead is acceptable and preferred so ownership isn't leaked)
  - `404` `{ "message": "..." }`
  - `422` `{ "message": "...", "errors": { "field": ["..."] } }`
  - `429` on throttled auth endpoints
- Every resource is scoped to the authenticated user. A user can never read or change another user's data.

## Enums

| Name | Values |
|---|---|
| `metro` | `johannesburg`, `tshwane`, `cape_town`, `ethekwini`, `ekurhuleni`, `nelson_mandela_bay`, `buffalo_city`, `mangaung`, `other` |
| `property_type` | `residential`, `sectional_title`, `bulk_residential`, `commercial`, `industrial`, `agricultural`, `vacant_land` |
| `service` | `water`, `electricity`, `sewerage`, `refuse`, `rates`, `other` |
| `reading_type` | `actual`, `estimated`, `unknown` |
| `unit` | `kl`, `kwh`, or `null` |
| `bill_status` | `needs_review` (no line items yet), `extracting` (AI extraction queued/running), `extraction_failed`, `audited` |
| `extraction_source` | `manual`, `ai` |
| `finding_rule` | `estimated_reading`, `consecutive_estimates`, `tariff_mismatch`, `outage_charge`, `consumption_spike`, `arithmetic_mismatch` |
| `severity` | `low`, `medium`, `high` |
| `finding_status` | `open`, `dismissed`, `disputed` |
| `dispute_status` | `draft`, `submitted`, `acknowledged`, `escalated`, `resolved`, `rejected` |
| `dispute_event_type` | `created`, `letter_edited`, `submitted`, `acknowledged`, `response_received`, `escalated`, `resolved`, `rejected`, `note` |
| `deadline_type` | `lodge_dispute`, `escalate` |

## Resource shapes

### User
```json
{
  "id": 1,
  "name": "Thandi M",
  "email": "thandi@example.com",
  "popia_consent_version": "2026-10-v1",
  "popia_consented_at": "2026-10-09T05:17:14.000000Z",
  "ai_extraction_consent": false,
  "created_at": "2026-10-09T05:17:14.000000Z"
}
```

### Metro
```json
{
  "code": "ethekwini",
  "name": "eThekwini Metropolitan Municipality",
  "dispute_window_days": 30,
  "response_wait_days": 30,
  "dispute_channels": [
    { "type": "statement", "label": "Billing query channel", "value": "Use the query email, portal or walk-in centre printed on your municipal statement." }
  ],
  "escalation_steps": [
    { "level": 0, "name": "Billing query", "description": "Written dispute to the municipality's billing or revenue department." },
    { "level": 1, "name": "Senior revenue official", "description": "..." },
    { "level": 2, "name": "Ward councillor or municipal ombudsman", "description": "..." },
    { "level": 3, "name": "Public Protector", "description": "..." }
  ],
  "verified": false
}
```
`verified: false` means the windows and steps are sensible defaults that have not yet been confirmed against that municipality's credit control and debt collection by-law. Clients must show an "unverified" hint when `verified` is false.

### Property
```json
{
  "id": 3,
  "nickname": "Sunset Court",
  "metro": "johannesburg",
  "account_number": "5501234567",
  "account_number_masked": "••••4567",
  "address": "12 Example Rd, Melville",
  "property_type": "sectional_title",
  "bills_count": 4,
  "open_findings_count": 2,
  "created_at": "..."
}
```
`account_number` is included only on single-property responses (`GET/POST/PATCH /properties/{id}`); list responses include only `account_number_masked`.

### Line item
```json
{
  "id": 10,
  "service": "water",
  "description": "Water consumption",
  "tariff_category": "Residential",
  "reading_type": "estimated",
  "previous_reading": 1203.0,
  "current_reading": 1241.0,
  "consumption": 38.0,
  "unit": "kl",
  "amount_cents": 152340
}
```
`tariff_category`, `previous_reading`, `current_reading`, `consumption`, `unit` may be `null`. Readings and consumption are numbers (decimals allowed).

### Finding
```json
{
  "id": 7,
  "bill_id": 5,
  "rule": "consumption_spike",
  "severity": "medium",
  "confidence": 0.8,
  "title": "Water use is 3.1 times your usual",
  "explanation": "Plain-language explanation of what was detected and why it may be wrong.",
  "estimated_overcharge_cents": 98000,
  "evidence": { "consumption": 38.0, "median_consumption": 12.2, "bills_compared": 5 },
  "status": "open",
  "created_at": "..."
}
```
`estimated_overcharge_cents` may be `null` when the rule can't estimate an amount. `confidence` is a number between 0 and 1. `evidence` is a rule-specific object (see `docs/audit-rules.md`).

### Bill
```json
{
  "id": 5,
  "property_id": 3,
  "bill_date": "2026-09-25",
  "period_start": "2026-08-20",
  "period_end": "2026-09-19",
  "due_date": "2026-10-15",
  "total_cents": 412350,
  "status": "audited",
  "extraction_source": "manual",
  "has_file": true,
  "file_mime": "image/jpeg",
  "dispute_deadline": "2026-10-25",
  "findings_summary": { "open_count": 2, "high_count": 1, "potential_overcharge_cents": 98000 },
  "line_items": [ /* Line item */ ],
  "findings": [ /* Finding, ordered by severity desc then id */ ],
  "created_at": "..."
}
```
- `bill_date`, `period_start`, `period_end`, `due_date`, `total_cents` may be `null` until known.
- `total_cents` is the **current charges** total for the period (excluding arrears/opening balance).
- `dispute_deadline` = `bill_date + metro.dispute_window_days` (null when `bill_date` is null).
- In collection responses (`GET /properties/{id}/bills`) `line_items` and `findings` are omitted; everything else is present.

### Outage
```json
{ "id": 2, "property_id": 3, "service": "water", "starts_at": "2026-09-01T06:00:00.000000Z", "ends_at": "2026-09-09T18:00:00.000000Z", "notes": "Rand Water maintenance", "created_at": "..." }
```

### Dispute
```json
{
  "id": 4,
  "bill_id": 5,
  "property": { "id": 3, "nickname": "Sunset Court", "metro": "johannesburg" },
  "bill": { "id": 5, "bill_date": "2026-09-25" },
  "status": "submitted",
  "finding_ids": [7, 8],
  "amount_disputed_cents": 98000,
  "letter_subject": "Dispute of municipal account 5501234567: bill dated 25 September 2026",
  "letter_body": "Plain text letter...",
  "channel": "email",
  "municipality_reference": "QRY-889123",
  "lodge_deadline": "2026-10-25",
  "submitted_at": "2026-10-09T08:00:00.000000Z",
  "response_due_at": "2026-11-08T08:00:00.000000Z",
  "escalation_level": 0,
  "next_step": { "name": "Senior revenue official", "description": "...", "due_at": "2026-11-08T08:00:00.000000Z" },
  "outcome_amount_cents": null,
  "resolved_at": null,
  "events": [ { "id": 1, "type": "created", "note": null, "occurred_at": "..." } ],
  "created_at": "..."
}
```
- `lodge_deadline` = the bill's `dispute_deadline`.
- `response_due_at` = `submitted_at + metro.response_wait_days`; after each escalation it resets to `escalated_at + metro.response_wait_days`.
- `next_step` is the next escalation step after `escalation_level`, or `null` when the dispute is `resolved`/`rejected`/`draft` or there is no further step.
- `events` ordered by `occurred_at` ascending. In collection responses `events` and `letter_body` are omitted.

## Endpoints

### Auth and account (throttled: 6 requests per minute per IP on register/login)
| Method | Path | Body | Response |
|---|---|---|---|
| POST | `/auth/register` | `name`, `email`, `password` (min 8), `password_confirmation`, `popia_consent` (must be `true`), `ai_extraction_consent` (bool, optional, default false), `device_name` (optional) | `201 { "token": "...", "user": User }` |
| POST | `/auth/login` | `email`, `password`, `device_name` (optional) | `200 { "token": "...", "user": User }`; wrong credentials → `422` with `errors.email` |
| POST | `/auth/logout` | – | `204` (revokes current token) |
| GET | `/me` | – | `{ data: User }` |
| PATCH | `/me` | `name?`, `ai_extraction_consent?` | `{ data: User }` |
| GET | `/me/export` | – | `200` JSON: `{ "exported_at", "user", "properties": [Property with "bills": [Bill], "outages": [Outage]], "disputes": [Dispute] }` with `Content-Disposition: attachment; filename="my-data.json"` |
| DELETE | `/me` | `password` | `204`; deletes the user, every related row, every stored bill file and all tokens. Wrong password → `422` |

### Reference data
| Method | Path | Response |
|---|---|---|
| GET | `/metros` | `{ data: [Metro] }` (public, no auth needed) |

### Dashboard
`GET /dashboard` →
```json
{ "data": {
  "properties_count": 2,
  "open_findings_count": 3,
  "potential_overcharge_cents": 154000,
  "active_disputes_count": 1,
  "recovered_cents": 0,
  "upcoming_deadlines": [
    { "type": "lodge_dispute", "due_on": "2026-10-25", "bill_id": 5, "dispute_id": null, "property_nickname": "Sunset Court", "label": "Lodge a dispute for the 25 Sep 2026 bill" },
    { "type": "escalate", "due_on": "2026-11-08", "bill_id": 5, "dispute_id": 4, "property_nickname": "Sunset Court", "label": "Escalate if the municipality hasn't responded" }
  ]
} }
```
- `lodge_dispute` deadlines: bills with at least one open `medium`/`high` finding that are not in a dispute, using `dispute_deadline`.
- `escalate` deadlines: disputes in `submitted`/`acknowledged`/`escalated` using `response_due_at` (as a date).
- Sorted by `due_on` ascending; includes overdue items; at most 20.
- `recovered_cents` = sum of `outcome_amount_cents` over `resolved` disputes.

### Properties
| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/properties` | – | `{ data: [Property] }` |
| POST | `/properties` | `nickname`, `metro`, `account_number`, `address`, `property_type` | `201 { data: Property }` |
| GET | `/properties/{id}` | – | `{ data: Property }` |
| PATCH | `/properties/{id}` | any of the POST fields | `{ data: Property }` |
| DELETE | `/properties/{id}` | – | `204` (deletes its bills, files, findings, outages and disputes) |

### Outages
| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/properties/{id}/outages` | – | `{ data: [Outage] }` |
| POST | `/properties/{id}/outages` | `service` (`water`/`electricity`), `starts_at`, `ends_at` (after `starts_at`), `notes?` | `201 { data: Outage }`; re-audits the property's bills whose period overlaps |
| DELETE | `/outages/{id}` | – | `204`; re-audits overlapping bills |

### Bills
| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/properties/{id}/bills` | – | `{ data: [Bill (collection shape)] }` newest `bill_date` first (nulls last) |
| POST | `/properties/{id}/bills` | multipart: `file?` (jpeg, png, webp, heic, pdf; max 10 MB), `bill_date?`, `period_start?`, `period_end?`, `due_date?`, `total_cents?`, `line_items?` (array, or a JSON-encoded string of the array when multipart). At least one of `file` or `line_items` is required. | `201 { data: Bill }` |
| GET | `/bills/{id}` | – | `{ data: Bill }` |
| PUT | `/bills/{id}` | `bill_date?`, `period_start?`, `period_end?`, `due_date?`, `total_cents?`, `line_items` (array; replaces all line items) | `{ data: Bill }` after re-audit; sets `extraction_source` unchanged |
| POST | `/bills/{id}/audit` | – | `{ data: Bill }` after re-running all rules |
| GET | `/bills/{id}/file` | – | the original file, streamed with its MIME type; `404` if none |
| DELETE | `/bills/{id}` | – | `204` (deletes file, line items, findings; disputes on it too) |

Line item input fields: `service` (required), `description?`, `tariff_category?`, `reading_type` (default `unknown`), `previous_reading?`, `current_reading?`, `consumption?` (if null and both readings are present, the server computes `current - previous`), `unit?`, `amount_cents` (required, integer ≥ 0).

Bill creation flow:
1. `line_items` supplied → status `audited` after running the audit synchronously; `extraction_source: "manual"`.
2. Only `file` supplied, AI extraction is configured on the server **and** the user has `ai_extraction_consent: true` → status `extracting`; an `ExtractBill` job runs (synchronously when `QUEUE_CONNECTION=sync`), fills dates/total/line items, sets `extraction_source: "ai"`, then audits → `audited`. On failure → `extraction_failed`.
3. Only `file` supplied otherwise → status `needs_review` with no line items; the user enters them with `PUT /bills/{id}`.

Re-audit replaces the bill's findings but **preserves** the status of findings that still match (same `rule` + same line item/service): a dismissed finding stays dismissed and a disputed one stays disputed.

### Findings
| Method | Path | Body | Response |
|---|---|---|---|
| PATCH | `/findings/{id}` | `status`: `open` or `dismissed` | `{ data: Finding }`; `422` if the finding is `disputed` |

### Disputes
| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/disputes` | – | `{ data: [Dispute (collection shape)] }` newest first |
| POST | `/bills/{id}/disputes` | `finding_ids` (non-empty array of open findings on this bill) | `201 { data: Dispute }` in `draft` with a generated letter; those findings become `disputed` |
| GET | `/disputes/{id}` | – | `{ data: Dispute }` |
| PATCH | `/disputes/{id}` | `letter_subject?`, `letter_body?` | `{ data: Dispute }`; only while `draft` (else `422`); adds a `letter_edited` event |
| DELETE | `/disputes/{id}` | – | `204`; only while `draft` (else `422`); its findings go back to `open` |
| POST | `/disputes/{id}/submit` | `channel` (`email`, `portal`, `walk_in`, `phone`, `other`), `municipality_reference?`, `submitted_at?` (default now) | `{ data: Dispute }` status `submitted`; only from `draft` |
| POST | `/disputes/{id}/events` | `type` (`acknowledged`, `response_received`, `note`), `note?`, `occurred_at?` | `{ data: Dispute }`; `acknowledged` moves `submitted` → `acknowledged` |
| POST | `/disputes/{id}/escalate` | `note?` | `{ data: Dispute }`; from `submitted`/`acknowledged`/`escalated` only, and only if a next step exists; increments `escalation_level`, status `escalated`, resets `response_due_at` |
| POST | `/disputes/{id}/resolve` | `outcome` (`resolved` or `rejected`), `outcome_amount_cents?`, `note?` | `{ data: Dispute }`; from any non-draft, non-final status |
