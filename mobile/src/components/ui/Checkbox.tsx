import Ionicons from '@expo/vector-icons/Ionicons';
import type { ReactNode } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { colors, radius, spacing, touchTarget } from '@/theme';

import { AppText } from './AppText';
import { FieldError } from './FieldError';

export interface CheckboxProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label: string;
  /** Extra content under the label (e.g. an explanation). */
  description?: ReactNode;
  error?: string;
  disabled?: boolean;
  testID?: string;
}

export function Checkbox({ checked, onChange, label, description, error, disabled, testID }: CheckboxProps) {
  return (
    <View style={styles.wrapper}>
      <Pressable
        accessibilityRole="checkbox"
        accessibilityLabel={label}
        accessibilityState={{ checked, disabled }}
        accessibilityHint={error ? `Error: ${error}` : typeof description === 'string' ? description : undefined}
        disabled={disabled}
        onPress={() => onChange(!checked)}
        testID={testID}
        style={({ pressed }) => [styles.row, pressed && styles.pressed]}>
        <View style={[styles.box, checked && styles.boxChecked, error ? styles.boxError : null]}>
          {checked ? <Ionicons name="checkmark" size={20} color={colors.onPrimary} /> : null}
        </View>
        <View style={styles.text}>
          <AppText variant="strong">{label}</AppText>
          {typeof description === 'string' ? <AppText variant="small">{description}</AppText> : description}
        </View>
      </Pressable>
      <FieldError message={error} />
    </View>
  );
}

const styles = StyleSheet.create({
  wrapper: { gap: spacing.xs },
  row: {
    flexDirection: 'row',
    gap: spacing.md,
    alignItems: 'flex-start',
    minHeight: touchTarget,
    paddingVertical: spacing.xs,
  },
  pressed: { opacity: 0.8 },
  box: {
    width: 28,
    height: 28,
    marginTop: 2,
    borderRadius: radius.sm,
    borderWidth: 2,
    borderColor: colors.borderStrong,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  boxChecked: { backgroundColor: colors.primary, borderColor: colors.primary },
  boxError: { borderColor: colors.danger },
  text: { flex: 1, gap: spacing.xs },
});
