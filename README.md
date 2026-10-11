# Municipal Bill Auditor

People in South Africa photograph or forward their municipal bill. The app reads it and flags likely errors, drafts the dispute, tracks the deadline and escalates when the municipality doesn't respond.

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

The three revenue lines work as a funnel:

| Customer                       | What they pay                                                                                                      | Role in the business                                                             |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------- |
| Businesses and body corporates | Success fee, about 20% to 35% of the back-refund won plus the first 12 months of future savings from a fixed error | First revenue. Customers pay nothing unless they win.                            |
| Landlords and managing agents  | Subscription per unit per month, about R30 to R80                                                                  | Steady, recurring revenue. They buy time saved and deadlines that aren't missed. |
| Households                     | Free bill checks, with an optional paid managed dispute (about R199 to R499)                                       | Acquisition, trust and data on error patterns per metro.                         |

Refunds usually arrive as a credit on the municipal account rather than cash, so success-fee contracts should invoice once the credit appears.

### Pricing model

[`pricing/bill-auditor-pricing-model.xlsx`](pricing/bill-auditor-pricing-model.xlsx) is a three-year revenue and profit model. Change the blue numbers on yellow cells in the **Assumptions** tab and the **Model** tab recalculates. Every starting value is an illustrative guess, not research.

With the starting guesses, the business is still loss-making in Year 3, subscriptions make up most of the revenue, and processing free household bills costs more than household disputes earn. The most important number to test with real customers is how many managing-agent units can be signed.

## Known risks

- **Processes differ by metro**, so dispute rules and templates have to be built per municipality.
- **Deadlines are short.** An eThekwini councillor advises disputing within 30 days of an incorrect bill.
- **False alarms burn trust.** A wrong flag costs a paying customer time with the municipality.
- **Personal financial data.** The app must comply with POPIA from day one.

## Status

A working first version of the app is built: Laravel 13 and React (Inertia) on MySQL. The screens follow the layouts in [`docs/design.md`](docs/design.md), and the public pages follow [`docs/landing-page.md`](docs/landing-page.md).

Before launch, these still need real values or checking:

- **Metro rules.** Each metro's dispute window, reply time and escalation steps are cautious placeholders, marked "not verified" in the staff reference screen.
- **Tariff rates.** The demo rates are illustrative. The wrong-tariff check needs each metro's published rates to work out the overcharge.
- **Proof figures.** The landing page hides the research-note figures in production until each has a source link (`app/Support/SiteContent.php`).
- **Company details.** Company name, information officer and contact address for the privacy notice (`COMPANY_NAME`, `INFORMATION_OFFICER`, `CONTACT_EMAIL`).
- **Prices.** R299 per managed household dispute, R50 per unit per month and a 25% success fee are the pricing model's starting guesses (`PRICE_*` and `SUCCESS_FEE_RATE`).

## Running the app

You need PHP 8.3 or later, Composer, Node 22 and MySQL 8.

```bash
composer install
cp .env.example .env
php artisan key:generate
mysql -u root -e "CREATE DATABASE municipal_bill_auditor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate
npm install
composer dev        # web server, queue worker and Vite together
```

Set `DB_USERNAME` and `DB_PASSWORD` in `.env` to match your MySQL user.

### Demo data

```bash
php artisan db:seed --class=DemoSeeder
```

This adds a household, a managing agent with eight units, a body corporate with a tariff claim, and a staff member. Sign in as `household@example.com`, `agent@example.com`, `business@example.com` or `staff@example.com`, all with the password `password`. Every figure is illustrative.

### Reading bills with Claude

Set `ANTHROPIC_API_KEY` to read uploaded and forwarded bills automatically. The model is set in `config/auditor.php` (`ANTHROPIC_BILL_MODEL` overrides it), and the request uses structured outputs so the reply always has the same shape. Server-side fallbacks are on, so a request the model declines is retried on the API's default fallback model.

Without a key, every bill waits for its details to be typed in. The privacy notice says the bill file is sent to Anthropic for reading, so keep it accurate if you change provider.

### Background work

Bills are read on the queue, so production needs a worker:

```bash
php artisan queue:work
```

Deadline reminders go out daily at 07:00 South African time. Run the scheduler from cron:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

### Forwarded e-bills

Each account gets an address like `code@bills.example.com`. Point your email provider's inbound webhook at `POST /inbound/email` as a multipart form, with the recipient in `to` and the attachments as files, and send `INBOUND_EMAIL_SECRET` in the `X-Inbound-Secret` header. Set `INBOUND_EMAIL_DOMAIN` to your receiving domain.

### Tests

```bash
mysql -u root -e "CREATE DATABASE municipal_bill_auditor_test"
composer test            # Pint, then PHPUnit
npm run check            # lint and formatting
npm run types:check      # TypeScript
```

CI runs all of these on every pull request, against MySQL 8.

## Where things are

| Path                              | What it holds                                                                                          |
| --------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `app/Audit`                       | The checks, deadlines, letters and fees. Plain PHP with exact decimal money, and no Laravel.           |
| `app/Services`                    | Reading bills, running the checks, drafting and tracking disputes, and spotting credits on later bills |
| `app/Http/Controllers`            | The app screens, the staff tools, the public pages and the inbound email webhook                       |
| `app/Console/Commands`            | The daily reminder run                                                                                 |
| `resources/js/pages`              | The React screens                                                                                      |
| `resources/views/marketing`       | The public pages, rendered on the server for search engines                                            |
| `database/seeders/DemoSeeder.php` | The demo accounts                                                                                      |
