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

| Customer | What they pay | Role in the business |
|---|---|---|
| Businesses and body corporates | Success fee, about 20% to 35% of the back-refund won plus the first 12 months of future savings from a fixed error | First revenue. Customers pay nothing unless they win. |
| Landlords and managing agents | Subscription per unit per month, about R30 to R80 | Steady, recurring revenue. They buy time saved and deadlines that aren't missed. |
| Households | Free bill checks, with an optional paid managed dispute (about R199 to R499) | Acquisition, trust and data on error patterns per metro. |

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

Early planning. No application code yet.
