import { Pressable, StyleSheet, View } from 'react-native';

import type { Option } from '@/lib/labels';
import { colors, radius, spacing, touchTarget } from '@/theme';

import { AppText } from './AppText';
import { FieldError } from './FieldError';

export interface ChoiceChipsProps<T extends string> {
  label: string;
  value: T | null;
  options: Option<T>[];
  onChange: (value: T) => void;
  error?: string;
}

/** A short list of mutually exclusive choices shown inline as large buttons. */
export function ChoiceChips<T extends string>({ label, value, options, onChange, error }: ChoiceChipsProps<T>) {
  return (
    <View style={styles.wrapper}>
      <AppText variant="label">{label}</AppText>
      <View style={styles.row} accessibilityRole="radiogroup" accessibilityLabel={label}>
        {options.map((option) => {
          const selected = option.value === value;
          return (
            <Pressable
              key={option.value}
              accessibilityRole="radio"
              accessibilityLabel={`${label}: ${option.label}`}
              accessibilityState={{ checked: selected }}
              onPress={() => onChange(option.value)}
              style={({ pressed }) => [styles.chip, selected && styles.chipSelected, pressed && styles.pressed]}>
              <AppText variant="strong" style={selected ? styles.textSelected : styles.text}>
                {option.label}
              </AppText>
            </Pressable>
          );
        })}
      </View>
      <FieldError message={error} />
    </View>
  );
}

const styles = StyleSheet.create({
  wrapper: { gap: spacing.xs },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  chip: {
    minHeight: touchTarget,
    paddingHorizontal: spacing.lg,
    justifyContent: 'center',
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.borderStrong,
    backgroundColor: colors.surface,
  },
  chipSelected: { backgroundColor: colors.primary, borderColor: colors.primary },
  pressed: { opacity: 0.8 },
  text: { color: colors.text },
  textSelected: { color: colors.onPrimary },
});
