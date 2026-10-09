/**
 * Form state for bill details and line items. People type rand and readings as text;
 * this converts them into the API's cents and numbers, with friendly errors.
 */
import type { Bill, LineItem, LineItemInput, ReadingType, Service } from '@/api';

import { formatDate, parseDateInput } from './dates';
import type { FieldErrors } from './forms';
import { unitLabel, type UnitChoice } from './labels';
import { centsToRandInput, parseRandToCents } from './money';
import { decimalProblem, formatNumber, numberToInput, parseDecimalInput } from './numbers';

export interface LineItemDraft {
  key: string;
  service: Service | null;
  description: string;
  tariff_category: string;
  reading_type: ReadingType;
  previous_reading: string;
  current_reading: string;
  consumption: string;
  /**
   * True while the usage follows the readings: it is empty, was worked out from them, or came
   * from the server matching them. False once the person types a usage of their own.
   */
  consumption_auto: boolean;
  unit: UnitChoice;
  amount: string;
}

export type LineItemField = Exclude<keyof LineItemDraft, 'key' | 'consumption_auto'>;
export type LineItemPatch = Partial<Pick<LineItemDraft, LineItemField>>;
export type LineItemErrors = Partial<Record<LineItemField, string>>;

let keyCounter = 0;
function nextKey(): string {
  keyCounter += 1;
  return `line-${keyCounter}`;
}

export function emptyLineItemDraft(): LineItemDraft {
  return {
    key: nextKey(),
    service: null,
    description: '',
    tariff_category: '',
    reading_type: 'unknown',
    previous_reading: '',
    current_reading: '',
    consumption: '',
    consumption_auto: true,
    unit: 'none',
    amount: '',
  };
}

export function lineItemToDraft(item: LineItem): LineItemDraft {
  return {
    key: nextKey(),
    service: item.service,
    description: item.description ?? '',
    tariff_category: item.tariff_category ?? '',
    reading_type: item.reading_type,
    previous_reading: numberToInput(item.previous_reading),
    current_reading: numberToInput(item.current_reading),
    consumption: numberToInput(item.consumption),
    consumption_auto: item.consumption === null || sameNumber(item.consumption, readingsDifference(item)),
    unit: item.unit ?? 'none',
    amount: centsToRandInput(item.amount_cents),
  };
}

function readingsDifference(item: Pick<LineItem, 'previous_reading' | 'current_reading'>): number | null {
  if (item.previous_reading === null || item.current_reading === null) return null;
  if (item.current_reading < item.previous_reading) return null;
  // Rounded to 3 decimals so 32680 - 32270.1 gives 409.9, not 409.89999999999.
  return Math.round((item.current_reading - item.previous_reading) * 1000) / 1000;
}

function sameNumber(a: number, b: number | null): boolean {
  return b !== null && Math.abs(a - b) < 0.0005;
}

/** Usage worked out from two typed readings, or null when they don't give one. */
export function usageFromReadings(draft: Pick<LineItemDraft, 'previous_reading' | 'current_reading'>): number | null {
  const previous = parseDecimalInput(draft.previous_reading);
  const current = parseDecimalInput(draft.current_reading);
  if (!previous.valid || !current.valid) return null;
  return readingsDifference({ previous_reading: previous.value, current_reading: current.value });
}

/**
 * Applies one edit to a line. When a reading changes, a usage that follows the readings is
 * worked out again (or cleared), so an old usage is never saved alongside new readings.
 * A usage the person typed themselves is left alone.
 */
export function updateLineItemDraft(draft: LineItemDraft, patch: LineItemPatch): LineItemDraft {
  const next: LineItemDraft = { ...draft, ...patch };
  if (patch.consumption !== undefined) {
    next.consumption_auto = patch.consumption.trim() === '';
  } else if ((patch.previous_reading !== undefined || patch.current_reading !== undefined) && draft.consumption_auto) {
    next.consumption = numberToInput(usageFromReadings(next));
  }
  return next;
}

/** The note under the usage field: how we read it, where it came from, or what to check. */
export function usageHint(draft: LineItemDraft): string {
  const parsed = parseDecimalInput(draft.consumption);
  if (!parsed.valid) return decimalProblem(parsed) ?? '';
  if (parsed.value === null) return "Leave empty and we'll work it out from the two readings.";
  const unit = unitLabel(draft.unit === 'none' ? null : draft.unit);
  const amount = (value: number) => `${formatNumber(value)}${unit ? ` ${unit}` : ''}`;
  if (draft.consumption_auto) {
    return `Reads as ${amount(parsed.value)}, worked out from your two readings. Change it if your bill shows a different usage.`;
  }
  const fromReadings = usageFromReadings(draft);
  if (fromReadings !== null && !sameNumber(parsed.value, fromReadings)) {
    return `Reads as ${amount(parsed.value)}. Your two readings work out to ${amount(fromReadings)}, so check this against your bill.`;
  }
  return `Reads as ${amount(parsed.value)}`;
}

/** Whether readings make sense for this line (metered services, or the person already typed some). */
export function showsReadings(draft: LineItemDraft): boolean {
  return (
    draft.service === 'water' ||
    draft.service === 'electricity' ||
    draft.previous_reading !== '' ||
    draft.current_reading !== '' ||
    draft.consumption !== ''
  );
}

export function validateLineItems(drafts: LineItemDraft[]): {
  items: LineItemInput[];
  errors: LineItemErrors[];
  valid: boolean;
} {
  const items: LineItemInput[] = [];
  const errors: LineItemErrors[] = [];
  let valid = true;

  drafts.forEach((draft) => {
    const rowErrors: LineItemErrors = {};
    if (!draft.service) rowErrors.service = 'Choose which service this line is for.';

    const amount = parseRandToCents(draft.amount);
    if (draft.amount.trim() === '') rowErrors.amount = 'Enter the amount for this line, like 1523.40.';
    else if (amount === null) rowErrors.amount = 'Enter the amount in rand, like 1523.40.';

    const readings = {
      previous_reading: parseDecimalInput(draft.previous_reading),
      current_reading: parseDecimalInput(draft.current_reading),
      consumption: parseDecimalInput(draft.consumption),
    };
    (Object.keys(readings) as (keyof typeof readings)[]).forEach((field) => {
      const problem = decimalProblem(readings[field]);
      if (problem) rowErrors[field] = problem;
    });

    errors.push(rowErrors);
    if (Object.keys(rowErrors).length > 0 || !draft.service || amount === null) {
      valid = false;
      return;
    }

    const value = (parsed: (typeof readings)[keyof typeof readings]) => (parsed.valid ? parsed.value : null);
    items.push({
      service: draft.service,
      description: draft.description.trim() || null,
      tariff_category: draft.tariff_category.trim() || null,
      reading_type: draft.reading_type,
      previous_reading: value(readings.previous_reading),
      current_reading: value(readings.current_reading),
      consumption: value(readings.consumption),
      unit: draft.unit === 'none' ? null : draft.unit,
      amount_cents: amount,
    });
  });

  return { items, errors, valid };
}

const SERVER_FIELD_MAP: Record<string, LineItemField> = { amount_cents: 'amount' };

/** Maps 422 keys like "line_items.2.amount_cents" onto row errors. */
export function lineItemErrorsFromServer(fieldErrors: FieldErrors, rowCount: number): LineItemErrors[] {
  const rows: LineItemErrors[] = Array.from({ length: rowCount }, () => ({}));
  for (const [key, message] of Object.entries(fieldErrors)) {
    const match = /^line_items\.(\d+)\.(\w+)$/.exec(key);
    if (!match) continue;
    const index = Number(match[1]);
    const field = (SERVER_FIELD_MAP[match[2]] ?? match[2]) as LineItemField;
    if (rows[index]) rows[index][field] = message;
  }
  return rows;
}

// ---------------------------------------------------------------------------
// Bill-level fields
// ---------------------------------------------------------------------------

export interface BillDraft {
  bill_date: string;
  period_start: string;
  period_end: string;
  due_date: string;
  total: string;
  line_items: LineItemDraft[];
}

export const BILL_DATE_FIELDS = ['bill_date', 'period_start', 'period_end', 'due_date'] as const;
export type BillDateField = (typeof BILL_DATE_FIELDS)[number];

export function emptyBillDraft(): BillDraft {
  return { bill_date: '', period_start: '', period_end: '', due_date: '', total: '', line_items: [] };
}

export function billToDraft(bill: Bill): BillDraft {
  const date = (value: string | null) => (value ? formatDate(value, '') : '');
  return {
    bill_date: date(bill.bill_date),
    period_start: date(bill.period_start),
    period_end: date(bill.period_end),
    due_date: date(bill.due_date),
    total: centsToRandInput(bill.total_cents),
    line_items: bill.line_items.map(lineItemToDraft),
  };
}

export interface BillValues {
  bill_date: string | null;
  period_start: string | null;
  period_end: string | null;
  due_date: string | null;
  total_cents: number | null;
  line_items: LineItemInput[];
}

export interface BillDraftErrors {
  /** Keys use the API's names: bill_date, period_start, period_end, due_date, total_cents, line_items. */
  fields: FieldErrors;
  lineItems: LineItemErrors[];
}

const DATE_ERROR = 'Enter a date like 25/09/2026.';

export function validateBillDraft(
  draft: BillDraft,
  options: { requireLineItems: boolean },
): { values: BillValues; errors: BillDraftErrors; valid: boolean } {
  const fields: FieldErrors = {};
  const dates = {} as Record<BillDateField, string | null>;
  for (const field of BILL_DATE_FIELDS) {
    const text = draft[field].trim();
    const parsed = text === '' ? null : parseDateInput(text);
    if (text !== '' && parsed === null) fields[field] = DATE_ERROR;
    dates[field] = parsed;
  }
  if (dates.period_start && dates.period_end && dates.period_end < dates.period_start) {
    fields.period_end = "The end of the period can't be before the start.";
  }

  const total = draft.total.trim() === '' ? null : parseRandToCents(draft.total);
  if (draft.total.trim() !== '' && total === null) fields.total_cents = 'Enter the total in rand, like 4123.50.';

  const lines = validateLineItems(draft.line_items);
  if (options.requireLineItems && draft.line_items.length === 0) {
    fields.line_items = 'Add at least one line from your bill.';
  }

  const valid = Object.keys(fields).length === 0 && lines.valid;
  return {
    values: { ...dates, total_cents: total, line_items: lines.items },
    errors: { fields, lineItems: lines.errors },
    valid,
  };
}
