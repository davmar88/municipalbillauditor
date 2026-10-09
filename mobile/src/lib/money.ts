/**
 * Money helpers. The API always uses integer cents; people type and read rand.
 * Display format: "R1 523.40", "R0.00", "-R12.00" (normal space as thousands separator).
 * Deliberately not using Intl so output is identical on every device.
 */

function groupThousands(digits: string): string {
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

/** Formats integer cents as rand, e.g. 152340 -> "R1 523.40". */
export function formatRand(cents: number): string {
  if (!Number.isFinite(cents)) return '';
  const whole = Math.round(cents);
  const negative = whole < 0;
  const abs = Math.abs(whole);
  const rands = Math.floor(abs / 100);
  const remainder = abs % 100;
  return `${negative ? '-' : ''}R${groupThousands(String(rands))}.${String(remainder).padStart(2, '0')}`;
}

/** Same as formatRand but returns a fallback for null/undefined amounts. */
export function formatRandOrDash(cents: number | null | undefined, fallback = '—'): string {
  return cents === null || cents === undefined ? fallback : formatRand(cents);
}

/**
 * Parses what a person types as a rand amount into integer cents.
 * Accepts "1523.40", "R1 523.40", "1523,40", "1,523.40", "0.5".
 * Returns null when empty or not a valid non-negative amount with at most two decimals.
 */
export function parseRandToCents(input: string): number | null {
  let s = input
    .trim()
    .replace(/^R/i, '')
    .replace(/[\s  ]/g, '');
  if (s === '') return null;

  const hasComma = s.includes(',');
  const hasDot = s.includes('.');
  if (hasComma && hasDot) {
    // "1,523.40": commas are thousands separators.
    s = s.replace(/,/g, '');
  } else if (hasComma) {
    const commas = s.split(',').length - 1;
    // "1523,40" uses a decimal comma; "1,523,000" uses thousands commas.
    s = commas === 1 ? s.replace(',', '.') : s.replace(/,/g, '');
  }

  const match = /^(\d*)(?:\.(\d{1,2}))?$/.exec(s);
  if (!match) return null;
  const [, intPart, fracPart = ''] = match;
  if (intPart === '' && fracPart === '') return null;

  const cents = Number(intPart || '0') * 100 + Number(fracPart.padEnd(2, '0'));
  return Number.isSafeInteger(cents) ? cents : null;
}

/** Turns cents into an editable rand string without separators, e.g. 152340 -> "1523.40". */
export function centsToRandInput(cents: number | null | undefined): string {
  if (cents === null || cents === undefined || !Number.isFinite(cents)) return '';
  const whole = Math.round(cents);
  const abs = Math.abs(whole);
  return `${whole < 0 ? '-' : ''}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}
