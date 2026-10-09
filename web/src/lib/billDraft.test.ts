import { describe, expect, it } from 'vitest'
import { makeBill, makeLineItem } from '../test/fixtures'
import {
  billFieldsFromBill,
  billFieldsToInput,
  emptyLineItem,
  lineItemsToInput,
  lineItemToDraft,
  readingsDifference,
  usageAgainstReadings,
  withReading,
} from './billDraft'

describe('line item drafts', () => {
  it('converts typed rand amounts to cents and blanks to null', () => {
    const draft = {
      ...emptyLineItem(),
      service: 'water' as const,
      description: ' Water consumption ',
      reading_type: 'estimated' as const,
      previous_reading: '1203',
      current_reading: '1241,5',
      unit: 'kl' as const,
      amount: '1 523,40',
    }
    const { value, errors } = lineItemsToInput([draft])
    expect(errors).toEqual({})
    expect(value).toEqual([
      {
        service: 'water',
        description: 'Water consumption',
        tariff_category: null,
        reading_type: 'estimated',
        previous_reading: 1203,
        current_reading: 1241.5,
        consumption: null,
        unit: 'kl',
        amount_cents: 152340,
      },
    ])
  })

  it('reports problems with the same keys the API uses', () => {
    const { errors } = lineItemsToInput([
      { ...emptyLineItem(), service: 'water', amount: '10' },
      { ...emptyLineItem(), amount: 'ten rand', consumption: 'lots' },
    ])
    expect(Object.keys(errors).sort()).toEqual([
      'line_items.1.amount_cents',
      'line_items.1.consumption',
      'line_items.1.service',
    ])
  })

  it('round-trips an existing line item', () => {
    // Usage that differs from the readings (say a meter multiplier) is kept as entered.
    const item = makeLineItem({ previous_reading: 1203, current_reading: 1241, consumption: 380 })
    const { value } = lineItemsToInput([lineItemToDraft(item)])
    const { id: _id, ...expected } = item
    expect(_id).toBe(10)
    expect(value[0]).toEqual(expected)
  })

  it('leaves usage blank when it is just the difference between the readings', () => {
    const draft = lineItemToDraft(makeLineItem({ previous_reading: 1203, current_reading: 1241.5, consumption: 38.5 }))
    expect(draft.consumption).toBe('')
    expect(lineItemsToInput([draft]).value[0]).toMatchObject({
      previous_reading: 1203,
      current_reading: 1241.5,
      consumption: null,
    })
  })

  it('keeps usage when there are no readings to work it out from', () => {
    const draft = lineItemToDraft(makeLineItem({ previous_reading: null, current_reading: null, consumption: 38 }))
    expect(draft.consumption).toBe('38')
  })

  it('sends the corrected reading without the old usage after a reading is fixed', () => {
    // Reviewer's case: 32270 -> 33620 saved, server worked out 1350 kWh; the real reading was 32680.
    const saved = makeLineItem({
      service: 'electricity',
      unit: 'kwh',
      reading_type: 'actual',
      previous_reading: 32270,
      current_reading: 33620,
      consumption: 1350,
    })
    const edited = withReading(lineItemToDraft(saved), 'current_reading', '32680')
    const { value, errors } = lineItemsToInput([edited])
    expect(errors).toEqual({})
    expect(value[0]).toMatchObject({ previous_reading: 32270, current_reading: 32680, consumption: null })
  })

  it('clears usage that matched the old readings when a reading changes', () => {
    const typed = { ...emptyLineItem(), previous_reading: '100', current_reading: '110', consumption: '10' }
    expect(usageAgainstReadings(typed)).toBe('matches')
    expect(withReading(typed, 'current_reading', '150').consumption).toBe('')
    expect(withReading(typed, 'previous_reading', '90').consumption).toBe('')
  })

  it("keeps usage the person typed that doesn't come from the readings", () => {
    const typed = { ...emptyLineItem(), previous_reading: '100', current_reading: '110', consumption: '100' }
    expect(usageAgainstReadings(typed)).toBe('differs')
    const next = withReading(typed, 'current_reading', '150')
    expect(next.consumption).toBe('100')
    expect(next.current_reading).toBe('150')
    expect(usageAgainstReadings({ ...typed, consumption: '' })).toBe('blank')
    expect(usageAgainstReadings({ ...typed, previous_reading: '' })).toBe('unchecked')
  })

  it('works out the difference between readings', () => {
    expect(readingsDifference('1203', '1241,5')).toBe(38.5)
    expect(readingsDifference('0.1', '0.3')).toBe(0.2)
    expect(readingsDifference('', '1241')).toBeNull()
    expect(readingsDifference('12x', '1241')).toBeNull()
  })

  it('asks instead of guessing when a reading has a comma before three digits', () => {
    const { errors } = lineItemsToInput([
      { ...emptyLineItem(), service: 'water', amount: '10', previous_reading: '19,330', current_reading: '19 400' },
    ])
    expect(errors).toEqual({
      'line_items.0.previous_reading':
        'Did you mean 19330 or 19.33? Please type it without the comma, like 19330, or with a dot for decimals, like 19.33.',
    })
  })
})

describe('bill field drafts', () => {
  it('converts the total to cents and blank dates to null', () => {
    const { value, errors } = billFieldsToInput({
      bill_date: '2026-09-25',
      period_start: '',
      period_end: '',
      due_date: '',
      total: 'R4 123.50',
    })
    expect(errors).toEqual({})
    expect(value).toEqual({
      bill_date: '2026-09-25',
      period_start: null,
      period_end: null,
      due_date: null,
      total_cents: 412350,
    })
  })

  it('round-trips a bill', () => {
    const bill = makeBill()
    expect(billFieldsToInput(billFieldsFromBill(bill)).value).toEqual({
      bill_date: bill.bill_date,
      period_start: bill.period_start,
      period_end: bill.period_end,
      due_date: bill.due_date,
      total_cents: bill.total_cents,
    })
  })

  it('rejects an invalid total and a period that ends before it starts', () => {
    const { errors } = billFieldsToInput({
      bill_date: '',
      period_start: '2026-09-19',
      period_end: '2026-08-20',
      due_date: '',
      total: '12.345',
    })
    expect(Object.keys(errors).sort()).toEqual(['period_end', 'total_cents'])
  })
})
