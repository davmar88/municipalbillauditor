/** Design tokens. One calm, high-contrast light theme. */
export const colors = {
  background: '#F5F7F8',
  surface: '#FFFFFF',
  surfaceMuted: '#F0F2F4',
  border: '#D5DBE0',
  borderStrong: '#AEB8C1',
  text: '#13222E',
  textMuted: '#4F5D69',
  primary: '#0A5C6B',
  primaryPressed: '#07434F',
  primarySoft: '#E3F1F3',
  onPrimary: '#FFFFFF',
  danger: '#B42318',
  dangerPressed: '#8E1B12',
  dangerSoft: '#FDECEA',
  warning: '#9A4A00',
  warningSoft: '#FFF4E0',
  success: '#106B3A',
  successSoft: '#E5F5EC',
  info: '#1F4F99',
  infoSoft: '#E8F0FB',
  focus: '#2563EB',
} as const;

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

export const radius = {
  sm: 6,
  md: 10,
  lg: 14,
  pill: 999,
} as const;

export const fontSize = {
  small: 14,
  body: 16,
  large: 18,
  title: 22,
  hero: 28,
} as const;

/** Minimum height for anything tappable (WCAG / platform guidance is 44-48pt). */
export const touchTarget = 48;
