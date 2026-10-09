import { decimalProblem, formatNumber, numberReadBack, parseDecimalInput } from '../numbers';

describe('parseDecimalInput', () => {
  it.each([
    ['1203', 1203],
    ['1 203.5', 1203.5],
    ['1203,5', 1203.5],
    ['0,125', 0.125],
    ['12,345.6', 12345.6],
    ['1,234,567', 1234567],
    ['32 270', 32270],
  ])('reads %p as %p', (input, expected) => {
    expect(parseDecimalInput(input)).toEqual({ valid: true, value: expected });
  });

  it('treats empty input as no value', () => {
    expect(parseDecimalInput('  ')).toEqual({ valid: true, value: null });
  });

  it('refuses to guess whether a comma before three digits separates thousands or decimals', () => {
    // Read as decimals, a meter reading of 12,345 would quietly become 12.345.
    expect(parseDecimalInput('1,203')).toEqual({ valid: false, ambiguous: { thousands: 1203, decimal: 1.203 } });
    expect(parseDecimalInput('12,345')).toEqual({ valid: false, ambiguous: { thousands: 12345, decimal: 12.345 } });
  });

  it.each(['abc', '1.2.3', '12,34.5', '1,2345,678', '-5', '.', ','])('rejects %p', (input) => {
    expect(parseDecimalInput(input).valid).toBe(false);
  });
});

describe('decimalProblem', () => {
  it('asks which number was meant when a comma is ambiguous', () => {
    expect(decimalProblem(parseDecimalInput('12,345'))).toBe(
      'Is this 12 345 or 12.345? Please type it as 12345 or 12.345.',
    );
  });

  it('asks for a number otherwise, and says nothing for valid input', () => {
    expect(decimalProblem(parseDecimalInput('abc'))).toBe('Enter a number, like 1203.5.');
    expect(decimalProblem(parseDecimalInput('12'))).toBeNull();
  });
});

describe('numberReadBack', () => {
  it('shows how a reading was understood', () => {
    expect(numberReadBack('32270', 'kl')).toBe('Reads as 32 270 kl');
    expect(numberReadBack('1203,5')).toBe('Reads as 1 203.5');
    expect(numberReadBack('')).toBeNull();
    expect(numberReadBack('1,203', 'kl')).toBe('Is this 1 203 or 1.203? Please type it as 1203 or 1.203.');
  });
});

describe('formatNumber', () => {
  it('groups thousands with a space and drops trailing zeros', () => {
    expect(formatNumber(12345.6)).toBe('12 345.6');
    expect(formatNumber(409.9)).toBe('409.9');
    expect(formatNumber(null)).toBe('');
  });
});
