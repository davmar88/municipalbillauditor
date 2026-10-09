import { makeBill } from '@/test/fixtures';

import type { LineItem } from '@/api';

import {
  billToDraft,
  emptyBillDraft,
  emptyLineItemDraft,
  lineItemErrorsFromServer,
  lineItemToDraft,
  updateLineItemDraft,
  usageHint,
  validateBillDraft,
  validateLineItems,
  type LineItemDraft,
} from '../billDraft';

const serverLine = (overrides: Partial<LineItem> = {}): LineItem => ({
  id: 10,
  service: 'water',
  description: 'Water consumption',
  tariff_category: null,
  reading_type: 'actual',
  previous_reading: 32270,
  current_reading: 33620,
  consumption: 1350,
  unit: 'kl',
  amount_cents: 152340,
  ...overrides,
});

describe('validateLineItems', () => {
  it('converts typed rand and readings into API values', () => {
    const draft = {
      ...emptyLineItemDraft(),
      service: 'water' as const,
      description: ' Water consumption ',
      reading_type: 'estimated' as const,
      previous_reading: '1 203',
      current_reading: '1241,5',
      unit: 'kl' as const,
      amount: 'R1 523.40',
    };
    const result = validateLineItems([draft]);
    expect(result.valid).toBe(true);
    expect(result.items).toEqual([
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
    ]);
  });

  it('requires a service and an amount and checks numbers', () => {
    const result = validateLineItems([{ ...emptyLineItemDraft(), previous_reading: 'abc' }]);
    expect(result.valid).toBe(false);
    expect(result.errors[0]).toEqual({
      service: 'Choose which service this line is for.',
      amount: 'Enter the amount for this line, like 1523.40.',
      previous_reading: 'Enter a number, like 1203.5.',
    });
  });

  it('asks which number was meant for a reading typed with a thousands comma', () => {
    const result = validateLineItems([
      {
        ...emptyLineItemDraft(),
        service: 'water',
        amount: '100',
        previous_reading: '12,345',
        current_reading: '12383',
      },
    ]);
    expect(result.valid).toBe(false);
    expect(result.errors[0]).toEqual({
      previous_reading: 'Is this 12 345 or 12.345? Please type it as 12345 or 12.345.',
    });
  });
});

describe('updateLineItemDraft (usage and readings)', () => {
  it('works out the usage again when a reading of a saved bill changes', () => {
    // The usage of 1350 came from the old readings; sending it with new readings kept a false spike.
    const draft = lineItemToDraft(serverLine());
    const edited = updateLineItemDraft(draft, { current_reading: '32680' });

    expect(edited.consumption).toBe('410');
    expect(validateLineItems([edited]).items[0]).toMatchObject({
      previous_reading: 32270,
      current_reading: 32680,
      consumption: 410,
    });
  });

  it('clears the usage when the readings no longer give one', () => {
    const draft = lineItemToDraft(serverLine());
    const edited = updateLineItemDraft(draft, { current_reading: '' });
    expect(edited.consumption).toBe('');
    expect(validateLineItems([edited]).items[0].consumption).toBeNull();
  });

  it('fills in the usage as readings are typed on a new line', () => {
    let draft: LineItemDraft = { ...emptyLineItemDraft(), service: 'water', unit: 'kl', amount: '100' };
    draft = updateLineItemDraft(draft, { previous_reading: '1203' });
    expect(draft.consumption).toBe('');
    draft = updateLineItemDraft(draft, { current_reading: '1241,5' });
    expect(draft.consumption).toBe('38.5');
    expect(usageHint(draft)).toBe(
      'Reads as 38.5 kl, worked out from your two readings. Change it if your bill shows a different usage.',
    );
  });

  it('avoids floating-point noise in worked-out usage', () => {
    const draft = lineItemToDraft(serverLine({ previous_reading: 32270.1, current_reading: 32270.1, consumption: 0 }));
    expect(updateLineItemDraft(draft, { current_reading: '32680' }).consumption).toBe('409.9');
  });

  it('keeps a usage you typed yourself, and points out when the readings disagree', () => {
    let draft: LineItemDraft = { ...emptyLineItemDraft(), service: 'water', unit: 'kl' };
    draft = updateLineItemDraft(draft, { consumption: '40' });
    draft = updateLineItemDraft(draft, { previous_reading: '1203' });
    draft = updateLineItemDraft(draft, { current_reading: '1241' });

    expect(draft.consumption).toBe('40');
    expect(usageHint(draft)).toBe(
      'Reads as 40 kl. Your two readings work out to 38 kl, so check this against your bill.',
    );
  });

  it('follows the readings again once a typed usage is cleared', () => {
    let draft = updateLineItemDraft(emptyLineItemDraft(), { consumption: '40' });
    draft = updateLineItemDraft(draft, { consumption: '' });
    draft = updateLineItemDraft(draft, { previous_reading: '10', current_reading: '25' });
    expect(draft.consumption).toBe('15');
  });

  it('keeps a saved usage that never matched the readings, such as a meter with a multiplier', () => {
    const draft = lineItemToDraft(serverLine({ previous_reading: 100, current_reading: 110, consumption: 400 }));
    expect(draft.consumption_auto).toBe(false);
    expect(updateLineItemDraft(draft, { current_reading: '111' }).consumption).toBe('400');
  });

  it('keeps a saved usage that has no readings', () => {
    const draft = lineItemToDraft(serverLine({ previous_reading: null, current_reading: null, consumption: 38 }));
    expect(updateLineItemDraft(draft, { previous_reading: '1203' }).consumption).toBe('38');
  });

  it('explains an empty usage field', () => {
    expect(usageHint(emptyLineItemDraft())).toBe("Leave empty and we'll work it out from the two readings.");
  });
});

describe('validateBillDraft', () => {
  it('parses dates and the total, leaving empty fields as null', () => {
    const result = validateBillDraft(
      { ...emptyBillDraft(), bill_date: '25/09/2026', total: '4123.50' },
      { requireLineItems: false },
    );
    expect(result.valid).toBe(true);
    expect(result.values).toEqual({
      bill_date: '2026-09-25',
      period_start: null,
      period_end: null,
      due_date: null,
      total_cents: 412350,
      line_items: [],
    });
  });

  it('flags unreadable dates, a backwards period and missing line items', () => {
    const result = validateBillDraft(
      {
        ...emptyBillDraft(),
        bill_date: '31/02/2026',
        period_start: '20/09/2026',
        period_end: '19/08/2026',
        total: 'lots',
      },
      { requireLineItems: true },
    );
    expect(result.valid).toBe(false);
    expect(Object.keys(result.errors.fields).sort()).toEqual(['bill_date', 'line_items', 'period_end', 'total_cents']);
  });

  it('round-trips an existing bill', () => {
    const draft = billToDraft(makeBill());
    expect(draft.bill_date).toBe('25 Sep 2026');
    expect(draft.total).toBe('4123.50');
    const result = validateBillDraft(draft, { requireLineItems: true });
    expect(result.valid).toBe(true);
    expect(result.values.bill_date).toBe('2026-09-25');
    expect(result.values.line_items[0]).toMatchObject({
      service: 'water',
      amount_cents: 152340,
      consumption: 38,
      unit: 'kl',
    });
  });
});

describe('lineItemErrorsFromServer', () => {
  it('maps nested 422 keys onto rows', () => {
    const rows = lineItemErrorsFromServer(
      {
        'line_items.1.amount_cents': 'The amount must be at least 0.',
        'line_items.0.service': 'Invalid service.',
        email: 'x',
      },
      2,
    );
    expect(rows).toEqual([{ service: 'Invalid service.' }, { amount: 'The amount must be at least 0.' }]);
  });
});
