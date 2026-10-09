/**
 * Date helpers. The API sends plain dates as "YYYY-MM-DD" and timestamps as ISO-8601 UTC.
 * Display format: "25 Sep 2026". Deliberately not using Intl so output is identical everywhere.
 */

export const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as const;
const FULL_MONTHS = [
  'january',
  'february',
  'march',
  'april',
  'may',
  'june',
  'july',
  'august',
  'september',
  'october',
  'november',
  'december',
] as const;

const MONTH_LOOKUP: Record<string, number> = { sept: 9 };
FULL_MONTHS.forEach((full, index) => {
  MONTH_LOOKUP[full] = index + 1;
  MONTH_LOOKUP[full.slice(0, 3)] = index + 1;
});

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

function isValidYmd(y: number, m: number, d: number): boolean {
  if (y < 1900 || y > 2200 || m < 1 || m > 12 || d < 1) return false;
  const daysInMonth = new Date(Date.UTC(y, m, 0)).getUTCDate();
  return d <= daysInMonth;
}

function toIso(y: number, m: number, d: number): string {
  return `${y}-${pad2(m)}-${pad2(d)}`;
}

/** Parses a strict "YYYY-MM-DD" string. */
export function parseIsoDate(value: string): { year: number; month: number; day: number } | null {
  const match = ISO_DATE.exec(value);
  if (!match) return null;
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  return isValidYmd(year, month, day) ? { year, month, day } : null;
}

/**
 * Formats a "YYYY-MM-DD" date or an ISO timestamp as "25 Sep 2026".
 * Timestamps are shown in the device's local time zone.
 */
export function formatDate(value: string | null | undefined, fallback = '—'): string {
  if (!value) return fallback;
  const plain = parseIsoDate(value.slice(0, 10));
  if (value.length === 10) {
    return plain ? `${plain.day} ${MONTHS[plain.month - 1]} ${plain.year}` : fallback;
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return fallback;
  return `${date.getDate()} ${MONTHS[date.getMonth()]} ${date.getFullYear()}`;
}

/** Formats an ISO timestamp as "1 Sep 2026, 08:00" in local time. */
export function formatDateTime(value: string | null | undefined, fallback = '—'): string {
  if (!value) return fallback;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return fallback;
  return `${formatDate(value)}, ${pad2(date.getHours())}:${pad2(date.getMinutes())}`;
}

/** Today's local date as "YYYY-MM-DD". */
export function todayIso(now: Date = new Date()): string {
  return toIso(now.getFullYear(), now.getMonth() + 1, now.getDate());
}

/** Local calendar date ("YYYY-MM-DD") of a plain date or a timestamp. */
export function toLocalIsoDate(value: string): string | null {
  if (value.length === 10) return parseIsoDate(value) ? value : null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : todayIso(date);
}

/** Whole days from `today` until `due` (negative when overdue). Accepts plain dates or timestamps. */
export function daysUntil(due: string, today: string = todayIso()): number | null {
  const dueDate = toLocalIsoDate(due);
  const a = parseIsoDate(today);
  const b = dueDate ? parseIsoDate(dueDate) : null;
  if (!a || !b) return null;
  const ms = Date.UTC(b.year, b.month - 1, b.day) - Date.UTC(a.year, a.month - 1, a.day);
  return Math.round(ms / 86_400_000);
}

export function isOverdue(due: string, today: string = todayIso()): boolean {
  const days = daysUntil(due, today);
  return days !== null && days < 0;
}

/** Friendly relative wording, e.g. "Due today", "Due in 3 days", "2 days overdue". */
export function describeDue(due: string, today: string = todayIso()): string {
  const days = daysUntil(due, today);
  if (days === null) return '';
  if (days === 0) return 'Due today';
  if (days === 1) return 'Due tomorrow';
  if (days > 1) return `Due in ${days} days`;
  if (days === -1) return '1 day overdue';
  return `${-days} days overdue`;
}

/**
 * Parses a date typed by a person. Accepts "2026-09-25", "25/09/2026", "25-09-2026",
 * "25 Sep 2026" and "25 September 2026". Returns "YYYY-MM-DD" or null.
 */
export function parseDateInput(input: string): string | null {
  const s = input.trim().replace(/\s+/g, ' ');
  if (s === '') return null;

  let match = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/.exec(s);
  if (match) {
    const [y, m, d] = [Number(match[1]), Number(match[2]), Number(match[3])];
    return isValidYmd(y, m, d) ? toIso(y, m, d) : null;
  }

  match = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/.exec(s);
  if (match) {
    const [d, m, y] = [Number(match[1]), Number(match[2]), Number(match[3])];
    return isValidYmd(y, m, d) ? toIso(y, m, d) : null;
  }

  match = /^(\d{1,2}) ([a-zA-Z]+)\.?,? (\d{4})$/.exec(s);
  if (match) {
    const d = Number(match[1]);
    const m = MONTH_LOOKUP[match[2].toLowerCase()];
    const y = Number(match[3]);
    if (!m) return null;
    return isValidYmd(y, m, d) ? toIso(y, m, d) : null;
  }

  return null;
}

/** Parses "08:00", "8:00", "08h00" or "0800". Returns minutes since midnight or null. */
export function parseTimeInput(input: string): number | null {
  const s = input.trim().toLowerCase();
  if (s === '') return null;
  const match = /^(\d{1,2})(?:[:h.]?(\d{2}))?$/.exec(s);
  if (!match) return null;
  const hours = Number(match[1]);
  const minutes = Number(match[2] ?? '0');
  if (hours > 23 || minutes > 59) return null;
  return hours * 60 + minutes;
}

/** Combines a local date ("YYYY-MM-DD") and minutes since midnight into an ISO UTC timestamp. */
export function localDateTimeToIso(date: string, minutes: number): string | null {
  const parts = parseIsoDate(date);
  if (!parts) return null;
  const local = new Date(parts.year, parts.month - 1, parts.day, Math.floor(minutes / 60), minutes % 60, 0, 0);
  return local.toISOString();
}

/** Local "HH:MM" of a timestamp, for prefilling time inputs. */
export function timeOf(value: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return `${pad2(date.getHours())}:${pad2(date.getMinutes())}`;
}
