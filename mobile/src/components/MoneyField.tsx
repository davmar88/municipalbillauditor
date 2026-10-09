import { formatRand, parseRandToCents } from '@/lib/money';

import { TextField } from './ui';

/** An amount typed in rand. Shows how we read it, e.g. "R1 523.40". */
export function MoneyField({
  label,
  value,
  onChangeText,
  error,
  optional,
  hint,
  testID,
}: {
  label: string;
  value: string;
  onChangeText: (text: string) => void;
  error?: string;
  optional?: boolean;
  hint?: string;
  testID?: string;
}) {
  const cents = value.trim() ? parseRandToCents(value) : null;
  const readBack = value.trim()
    ? cents !== null
      ? `Reads as ${formatRand(cents)}`
      : 'Use numbers only, like 1523.40'
    : hint;
  return (
    <TextField
      label={`${label} (R)`}
      optional={optional}
      value={value}
      onChangeText={onChangeText}
      placeholder="0.00"
      keyboardType="decimal-pad"
      autoCorrect={false}
      error={error}
      hint={readBack}
      testID={testID}
    />
  );
}
