# Audit rules

The audit engine runs every rule over one bill, using that property's other bills and outages as history. Rules must prefer **missing a problem over raising a false alarm**: a wrong flag costs a customer time with the municipality and burns trust. When data is missing or ambiguous, a rule either stays silent or emits a `low` finding.

Only `medium` and `high` findings count towards dashboards, deadlines and "potential overcharge" totals. `low` findings are shown as information.

"History" for a service means the property's other bills with `period_end` before this bill's `period_start` (or, when periods are missing, `bill_date` earlier than this bill's), most recent first. "Usage" means a line item's `consumption`.

Thresholds live in `backend/config/audit.php` so they can be tuned without code changes.

## `estimated_reading`
- **Fires when** a `water` or `electricity` line item has `reading_type = estimated`.
- **Severity**: `medium` when the estimated usage is more than 20% above the median of up to 6 previous **actual** readings for that service; otherwise `low`.
- **Confidence**: 0.9 (the bill itself says it is estimated).
- **Overcharge**: when there are at least 2 previous actual readings: `max(0, usage − median_actual) × (amount_cents / usage)`, rounded to cents. Otherwise `null`.
- **Evidence**: `service`, `consumption`, `median_actual_consumption` (or null), `actual_bills_compared`.
- **Why**: municipalities must base charges on actual readings where reasonably possible; repeated estimates are a common source of large catch-up bills.

## `consecutive_estimates`
- **Fires when** this bill and at least the 2 bills immediately before it all have `estimated` readings for the same service (3 in a row).
- **Severity**: `high`. **Confidence**: 0.95. **Overcharge**: `null`.
- **Evidence**: `service`, `consecutive_count`, `bill_ids`.
- Emitted once per service on the newest bill only.

## `tariff_mismatch`
- **Fires when** a line item's `tariff_category` text clearly names a customer class that conflicts with the property's `property_type`.
- Matching is case-insensitive keyword matching configured per class in `config/audit.php`, e.g. class `business` = [`commercial`, `business`, `industrial`]; class `residential` = [`residential`, `domestic`, `household`]; class `bulk` = [`bulk`, `sectional`, `body corporate`, `cluster`].
- Conflicts:
  - `residential`, `sectional_title`, `bulk_residential` properties billed on a `business` tariff → `high`, confidence 0.75.
  - `commercial` or `industrial` properties billed on a `residential` tariff → **no finding** (that error favours the customer; we don't flag it).
  - `sectional_title`/`bulk_residential` billed on a single-dwelling `residential` tariff with no `bulk` keyword → `low`, confidence 0.4 (it may or may not be wrong; depends on the metro's tariff structure).
- No finding when `tariff_category` is null/empty or matches no class.
- **Overcharge**: `null` (needs tariff tables, out of scope for v1).
- **Evidence**: `service`, `tariff_category`, `property_type`, `matched_keyword`.

## `outage_charge`
- **Fires when** a recorded outage for `water` or `electricity` overlaps this bill's `period_start..period_end` and the same service has a charge (`amount_cents > 0`).
- `outage_fraction` = overlapping outage time (merging overlapping outages) ÷ billing period length.
- Ignored when `outage_fraction < 0.1` (config).
- When `outage_fraction ≥ 0.95` (supply off for the whole period) and usage > 0 → `high`, confidence 0.85, overcharge = consumption-based amount (the full line amount).
- Otherwise, when there are at least 2 previous bills with usage for that service: expected usage = `median × (1 − outage_fraction)`; if usage > expected × 1.15 → `medium`, confidence 0.65, overcharge = `(usage − expected) × (amount_cents / usage)`.
- Otherwise (no history) → `low`, confidence 0.4, overcharge `null`.
- **Evidence**: `service`, `outage_days`, `period_days`, `outage_fraction`, `consumption`, `expected_consumption` (or null).
- Bills without `period_start`/`period_end` are skipped.

## `consumption_spike`
- **Needs** at least 3 previous bills with usage for the service. Compares against the median of up to 6 previous bills.
- **Fires when** usage > 2 × median **and** usage − median > the absolute floor (water 8 kl, electricity 250 kWh; config).
- **Severity**: `high` when usage > 3 × median, else `medium`. **Confidence**: 0.7 (a spike can be a real leak, which is still worth telling the customer about; the explanation must say so).
- Skipped for line items with `reading_type = estimated` (the `estimated_reading` rule covers those).
- **Overcharge**: `(usage − median) × (amount_cents / usage)`.
- **Evidence**: `service`, `consumption`, `median_consumption`, `bills_compared`, `ratio`.

## `arithmetic_mismatch`
- **Fires when** `total_cents` is set and differs from the sum of line item `amount_cents` by more than R5.00 (config).
- **Severity**: `low`, confidence 0.5. The total may legitimately include VAT lines or adjustments the customer didn't capture, so this is information only.
- **Overcharge**: `null`. **Evidence**: `total_cents`, `line_items_sum_cents`, `difference_cents`.

## Writing explanations
- Plain language, second person, no jargon, no certainty the evidence doesn't support ("may", "looks like").
- Say what the customer can check themselves (e.g. "read your meter and compare").
- Amounts in rand formatted like `R1 523.40`.
