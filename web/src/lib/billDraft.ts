/**
 * Form state for bills and line items, and its conversion to API input.
 * Form values are strings; money is typed in rand and sent as integer cents.
 */
import type { Bill, LineItem, LineItemInput, ReadingType, Service } from '../api'
import { centsToRandInput, formatNumber, parseDecimal, parseRandToCents, readDecimal } from './format'

export interface BillFieldsDraft {
  bill_date: string
  period_start: string
  period_end: string
  due_date: string
  /** Rand, as typed. */
  total: string
}

export interface LineItemDraft {
  /** Stable React key; never sent to the API. */
  key: string
  service: Service | ''
  description: string
  tariff_category: string
  reading_type: ReadingType
  previous_reading: string
  current_reading: string
  consumption: string
  unit: 'kl' | 'kwh' | ''
  /** Rand, as typed. */
  amount: string
}

let nextKey = 0
function newKey(): string {
  nextKey += 1
  return `line-${nextKey}`
}

export function emptyBillFields(): BillFieldsDraft {
  return { bill_date: '', period_start: '', period_end: '', due_date: '', total: '' }
}

export function billFieldsFromBill(bill: Bill): BillFieldsDraft {
  return {
    bill_date: bill.bill_date ?? '',
    period_start: bill.period_start ?? '',
    period_end: bill.period_end ?? '',
    due_date: bill.due_date ?? '',
    total: centsToRandInput(bill.total_cents),
  }
}

export function emptyLineItem(): LineItemDraft {
  return {
    key: newKey(),
    service: '',
    description: '',
    tariff_category: '',
    reading_type: 'unknown',
    previous_reading: '',
    current_reading: '',
    consumption: '',
    unit: '',
    amount: '',
  }
}

function numberToInput(value: number | null): string {
  return value === null ? '' : formatNumber(value).replace(/ /g, '')
}

/** Usage is compared to 3 decimals (litres on a kl meter, Wh on a kWh meter). */
function sameUsage(a: number, b: number): boolean {
  return Math.abs(a - b) < 0.0005
}

/** `current - previous` (rounded to 3 decimals) when both readings are valid numbers, else null. */
export function readingsDifference(previous: string, current: string): number | null {
  const p = parseDecimal(previous)
  const c = parseDecimal(current)
  if (p === null || c === null || Number.isNaN(p) || Number.isNaN(c)) return null
  return Math.round((c - p) * 1000) / 1000
}

/**
 * Usage typed in the form, compared with the readings: `blank` (the server
 * works it out), `matches` the readings, `differs` from them, or `unchecked`
 * when there aren't two valid readings to compare with.
 */
export function usageAgainstReadings(
  draft: Pick<LineItemDraft, 'previous_reading' | 'current_reading' | 'consumption'>,
): 'blank' | 'matches' | 'differs' | 'unchecked' {
  const usage = parseDecimal(draft.consumption)
  if (usage === null) return 'blank'
  const difference = readingsDifference(draft.previous_reading, draft.current_reading)
  if (Number.isNaN(usage) || difference === null) return 'unchecked'
  return sameUsage(usage, difference) ? 'matches' : 'differs'
}

/**
 * Loads a saved line item into the form. When the saved usage is just
 * `current - previous` (usually because the server worked it out), the usage
 * field is left blank so the server works it out again from whatever readings
 * are saved next. Otherwise a corrected reading would be saved with the old usage.
 */
export function lineItemToDraft(item: LineItem): LineItemDraft {
  const derived =
    item.consumption !== null &&
    item.previous_reading !== null &&
    item.current_reading !== null &&
    sameUsage(item.consumption, item.current_reading - item.previous_reading)
  return {
    key: newKey(),
    service: item.service,
    description: item.description ?? '',
    tariff_category: item.tariff_category ?? '',
    reading_type: item.reading_type,
    previous_reading: numberToInput(item.previous_reading),
    current_reading: numberToInput(item.current_reading),
    consumption: derived ? '' : numberToInput(item.consumption),
    unit: item.unit ?? '',
    amount: centsToRandInput(item.amount_cents),
  }
}

/**
 * Changes a meter reading. If the usage field only held the difference between
 * the old readings, it is cleared so the usage follows the new readings
 * instead of keeping a number that no longer matches them.
 */
export function withReading(
  draft: LineItemDraft,
  field: 'previous_reading' | 'current_reading',
  value: string,
): LineItemDraft {
  const next = { ...draft, [field]: value }
  if (usageAgainstReadings(draft) === 'matches') next.consumption = ''
  return next
}

/** The usual unit for a service, used to pre-fill the unit when the service changes. */
export function defaultUnit(service: Service | ''): LineItemDraft['unit'] {
  if (service === 'water') return 'kl'
  if (service === 'electricity') return 'kwh'
  return ''
}

export interface Converted<T> {
  value: T
  /** Keys match the API's 422 keys, e.g. `line_items.0.amount_cents`. */
  errors: Record<string, string>
}

const NOT_A_NUMBER = 'Please enter a number, like 1203 or 1203.5.'

export function lineItemsToInput(drafts: LineItemDraft[]): Converted<LineItemInput[]> {
  const errors: Record<string, string> = {}
  const value: LineItemInput[] = []

  drafts.forEach((draft, index) => {
    const prefix = `line_items.${index}`
    if (!draft.service) errors[`${prefix}.service`] = 'Please choose what this charge is for.'

    let amountCents = 0
    if (draft.amount.trim() === '') {
      errors[`${prefix}.amount_cents`] = 'Please enter the amount, for example 1523.40.'
    } else {
      const parsed = parseRandToCents(draft.amount)
      if (parsed === null) {
        errors[`${prefix}.amount_cents`] = 'Please enter an amount in rand, for example 1523.40.'
      } else {
        amountCents = parsed
      }
    }

    const numbers = {
      previous_reading: readDecimal(draft.previous_reading),
      current_reading: readDecimal(draft.current_reading),
      consumption: readDecimal(draft.consumption),
    }
    for (const [field, reading] of Object.entries(numbers)) {
      if (reading.kind === 'invalid') errors[`${prefix}.${field}`] = NOT_A_NUMBER
      if (reading.kind === 'ambiguous') {
        errors[`${prefix}.${field}`] =
          `Did you mean ${reading.asWhole} or ${reading.asDecimal}? Please type it without the comma, ` +
          `like ${reading.asWhole}, or with a dot for decimals, like ${reading.asDecimal}.`
      }
    }
    const valueOf = (reading: (typeof numbers)[keyof typeof numbers]) =>
      reading.kind === 'number' ? reading.value : null

    value.push({
      service: (draft.service || 'other') as Service,
      description: draft.description.trim() || null,
      tariff_category: draft.tariff_category.trim() || null,
      reading_type: draft.reading_type,
      previous_reading: valueOf(numbers.previous_reading),
      current_reading: valueOf(numbers.current_reading),
      consumption: valueOf(numbers.consumption),
      unit: draft.unit || null,
      amount_cents: amountCents,
    })
  })

  return { value, errors }
}

export interface BillFieldsInput {
  bill_date: string | null
  period_start: string | null
  period_end: string | null
  due_date: string | null
  total_cents: number | null
}

export function billFieldsToInput(draft: BillFieldsDraft): Converted<BillFieldsInput> {
  const errors: Record<string, string> = {}
  let totalCents: number | null = null
  if (draft.total.trim() !== '') {
    totalCents = parseRandToCents(draft.total)
    if (totalCents === null) {
      errors.total_cents = 'Please enter an amount in rand, for example 4123.50.'
    }
  }
  if (draft.period_start && draft.period_end && draft.period_end < draft.period_start) {
    errors.period_end = 'The period should end after it starts.'
  }
  return {
    value: {
      bill_date: draft.bill_date || null,
      period_start: draft.period_start || null,
      period_end: draft.period_end || null,
      due_date: draft.due_date || null,
      total_cents: totalCents,
    },
    errors,
  }
}

/** Sum of the amounts that parse, in cents. */
export function sumDraftAmounts(drafts: LineItemDraft[]): number {
  return drafts.reduce((sum, draft) => sum + (parseRandToCents(draft.amount) ?? 0), 0)
}
