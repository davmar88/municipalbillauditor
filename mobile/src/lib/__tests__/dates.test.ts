import {
  daysUntil,
  describeDue,
  formatDate,
  formatDateTime,
  isOverdue,
  localDateTimeToIso,
  parseDateInput,
  parseTimeInput,
  timeOf,
  todayIso,
} from '../dates';

describe('formatDate', () => {
  it('formats plain dates as "25 Sep 2026"', () => {
    expect(formatDate('2026-09-25')).toBe('25 Sep 2026');
    expect(formatDate('2026-01-01')).toBe('1 Jan 2026');
    expect(formatDate('2026-12-31')).toBe('31 Dec 2026');
  });

  it('does not shift plain dates across time zones', () => {
    // A plain date must never be parsed as UTC midnight and shown as the previous day.
    expect(formatDate('2026-03-01')).toBe('1 Mar 2026');
  });

  it('formats timestamps in local time', () => {
    const local = new Date(2026, 10, 8, 10, 0, 0);
    expect(formatDate(local.toISOString())).toBe('8 Nov 2026');
    expect(formatDate('2026-10-09T05:17:14.000000Z')).toMatch(/^\d{1,2} (Oct) 2026$/);
  });

  it('returns the fallback for empty or invalid values', () => {
    expect(formatDate(null)).toBe('—');
    expect(formatDate(undefined, 'Not set')).toBe('Not set');
    expect(formatDate('2026-02-30')).toBe('—');
    expect(formatDate('not a date')).toBe('—');
  });
});

describe('formatDateTime', () => {
  it('adds a 24-hour local time', () => {
    const local = new Date(2026, 8, 1, 8, 5, 0);
    expect(formatDateTime(local.toISOString())).toBe('1 Sep 2026, 08:05');
  });
});

describe('deadlines', () => {
  const today = '2026-10-09';

  it('counts days until a due date', () => {
    expect(daysUntil('2026-10-09', today)).toBe(0);
    expect(daysUntil('2026-10-25', today)).toBe(16);
    expect(daysUntil('2026-10-01', today)).toBe(-8);
  });

  it('knows when something is overdue', () => {
    expect(isOverdue('2026-10-08', today)).toBe(true);
    expect(isOverdue('2026-10-09', today)).toBe(false);
    expect(isOverdue('2026-10-10', today)).toBe(false);
  });

  it('describes due dates in plain words', () => {
    expect(describeDue('2026-10-09', today)).toBe('Due today');
    expect(describeDue('2026-10-10', today)).toBe('Due tomorrow');
    expect(describeDue('2026-10-12', today)).toBe('Due in 3 days');
    expect(describeDue('2026-10-08', today)).toBe('1 day overdue');
    expect(describeDue('2026-10-01', today)).toBe('8 days overdue');
  });

  it('uses the local calendar date for today', () => {
    expect(todayIso(new Date(2026, 0, 5, 23, 59))).toBe('2026-01-05');
  });
});

describe('parseDateInput', () => {
  it.each([
    ['2026-09-25', '2026-09-25'],
    ['25/09/2026', '2026-09-25'],
    ['25-09-2026', '2026-09-25'],
    ['5/9/2026', '2026-09-05'],
    ['25 Sep 2026', '2026-09-25'],
    ['25 sept 2026', '2026-09-25'],
    ['25 September 2026', '2026-09-25'],
    ['  1 jan 2027 ', '2027-01-01'],
  ])('reads %p as %p', (input, expected) => {
    expect(parseDateInput(input)).toBe(expected);
  });

  it.each(['', '31/02/2026', '2026-13-01', '25 Sepx 2026', 'tomorrow', '25/09/26'])('rejects %p', (input) => {
    expect(parseDateInput(input)).toBeNull();
  });

  it('round-trips with formatDate', () => {
    expect(parseDateInput(formatDate('2026-09-25'))).toBe('2026-09-25');
  });
});

describe('times', () => {
  it('parses common time formats', () => {
    expect(parseTimeInput('08:00')).toBe(480);
    expect(parseTimeInput('8:30')).toBe(510);
    expect(parseTimeInput('18h00')).toBe(1080);
    expect(parseTimeInput('0630')).toBe(390);
    expect(parseTimeInput('7')).toBe(420);
    expect(parseTimeInput('24:00')).toBeNull();
    expect(parseTimeInput('12:60')).toBeNull();
    expect(parseTimeInput('')).toBeNull();
  });

  it('combines a local date and time into an ISO timestamp', () => {
    const iso = localDateTimeToIso('2026-09-01', 6 * 60);
    expect(iso).toBe(new Date(2026, 8, 1, 6, 0).toISOString());
    expect(timeOf(iso!)).toBe('06:00');
  });
});
