import { centsToRandInput, formatRand, formatRandOrDash, parseRandToCents } from '../money';

describe('formatRand', () => {
  it.each([
    [152340, 'R1 523.40'],
    [0, 'R0.00'],
    [1234567890, 'R12 345 678.90'],
    [-1200, '-R12.00'],
    [5, 'R0.05'],
    [99, 'R0.99'],
    [100000, 'R1 000.00'],
    [99999, 'R999.99'],
    [-5, '-R0.05'],
  ])('formats %p cents as %p', (cents, expected) => {
    expect(formatRand(cents)).toBe(expected);
  });

  it('uses a normal space, not a non-breaking one', () => {
    expect(formatRand(152340)).toContain(' ');
    expect(formatRand(152340)).not.toMatch(/[  ]/);
  });

  it('formats negative zero as R0.00', () => {
    expect(formatRand(-0)).toBe('R0.00');
  });

  it('shows a fallback for missing amounts', () => {
    expect(formatRandOrDash(null)).toBe('—');
    expect(formatRandOrDash(undefined, 'Not entered')).toBe('Not entered');
    expect(formatRandOrDash(98000)).toBe('R980.00');
  });
});

describe('parseRandToCents', () => {
  it.each([
    ['1523.40', 152340],
    ['1523.4', 152340],
    ['1523', 152300],
    ['R1 523.40', 152340],
    ['r 1523.40', 152340],
    ['1 523,40', 152340],
    ['1523,40', 152340],
    ['1,523.40', 152340],
    ['1,234,567', 123456700],
    ['0.05', 5],
    ['.5', 50],
    ['0', 0],
    ['  12345678.90  ', 1234567890],
    ['1 523.40', 152340],
  ])('parses %p as %p cents', (input, expected) => {
    expect(parseRandToCents(input)).toBe(expected);
  });

  it.each(['', '   ', 'abc', '12.345', '1,523', '-12', '12.3.4', 'R', '.', '1e5'])('rejects %p', (input) => {
    expect(parseRandToCents(input)).toBeNull();
  });

  it('avoids floating point errors', () => {
    expect(parseRandToCents('0.29')).toBe(29);
    expect(parseRandToCents('1.15')).toBe(115);
    expect(parseRandToCents('4.35')).toBe(435);
  });

  it('round-trips with centsToRandInput', () => {
    for (const cents of [0, 5, 50, 152340, 1234567890]) {
      expect(parseRandToCents(centsToRandInput(cents))).toBe(cents);
    }
  });
});

describe('centsToRandInput', () => {
  it('produces an editable plain number', () => {
    expect(centsToRandInput(152340)).toBe('1523.40');
    expect(centsToRandInput(5)).toBe('0.05');
    expect(centsToRandInput(null)).toBe('');
  });
});
