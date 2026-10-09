/**
 * Small, locale-independent formatters. We deliberately avoid Intl so the
 * output is identical in every browser: "R1 523.40", "25 Sep 2026".
 */

const MONTHS = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
] as const

/** Groups an integer string in threes with a normal space: "1234567" -> "1 234 567". */
function groupThousands(digits: string): string {
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
}

/**
 * Formats integer cents as rand: 152340 -> "R1 523.40", -1200 -> "-R12.00".
 * Non-integer input is rounded to the nearest cent first.
 */
export function formatRand(cents: number): string {
  const rounded = Math.round(cents)
  const negative = rounded < 0
  const abs = Math.abs(rounded)
  const rands = Math.floor(abs / 100)
  const remainder = abs % 100
  const text = `R${groupThousands(String(rands))}.${String(remainder).padStart(2, '0')}`
  return negative ? `-${text}` : text
}

/** Like formatRand but returns a fallback for null/undefined. */
export function formatRandOrDash(
  cents: number | null | undefined,
  fallback = 'Not known yet',
): string {
  return cents === null || cents === undefined ? fallback : formatRand(cents)
}

/**
 * Parses a rand amount typed by a person into integer cents, without
 * floating-point maths. Accepts "1523.40", "1 523,40", "R1,523.40", "12".
 * Returns null for empty, negative or invalid input (including more than
 * two decimals).
 */
export function parseRandToCents(input: string): number | null {
  let text = input.trim().replace(/^R\s*/i, '')
  // Spaces (including non-breaking and thin spaces) are thousands separators.
  text = text.replace(/[\s\u00a0\u202f']/g, '')
  if (text === '' || text.startsWith('-')) return null

  const lastDot = text.lastIndexOf('.')
  const lastComma = text.lastIndexOf(',')
  let integerPart = text
  let fractionPart = ''

  if (lastDot !== -1 && lastComma !== -1) {
    // Both present: the last one is the decimal separator.
    const decimalIndex = Math.max(lastDot, lastComma)
    const thousandsChar = decimalIndex === lastDot ? ',' : '.'
    integerPart = text.slice(0, decimalIndex).split(thousandsChar).join('')
    fractionPart = text.slice(decimalIndex + 1)
  } else if (lastComma !== -1) {
    const parts = text.split(',')
    const last = parts[parts.length - 1]
    if (parts.length === 2 && last.length <= 2) {
      // "1523,40" — comma as decimal separator
      integerPart = parts[0]
      fractionPart = last
    } else if (parts.slice(1).every((p) => p.length === 3)) {
      // "1,523" or "1,523,000" — commas as thousands separators
      integerPart = parts.join('')
    } else {
      return null
    }
  } else if (lastDot !== -1) {
    const parts = text.split('.')
    if (parts.length !== 2) return null
    integerPart = parts[0]
    fractionPart = parts[1]
  }

  if (integerPart === '') integerPart = '0'
  if (!/^\d+$/.test(integerPart)) return null
  if (fractionPart !== '' && !/^\d{1,2}$/.test(fractionPart)) return null

  const cents =
    Number.parseInt(integerPart, 10) * 100 +
    Number.parseInt(fractionPart.padEnd(2, '0') || '0', 10)
  return Number.isSafeInteger(cents) ? cents : null
}

/** Cents to a plain value for a form field: 152340 -> "1523.40". */
export function centsToRandInput(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return ''
  const abs = Math.abs(Math.round(cents))
  const text = `${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
  return cents < 0 ? `-${text}` : text
}

/** What `readDecimal` made of a number typed by a person. */
export type DecimalReading =
  | { kind: 'empty' }
  | { kind: 'number'; value: number }
  | { kind: 'invalid' }
  /**
   * A single comma followed by exactly three digits, like "19,330": it could be
   * a thousands separator (19330) or a decimal comma (19.33), so we don't guess.
   */
  | { kind: 'ambiguous'; asWhole: string; asDecimal: string }

/** "1", "123" then groups of exactly three digits: a valid thousands grouping. */
function isThousandsGrouping(groups: string[]): boolean {
  return /^\d{1,3}$/.test(groups[0]) && groups.slice(1).every((group) => /^\d{3}$/.test(group))
}

function countChar(text: string, char: string): number {
  return text.split(char).length - 1
}

/**
 * Reads a decimal number typed by a person (meter readings, usage).
 *
 * - Spaces are thousands separators: "19 330" is 19330.
 * - A dot is a decimal point: "1241.523" is 1241.523.
 * - A comma is a decimal comma when it can only be one: "12,5", "1203,500", "0,330".
 * - With both a comma and a dot, the last one is the decimal separator: "1,234.5", "1.234,5".
 * - Several commas are thousands separators: "1,234,567".
 * - A single comma followed by exactly three digits ("19,330") is ambiguous
 *   and is reported as such rather than silently read 1000 times too small.
 */
export function readDecimal(input: string): DecimalReading {
  let text = input.trim().replace(/[\s\u00a0\u202f']/g, '')
  if (text === '') return { kind: 'empty' }
  const invalid: DecimalReading = { kind: 'invalid' }

  let sign = ''
  if (text.startsWith('-')) {
    sign = '-'
    text = text.slice(1)
  }
  if (!/^[\d.,]+$/.test(text)) return invalid

  const dots = countChar(text, '.')
  const commas = countChar(text, ',')
  let whole = text
  let fraction = ''

  if (dots > 0 && commas > 0) {
    const decimalIndex = Math.max(text.lastIndexOf('.'), text.lastIndexOf(','))
    const decimalChar = text[decimalIndex]
    const thousandsChar = decimalChar === '.' ? ',' : '.'
    if (countChar(text, decimalChar) !== 1) return invalid
    const groups = text.slice(0, decimalIndex).split(thousandsChar)
    if (!isThousandsGrouping(groups)) return invalid
    whole = groups.join('')
    fraction = text.slice(decimalIndex + 1)
  } else if (commas > 1) {
    const groups = text.split(',')
    if (!isThousandsGrouping(groups)) return invalid
    whole = groups.join('')
  } else if (commas === 1) {
    const [head, tail] = text.split(',')
    if (/^[1-9]\d{0,2}$/.test(head) && /^\d{3}$/.test(tail)) {
      return {
        kind: 'ambiguous',
        asWhole: `${sign}${String(Number(head + tail))}`,
        asDecimal: `${sign}${String(Number(`${head}.${tail}`))}`,
      }
    }
    whole = head
    fraction = tail
  } else if (dots > 1) {
    return invalid
  } else if (dots === 1) {
    const parts = text.split('.')
    whole = parts[0]
    fraction = parts[1]
  }

  if (whole === '' && fraction === '') return invalid
  if (!/^\d*$/.test(whole) || !/^\d*$/.test(fraction)) return invalid
  const value = Number(`${sign}${whole || '0'}.${fraction || '0'}`)
  return Number.isFinite(value) ? { kind: 'number', value: value === 0 ? 0 : value } : invalid
}

/**
 * Parses a decimal number typed by a person (meter readings, usage); see
 * `readDecimal` for the rules. Returns null when empty, NaN when invalid or
 * ambiguous.
 */
export function parseDecimal(input: string): number | null {
  const reading = readDecimal(input)
  if (reading.kind === 'empty') return null
  if (reading.kind === 'number') return reading.value
  return Number.NaN
}

/** Formats a reading or usage number without trailing zeros: 38 -> "38", 12.25 -> "12.25". */
export function formatNumber(value: number): string {
  const rounded = Math.round(value * 1000) / 1000
  const [whole, fraction] = String(Math.abs(rounded)).split('.')
  const text = groupThousands(whole) + (fraction ? `.${fraction}` : '')
  return rounded < 0 ? `-${text}` : text
}

const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/

/** Parses `YYYY-MM-DD` or an ISO timestamp into a local Date. */
export function toDate(value: string): Date | null {
  const match = DATE_ONLY.exec(value)
  if (match) {
    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  }
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

/**
 * "2026-09-25" -> "25 Sep 2026". Timestamps are shown in the viewer's
 * local time zone. Returns the fallback for null/empty/invalid input.
 */
export function formatDate(
  value: string | null | undefined,
  fallback = 'Not known yet',
): string {
  if (!value) return fallback
  const match = DATE_ONLY.exec(value)
  if (match) {
    const month = Number(match[2])
    if (month < 1 || month > 12) return fallback
    return `${Number(match[3])} ${MONTHS[month - 1]} ${match[1]}`
  }
  const date = toDate(value)
  if (!date) return fallback
  return `${date.getDate()} ${MONTHS[date.getMonth()]} ${date.getFullYear()}`
}

/** "2026-09-01T06:00:00Z" -> "1 Sep 2026, 08:00" in the viewer's local time. */
export function formatDateTime(
  value: string | null | undefined,
  fallback = 'Not known yet',
): string {
  if (!value) return fallback
  const date = toDate(value)
  if (!date) return fallback
  const hh = String(date.getHours()).padStart(2, '0')
  const mm = String(date.getMinutes()).padStart(2, '0')
  return `${formatDate(value)}, ${hh}:${mm}`
}

/** A Date as `YYYY-MM-DD` in local time. */
export function toDateString(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

/**
 * Whole days from `today` to the given date (negative when in the past).
 * Accepts `YYYY-MM-DD` or a timestamp (converted to its local date).
 */
export function daysUntil(value: string, today: Date): number | null {
  const date = toDate(value)
  if (!date) return null
  const start = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate())
  const end = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate())
  return Math.round((end - start) / 86_400_000)
}

/** A short, friendly "when" for deadlines. */
export function describeDue(value: string, today: Date): string {
  const days = daysUntil(value, today)
  if (days === null) return ''
  if (days < -1) return `${-days} days overdue`
  if (days === -1) return '1 day overdue'
  if (days === 0) return 'Due today'
  if (days === 1) return 'Due tomorrow'
  return `Due in ${days} days`
}

/** 0.8 -> "80%" */
export function formatPercent(fraction: number): string {
  const clamped = Math.min(1, Math.max(0, fraction))
  return `${Math.round(clamped * 100)}%`
}

/** 1536 -> "1.5 KB" */
export function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} bytes`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/** A `datetime-local` input value ("2026-09-01T08:00") as an ISO UTC string. */
export function localDateTimeToIso(value: string): string | null {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date.toISOString()
}
