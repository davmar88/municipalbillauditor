# Municipal Bill Auditor

People in South Africa photograph or forward their municipal bill. The app reads it with Claude and flags likely errors, then emails the dispute to the municipality on the customer's behalf, reads the replies, chases and escalates. It covers all 257 municipalities in South Africa, and it works by email only: there is no WhatsApp in the flow.

## What it checks

- **Estimated readings** billed instead of actual meter readings
- **Wrong tariff category**, for example a property billed on a commercial tariff instead of bulk residential
- **Charges during outages**, such as water billed while supply was off
- **Spikes against history**, where a bill jumps well above the account's own past usage

## Why now

These are figures from the founder's research notes and still need checking against their sources:

- The City of Johannesburg has acknowledged about 16,000 inaccurate-bill queries a month across roughly 1.2 million accounts.
- A Johannesburg resident only had more than R2.6m removed from her bill after the Public Protector stepped in.
- Johannesburg Water loses around 45% of its water as non-revenue (about R3.8bn), which drives pressure to recover revenue.
- Tshwane refunded about R89,000 to a property billed on a commercial tariff instead of bulk residential.

Today these errors are fixed one case at a time by councillors, ratepayer groups and the Public Protector. Many follow patterns that software can detect.

## How it makes money

Revenue comes from two places only: households' prepaid credit and a flat monthly fee from managing agents and businesses. There is no success fee: we take nothing from what a dispute wins.

| Customer                       | What they pay                                                                                                                             |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Households                     | A fixed price per job from prepaid credit: R15 to check a bill, R49 for a dispute. Top-ups of R50, R100, R250 or R500 by card (Paystack). |
| Landlords and managing agents  | R1,500 a month per organisation, with 100 bill checks and unlimited disputes included. Extra checks at R15 each from prepaid credit.      |
| Businesses and body corporates | R2,500 a month per organisation, on the same terms. Either can have a negotiated monthly fee.                                             |

A bill check reads the bill and runs the four checks. A dispute covers drafting it, emailing the municipality, reading its replies, chasing and escalating. The customer always sees the price before a job runs. A bill that arrives without enough credit waits and is checked after the next top-up, and a bill that can't be read is refunded. Every price is set from env in `config/auditor.php`, and `WELCOME_CREDIT` (0 by default) gives new accounts some credit to start.

Every Claude call is recorded with its model and token counts and costed in rand, and staff see each month's margin: top-ups and monthly fees received against what Claude cost.

### Pricing model

[`pricing/bill-auditor-pricing-model.xlsx`](pricing/bill-auditor-pricing-model.xlsx) is a three-year revenue and profit model. It still models the **old** pricing (success fees, a per-unit subscription and free household checks) and has not been updated for prepaid jobs and monthly fees.

## Known risks

- **Processes differ by municipality**, so dispute rules and addresses have to be kept per municipality, for all 257.
- **Unverified addresses.** The directory's billing emails were read from a public directory and none is verified. Staff verify them; until then a dispute goes to the address printed on the customer's bill first.
- **Deadlines are short.** An eThekwini councillor advises disputing within 30 days of an incorrect bill.
- **False alarms burn trust.** A wrong flag costs a paying customer time with the municipality.
- **Personal financial data.** The app must comply with POPIA from day one.

## Status

A working first version of the app is built: Laravel 13 and React (Inertia) on MySQL. The screens follow the layouts in [`docs/design.md`](docs/design.md), and the public pages follow [`docs/landing-page.md`](docs/landing-page.md).

Before launch, these still need real values or checking:

- **Municipal rules.** Each municipality's dispute window, reply time and escalation steps are cautious placeholders, marked "not verified" in the staff reference screen.
- **Municipal addresses.** Billing emails need verifying, and escalation (municipal manager) addresses need adding, in the staff reference screen.
- **Tariff rates.** The demo rates are illustrative. The wrong-tariff check needs each municipality's published rates to work out the overcharge.
- **Proof figures.** The landing page hides the research-note figures in production until each has a source link (`app/Support/SiteContent.php`).
- **Company details.** Company name, information officer and contact address for the privacy notice (`COMPANY_NAME`, `INFORMATION_OFFICER`, `CONTACT_EMAIL`).
- **Prices.** The job prices, top-up packs and monthly fees are starting values (`PRICE_*`, `TOP_UP_PACKS`, `WELCOME_CREDIT`, `INCLUDED_BILL_CHECKS_PER_MONTH`), and `USD_ZAR_RATE` is a placeholder.
- **Email and payments.** An SMTP provider for sending, an inbound route for replies, and Paystack keys.

## Running the app

You need PHP 8.3 or later, Composer, Node 22 and MySQL 8.

```bash
composer install
cp .env.example .env
php artisan key:generate
mysql -u root -e "CREATE DATABASE municipal_bill_auditor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate
php artisan municipalities:import   # all 257 municipalities
npm install
composer dev        # web server, queue worker and Vite together
```

Set `DB_USERNAME` and `DB_PASSWORD` in `.env` to match your MySQL user.

### Demo data

```bash
php artisan db:seed --class=DemoSeeder
```

This loads the municipal directory and adds a household with R100 of credit, a managing agent with eight units, a body corporate with a tariff claim, and a staff member. Sign in as `household@example.com`, `agent@example.com`, `business@example.com` or `staff@example.com`, all with the password `password`. Every figure is illustrative.

### Reading bills with Claude

Set `ANTHROPIC_API_KEY` to read uploaded and forwarded bills automatically. The model is set in `config/auditor.php` (`ANTHROPIC_BILL_MODEL` overrides it), and the request uses structured outputs so the reply always has the same shape. Server-side fallbacks are on, so a request the model declines is retried on the API's default fallback model.

Without a key, every bill waits for its details to be typed in. The privacy notice says the bill file is sent to Anthropic for reading, so keep it accurate if you change provider.

Replies from municipalities are read the same way (`ANTHROPIC_REPLY_MODEL`, defaulting to the bill model), with any attached letter sent as a document or image. A reply Claude can't read, or one from outside the municipality's email domain, waits in the staff work queue.

### Payments (Paystack)

Set `PAYSTACK_PUBLIC_KEY` and `PAYSTACK_SECRET_KEY` (test keys put Paystack in test mode). Top-ups and monthly fees go through Paystack's checkout. The first monthly fee paid by card saves the card, and `fees:invoice` charges it on the 1st of each month. Staff can record EFT top-ups from the work queue.

A payment is applied once, by whichever of these hears from Paystack first:

- the customer's return to `/credit/callback`
- Paystack's answer to a saved-card charge
- `payments:reconcile`, which asks about open payments every five minutes
- the signed webhook at `POST /webhooks/paystack`

A Paystack account has one webhook. If the account is the app's alone, point the webhook here. If it's shared with another site, leave the webhook where it is.

Payments stay in the account's main balance: nothing is split off to a subaccount or paid out.

### Email

Disputes are emailed from `MAIL_FROM_ADDRESS` over whatever `MAIL_MAILER` you configure (local development logs them), with replies going to `d-<token>@INBOUND_EMAIL_DOMAIN`. Nothing is sent without the customer's one-time mandate.

### Background work

Bills are read, disputes sent and replies read on the queue, so production needs a worker:

```bash
php artisan queue:work
```

Deadline reminders and dispute chasing (follow-up, escalation to the municipal manager, the Public Protector draft for the customer) run daily at 07:00 South African time, and monthly fees are raised on the 1st. Run the scheduler from cron:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

### Inbound email: forwarded e-bills and dispute replies

Each account gets an address like `code@bills.example.com`, and each dispute one like `d-<token>@bills.example.com`. Point your provider's inbound route at `POST /inbound/email` and set `INBOUND_EMAIL_DOMAIN` to the receiving domain. Mailgun is the default: set `MAILGUN_WEBHOOK_SIGNING_KEY` and its route forwards are verified by their signature. Other providers can post the same fields with `INBOUND_EMAIL_SECRET` in an `X-Inbound-Secret` header.

### Tests

```bash
mysql -u root -e "CREATE DATABASE municipal_bill_auditor_test"
composer test            # Pint, then PHPUnit
npm run check            # lint and formatting
npm run types:check      # TypeScript
```

CI runs all of these on every pull request, against MySQL 8.

## Where things are

| Path                              | What it holds                                                                                                                |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `app/Audit`                       | The checks, deadlines and letters. Plain PHP with exact decimal money, and no Laravel.                                       |
| `app/Services`                    | Reading bills and replies, the wallet and payments, Claude costs, the municipal directory, and emailing and chasing disputes |
| `app/Http/Controllers`            | The app screens, the staff tools, the public pages and the inbound email and Paystack webhooks                               |
| `app/Console/Commands`            | The daily reminder and chase run, monthly fees, and the municipality import                                                  |
| `database/data`                   | The directory of all 257 municipalities                                                                                      |
| `resources/js/pages`              | The React screens                                                                                                            |
| `resources/views/marketing`       | The public pages, rendered on the server for search engines                                                                  |
| `database/seeders/DemoSeeder.php` | The demo accounts                                                                                                            |
