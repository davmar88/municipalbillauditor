# Municipal Bill Auditor API

Laravel API behind the web and mobile apps. It stores municipal bills, flags
possible billing errors, drafts dispute letters and tracks deadlines.

The contract is [`docs/api.md`](../docs/api.md); the audit rules are
[`docs/audit-rules.md`](../docs/audit-rules.md); architecture and POPIA
requirements are in [`docs/architecture.md`](../docs/architecture.md). If this
code and those documents disagree, the documents win.

## Requirements

- PHP 8.3 with the `pdo_sqlite`, `mbstring`, `openssl` and `fileinfo` extensions
- Composer 2

## Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve            # http://localhost:8000/api/v1
```

`composer setup` does the same except seeding.

### File upload limits

Bill photos and PDFs can be up to 10 MB (see `docs/api.md`). PHP turns away
bigger uploads before Laravel sees them, and its usual defaults (2 MB per file,
8 MB per request) would reject ordinary phone photos. The PHP limits the API
needs are `upload_max_filesize = 12M` and `post_max_size = 16M`
(`App\Support\UploadLimits`). They sit above 10 MB so a slightly bigger file
still gets the friendly "The file is too big. The limit is 10 MB." message.

- **`php artisan serve` / `composer dev`**: nothing to do. This app's `serve`
  command (`app/Console/Commands/ServeCommand.php`) starts PHP with these limits.
  Laravel's own `serve` ignores `php -d ...` flags, so don't rely on those.
- **Plain `php -S`**: pass them yourself, from the `public/` folder:
  `cd public && php -d upload_max_filesize=12M -d post_max_size=16M -S localhost:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.
- **PHP-FPM**: `public/.user.ini` sets them when the document root is `public/`.
  You can also set them in `php.ini` or the pool config
  (`php_admin_value[upload_max_filesize] = 12M`, `php_admin_value[post_max_size] = 16M`).
- **nginx**: add `client_max_body_size 16m;` (its default is 1 MB).
  **Apache**: make sure `LimitRequestBody` is at least 16 MB, if you set it.

When a file is too big anywhere along the way, the API answers `422` with
`errors.file`, never a bare `413`.

### Demo login

`php artisan db:seed` (or `migrate --seed`) creates:

- **demo@example.com / password** (POPIA and AI consent both given)
- **Sunset Court**: Johannesburg, sectional title, six monthly bills. The newest
  bill shows an estimated water reading, a "Business" water tariff, water
  charged during a recorded water outage and an electricity spike.
- **Kloof Street**: Cape Town, residential, four clean bills.

Bill dates are relative to the day you seed, so the dashboard always has an
upcoming lodge deadline. Re-seed from scratch with `php artisan migrate:fresh --seed`.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `APP_KEY` | – | Required. Also encrypts account numbers, addresses and dispute letters at rest, so keep it safe and don't rotate it without re-encrypting. |
| `DB_CONNECTION` | `sqlite` | SQLite (`database/database.sqlite`) for local development. |
| `QUEUE_CONNECTION` | `sync` | `sync` runs AI extraction inline during the upload. Use `database` plus `php artisan queue:work` to run it in the background. |
| `MAIL_MAILER` | `log` | Deadline reminder emails go to `storage/logs/laravel.log` in development. |
| `MAIL_FROM_ADDRESS` | `reminders@example.com` | Sender of reminder emails. |
| `FRONTEND_URLS` | `http://localhost:5173,http://localhost:8081` | Comma-separated origins allowed by CORS for `api/*` (Vite web app, Expo mobile app). |
| `ANTHROPIC_API_KEY` | empty | Enables AI bill extraction. Empty means users type in their line items. |
| `ANTHROPIC_MODEL` | `claude-opus-5-5` | Model used for extraction. |

## Running

- API: `php artisan serve` (base URL `/api/v1`, health check `/up`).
- Queue worker (only when `QUEUE_CONNECTION` isn't `sync`): `php artisan queue:work`.
- Scheduler: run `php artisan schedule:run` every minute from cron (or
  `php artisan schedule:work` locally). It runs `deadlines:remind` daily at
  07:00 South African time, emailing users about lodge and escalation deadlines
  due within 5 days. Run it by hand with `php artisan deadlines:remind`.

## Tests and style

```bash
php artisan test               # in-memory SQLite, never calls the Anthropic API
./vendor/bin/pint --test       # code style (./vendor/bin/pint to fix)
```

Tests bind a fake `BillExtractor`; the Claude extractor's request format and
stop-reason handling are tested against a mocked HTTP transport.

## How it fits together

| Area | Where |
|---|---|
| Routes | `routes/api.php` |
| Controllers, form requests, API resources | `app/Http/` |
| Audit engine (one class per rule) | `app/Audit/`, thresholds in `config/audit.php` |
| Metros: dispute windows, channels, escalation steps | `config/metros.php` |
| Dispute letter template | `resources/views/letters/dispute.blade.php` |
| Bills, disputes, deadlines, account export/delete | `app/Services/` |
| AI extraction (`BillExtractor`, `ClaudeBillExtractor`, `ExtractBill` job) | `app/Extraction/`, `app/Jobs/` |
| Deadline reminders | `app/Console/Commands/SendDeadlineReminders.php`, `routes/console.php` |

### Audit behaviour worth knowing

- Every rule leans towards staying quiet. Only `medium` and `high` findings
  count towards `open_count`, `open_findings_count`, potential overcharge
  totals and lodge deadlines. `low` findings are information only.
- Re-auditing keeps a finding's id and status when the same rule still fires
  for the same service, so dismissed findings stay dismissed and disputed ones
  stay disputed. A disputed finding is never removed by a re-audit, even when
  its rule stops firing (for example `consecutive_estimates` moving to a newer
  bill): it is the record of what you disputed. If you delete a draft dispute,
  its findings reopen and the bill is re-audited, so any that no longer apply go.
- `tariff_mismatch` ignores a keyword negated with "non": "Non-Residential" is
  not a residential tariff. `non-residential` and `non-domestic` are business
  keywords in `config/audit.php`.
- `submitted_at` and `occurred_at` on disputes can't be in the future (a
  5-minute allowance covers a fast device clock).
- A line item's `description` is always a string: `""` when the bill line has
  no description.
- Creating, editing or deleting a bill re-audits the property's other bills,
  because their history changed. Recording or deleting an outage re-audits the
  bills whose period overlaps it. Changing a property's type re-audits its bills.
- When two findings concern the same charge (for example an estimated water
  reading in a month with a water outage), totals count that charge once: the
  larger estimate per bill and service. This applies to `potential_overcharge_cents`
  and `amount_disputed_cents`.

### Metros

All metros use the same defaults (30-day dispute window, 30-day response wait,
"use the channel printed on your statement", four escalation steps) and are
marked `verified: false`. Check each against the municipality's credit control
and debt collection by-law before changing them, and never add contact details
that haven't been verified.

## POPIA

- Registration requires POPIA consent. The consent version
  (`User::POPIA_CONSENT_VERSION`) and time are stored.
- AI extraction is a separate opt-in (`ai_extraction_consent`), because the
  bill image goes to a processor outside South Africa. It only runs when an
  API key is configured **and** the user opted in.
- Account numbers, addresses and dispute letters are encrypted at rest. List
  responses only show a masked account number, also inside a dispute's
  `letter_subject` in `GET /disputes`. The single dispute has the full subject.
- Bill files live on the private `bills` disk (`storage/app/private/bills`)
  under random names and are only streamed to their owner.
- `GET /api/v1/me/export` downloads everything; `DELETE /api/v1/me` deletes the
  user, every row, every stored file and all tokens. It asks for the password,
  so it is rate-limited (5 a minute and 20 an hour per user, 5 a minute per IP)
  to stop someone with a stolen token from guessing it.
- Logs must not contain bill contents, account numbers or tokens: extraction
  failures log only the bill id and a reason code, database errors are logged
  without bound values and exception traces omit function arguments.
- Still to do before launch: appoint and register an Information Officer,
  publish a privacy notice, sign an operator agreement with the AI provider,
  set a retention schedule and write a breach-notification procedure.
