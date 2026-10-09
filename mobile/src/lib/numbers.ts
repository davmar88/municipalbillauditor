/** Helpers for meter readings and usage, which may have decimals. */

function groupThousands(digits: string): string {
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

/** Formats a reading like 1203.5 as "1 203.5" (max 3 decimals, trailing zeros dropped). */
export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return '';
  const negative = value < 0;
  const fixed = Math.abs(value)
    .toFixed(3)
    .replace(/\.?0+$/, '');
  const [intPart, fracPart] = fixed.split('.');
  return `${negative ? '-' : ''}${groupThousands(intPart)}${fracPart ? `.${fracPart}` : ''}`;
}

export type DecimalParse =
  | { valid: true; value: number | null }
  /** `ambiguous` is set for input like "12,345", which could be 12345 or 12.345. */
  | { valid: false; ambiguous?: { thousands: number; decimal: number } };

const INVALID: DecimalParse = { valid: false };

/**
 * Parses a typed decimal: "1203", "1 203.5", "1203,5", "12,345.6" or "1,234,567".
 * Empty input is valid and means null. A single comma followed by exactly three digits
 * ("12,345") could be a thousands separator or a decimal comma, so it is rejected as
 * ambiguous rather than guessed: a wrong guess would quietly change a reading a thousandfold.
 */
export function parseDecimalInput(input: string): DecimalParse {
  let s = input.trim().replace(/[\s\u00a0\u202f]/g, '');
  if (s === '') return { valid: true, value: null };

  if (s.includes(',')) {
    const commas = s.split(',').length - 1;
    const [intPart, ...fraction] = s.split('.');
    const thousandsGroups = /^\d{1,3}(,\d{3})+$/.test(intPart);
    if (fraction.length > 0) {
      // "12,345.6": with a decimal dot, commas can only separate thousands.
      if (fraction.length !== 1 || !thousandsGroups) return INVALID;
      s = s.replace(/,/g, '');
    } else if (commas === 1) {
      if (/^[1-9]\d{0,2},\d{3}$/.test(s)) {
        return {
          valid: false,
          ambiguous: { thousands: Number(s.replace(',', '')), decimal: Number(s.replace(',', '.')) },
        };
      }
      // "1203,5": a decimal comma.
      s = s.replace(',', '.');
    } else {
      // "1,234,567": thousands separators.
      if (!thousandsGroups) return INVALID;
      s = s.replace(/,/g, '');
    }
  }

  if (!/^\d*(\.\d+)?$/.test(s) || s === '' || s === '.') return INVALID;
  const value = Number(s);
  return Number.isFinite(value) ? { valid: true, value } : INVALID;
}

/** What to tell someone whose typed reading or usage can't be read. */
export function decimalProblem(parsed: DecimalParse): string | null {
  if (parsed.valid) return null;
  if (parsed.ambiguous) {
    const { thousands, decimal } = parsed.ambiguous;
    return `Is this ${formatNumber(thousands)} or ${String(decimal)}? Please type it as ${String(thousands)} or ${String(decimal)}.`;
  }
  return 'Enter a number, like 1203.5.';
}

/**
 * Shows how a typed reading or usage was understood, e.g. "Reads as 12 345 kl",
 * or what is wrong with it. Returns null for empty input.
 */
export function numberReadBack(input: string, unit = ''): string | null {
  const parsed = parseDecimalInput(input);
  if (!parsed.valid) return decimalProblem(parsed);
  if (parsed.value === null) return null;
  return `Reads as ${formatNumber(parsed.value)}${unit ? ` ${unit}` : ''}`;
}

/** Formats a 0..1 confidence as a whole percentage, e.g. 0.8 -> "80%". */
export function formatPercent(fraction: number): string {
  if (!Number.isFinite(fraction)) return '';
  return `${Math.round(fraction * 100)}%`;
}

/** Turns a stored number into an editable string. */
export function numberToInput(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return '';
  return String(value);
}
