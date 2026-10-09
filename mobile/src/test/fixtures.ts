import type { Bill, Dispute, Finding, Metro, Property, User } from '@/api';

export function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    name: 'Thandi M',
    email: 'thandi@example.com',
    popia_consent_version: '2026-10-v1',
    popia_consented_at: '2026-10-09T05:17:14.000000Z',
    ai_extraction_consent: false,
    created_at: '2026-10-09T05:17:14.000000Z',
    ...overrides,
  };
}

export function makeFinding(overrides: Partial<Finding> = {}): Finding {
  return {
    id: 7,
    bill_id: 5,
    rule: 'consumption_spike',
    severity: 'medium',
    confidence: 0.8,
    title: 'Water use is 3.1 times your usual',
    explanation: 'Your water use looks much higher than usual. It may be a leak, or the reading may be wrong.',
    estimated_overcharge_cents: 98000,
    evidence: { consumption: 38.0, median_consumption: 12.2, bills_compared: 5 },
    status: 'open',
    created_at: '2026-10-09T05:17:14.000000Z',
    ...overrides,
  };
}

export function makeProperty(overrides: Partial<Property> = {}): Property {
  return {
    id: 3,
    nickname: 'Sunset Court',
    metro: 'johannesburg',
    account_number: '5501234567',
    account_number_masked: '••••4567',
    address: '12 Example Rd, Melville',
    property_type: 'sectional_title',
    bills_count: 1,
    open_findings_count: 2,
    created_at: '2026-10-09T05:17:14.000000Z',
    ...overrides,
  };
}

export function makeBill(overrides: Partial<Bill> = {}): Bill {
  return {
    id: 5,
    property_id: 3,
    bill_date: '2026-09-25',
    period_start: '2026-08-20',
    period_end: '2026-09-19',
    due_date: '2026-10-15',
    total_cents: 412350,
    status: 'audited',
    extraction_source: 'manual',
    has_file: false,
    file_mime: null,
    dispute_deadline: '2026-10-25',
    findings_summary: { open_count: 1, high_count: 0, potential_overcharge_cents: 98000 },
    line_items: [
      {
        id: 10,
        service: 'water',
        description: 'Water consumption',
        tariff_category: 'Residential',
        reading_type: 'actual',
        previous_reading: 1203,
        current_reading: 1241,
        consumption: 38,
        unit: 'kl',
        amount_cents: 152340,
      },
    ],
    findings: [makeFinding()],
    created_at: '2026-10-09T05:17:14.000000Z',
    ...overrides,
  };
}

export function makeMetro(overrides: Partial<Metro> = {}): Metro {
  return {
    code: 'johannesburg',
    name: 'City of Johannesburg Metropolitan Municipality',
    dispute_window_days: 30,
    response_wait_days: 30,
    dispute_channels: [
      { type: 'statement', label: 'Billing query channel', value: 'Use the query email on your statement.' },
    ],
    escalation_steps: [
      { level: 0, name: 'Billing query', description: 'Written dispute to the billing department.' },
      { level: 1, name: 'Senior revenue official', description: 'Ask for a senior official to review it.' },
    ],
    verified: false,
    ...overrides,
  };
}

export function makeDispute(overrides: Partial<Dispute> = {}): Dispute {
  return {
    id: 4,
    bill_id: 5,
    property: { id: 3, nickname: 'Sunset Court', metro: 'johannesburg' },
    bill: { id: 5, bill_date: '2026-09-25' },
    status: 'draft',
    finding_ids: [7],
    amount_disputed_cents: 98000,
    letter_subject: 'Dispute of municipal account 5501234567: bill dated 25 September 2026',
    letter_body: 'Dear Sir or Madam, ...',
    channel: null,
    municipality_reference: null,
    lodge_deadline: '2026-10-25',
    submitted_at: null,
    response_due_at: null,
    escalation_level: 0,
    next_step: null,
    outcome_amount_cents: null,
    resolved_at: null,
    events: [{ id: 1, type: 'created', note: null, occurred_at: '2026-10-09T05:17:14.000000Z' }],
    created_at: '2026-10-09T05:17:14.000000Z',
    ...overrides,
  };
}
