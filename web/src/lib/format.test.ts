import { describe, expect, it } from 'vitest'
import {
  centsToRandInput,
  daysUntil,
  describeDue,
  formatDate,
  formatDateTime,
  formatNumber,
  formatPercent,
  formatRand,
  parseDecimal,
  parseRandToCents,
  readDecimal,
} from './format'

describe('formatRand', () => {
  it.each([
    [152340, 'R1 523.40'],
    [0, 'R0.00'],
    [1234567890, 'R12 345 678.90'],
    [-1200, '-R12.00'],
    [5, 'R0.05'],
    [99, 'R0.99'],
    [100, 'R1.00'],
    [100000, 'R1 000.00'],
    [99999999, 'R999 999.99'],
    [-123456789, '-R1 234 567.89'],
  ])('formats %i cents as %s', (cents, expected) => {
    expect(formatRand(cents)).toBe(expected)
  })

  it('uses a normal space (U+0020) as the thousands separator', () => {
    const text = formatRand(152340)
    expect(text.charCodeAt(2)).toBe(32)
    expect(text).not.toMatch(/[\u00a0\u202f]/)
  })

  it('never shows negative zero', () => {
    expect(formatRand(-0)).toBe('R0.00')
  })
})

describe('parseRandToCents', () => {
  it.each([
    ['1523.40', 152340],
    ['1523.4', 152340],
    ['1 523.40', 152340],
    ['1 523,40', 152340],
    ['1523,40', 152340],
    ['R1,523.40', 152340],
    ['R 12 345 678.90', 1234567890],
    ['1,234,567.89', 123456789],
    ['1.234.567,89', 123456789],
    ['1,523', 152300],
    ['12', 1200],
    ['0', 0],
    ['0.5', 50],
    ['.99', 99],
    ['12.', 1200],
    ['  42.10  ', 4210],
    ['r42', 4200],
    // Values that go wrong with floating-point maths
    ['19.99', 1999],
    ['0.29', 29],
    ['1.13', 113],
  ])('parses %j as %i cents', (input, expected) => {
    expect(parseRandToCents(input)).toBe(expected)
  })

  it.each(['', '   ', 'abc', '-5', '-R5.00', '1.234', '12.3.4', '1,2345', '1e5', 'R', '12,34,5'])(
    'rejects %j',
    (input) => {
      expect(parseRandToCents(input)).toBeNull()
    },
  )

  it('round-trips with centsToRandInput', () => {
    for (const cents of [0, 1, 99, 100, 152340, 1234567890]) {
      expect(parseRandToCents(centsToRandInput(cents))).toBe(cents)
    }
  })
})

describe('centsToRandInput', () => {
  it('formats cents for an input field without thousands separators', () => {
    expect(centsToRandInput(152340)).toBe('1523.40')
    expect(centsToRandInput(5)).toBe('0.05')
    expect(centsToRandInput(null)).toBe('')
  })
})

describe('formatDate', () => {
  it.each([
    ['2026-09-25', '25 Sep 2026'],
    ['2026-01-05', '5 Jan 2026'],
    ['2026-12-31', '31 Dec 2026'],
    ['2027-05-01', '1 May 2027'],
  ])('formats %s as %s', (input, expected) => {
    expect(formatDate(input)).toBe(expected)
  })

  it('formats timestamps as a local date', () => {
    expect(formatDate('2026-10-09T05:17:14.000000Z')).toBe('9 Oct 2026')
    // 23:30 UTC is already the next day in South Africa (UTC+2)
    expect(formatDate('2026-11-08T23:30:00.000000Z')).toBe('9 Nov 2026')
  })

  it('returns the fallback for missing or invalid values', () => {
    expect(formatDate(null)).toBe('Not known yet')
    expect(formatDate(undefined, '—')).toBe('—')
    expect(formatDate('not a date', '—')).toBe('—')
  })
})

describe('formatDateTime', () => {
  it('shows the local date and time', () => {
    expect(formatDateTime('2026-09-01T06:00:00.000000Z')).toBe('1 Sep 2026, 08:00')
  })
})

describe('daysUntil and describeDue', () => {
  const today = new Date(2026, 9, 9) // 9 Oct 2026, local time

  it('counts whole days', () => {
    expect(daysUntil('2026-10-09', today)).toBe(0)
    expect(daysUntil('2026-10-25', today)).toBe(16)
    expect(daysUntil('2026-10-01', today)).toBe(-8)
  })

  it('describes due dates in plain words', () => {
    expect(describeDue('2026-10-09', today)).toBe('Due today')
    expect(describeDue('2026-10-10', today)).toBe('Due tomorrow')
    expect(describeDue('2026-10-25', today)).toBe('Due in 16 days')
    expect(describeDue('2026-10-08', today)).toBe('1 day overdue')
    expect(describeDue('2026-10-01', today)).toBe('8 days overdue')
  })
})

describe('other formatters', () => {
  it('formats confidence as a percentage', () => {
    expect(formatPercent(0.8)).toBe('80%')
    expect(formatPercent(0.95)).toBe('95%')
    expect(formatPercent(1)).toBe('100%')
  })

  it('formats readings without trailing zeros', () => {
    expect(formatNumber(38)).toBe('38')
    expect(formatNumber(12.25)).toBe('12.25')
    expect(formatNumber(1241)).toBe('1 241')
  })

  it('parses decimals with a comma or a dot', () => {
    expect(parseDecimal('12,5')).toBe(12.5)
    expect(parseDecimal('1203')).toBe(1203)
    expect(parseDecimal('')).toBeNull()
    expect(parseDecimal('abc')).toBeNaN()
  })

  it.each([
    ['19 330', 19330],
    ['1241.523', 1241.523],
    ['1203,500', 1203.5],
    ['1241,5', 1241.5],
    ['0,330', 0.33],
    [',5', 0.5],
    ['12.', 12],
    ['1,234.5', 1234.5],
    ['1.234,5', 1234.5],
    ['1,234,567', 1234567],
    ['19,330.25', 19330.25],
    ['-12,5', -12.5],
  ])('reads %s as %d', (input, expected) => {
    expect(parseDecimal(input)).toBe(expected)
    expect(readDecimal(input)).toEqual({ kind: 'number', value: expected })
  })

  it.each(['1.2.3', '1,23,4', '12,34.5', '1.234.5,6', '1,2,3', '.', ',', '-', '12a', '1,234.5.6'])(
    'rejects %s',
    (input) => {
      expect(parseDecimal(input)).toBeNaN()
      expect(readDecimal(input)).toEqual({ kind: 'invalid' })
    },
  )

  it('does not guess when a comma could be a thousands separator or a decimal comma', () => {
    // "19,330" used to be read as 19.33, a thousand times too small.
    expect(parseDecimal('19,330')).toBeNaN()
    expect(readDecimal('19,330')).toEqual({ kind: 'ambiguous', asWhole: '19330', asDecimal: '19.33' })
    expect(readDecimal('1,500')).toEqual({ kind: 'ambiguous', asWhole: '1500', asDecimal: '1.5' })
    expect(readDecimal('-123,456')).toEqual({ kind: 'ambiguous', asWhole: '-123456', asDecimal: '-123.456' })
  })
})
