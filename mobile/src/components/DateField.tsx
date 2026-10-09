import { formatDate, parseDateInput } from '@/lib/dates';

import { TextField } from './ui';

/** A date typed as text (25/09/2026, 2026-09-25 or 25 Sep 2026) with a read-back of what we understood. */
export function DateField({
  label,
  value,
  onChangeText,
  error,
  optional,
  testID,
}: {
  label: string;
  value: string;
  onChangeText: (text: string) => void;
  error?: string;
  optional?: boolean;
  testID?: string;
}) {
  const parsed = value.trim() ? parseDateInput(value) : null;
  const hint = !value.trim()
    ? 'For example 25/09/2026'
    : parsed
      ? `Reads as ${formatDate(parsed)}`
      : "We can't read this date yet. Try 25/09/2026.";
  return (
    <TextField
      label={label}
      optional={optional}
      value={value}
      onChangeText={onChangeText}
      placeholder="DD/MM/YYYY"
      autoCorrect={false}
      autoCapitalize="none"
      keyboardType="numbers-and-punctuation"
      error={error}
      hint={hint}
      testID={testID}
    />
  );
}
