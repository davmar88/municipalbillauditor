import { forwardRef } from 'react';
import { StyleSheet, TextInput, View, type TextInputProps } from 'react-native';

import { colors, fontSize, radius, spacing, touchTarget } from '@/theme';

import { AppText } from './AppText';
import { FieldError } from './FieldError';

export interface TextFieldProps extends TextInputProps {
  label: string;
  error?: string;
  hint?: string;
  optional?: boolean;
}

export const TextField = forwardRef<TextInput, TextFieldProps>(function TextField(
  { label, error, hint, optional, style, multiline, ...rest },
  ref,
) {
  const fullLabel = optional ? `${label} (optional)` : label;
  return (
    <View style={styles.wrapper}>
      <AppText variant="label">{fullLabel}</AppText>
      {hint ? <AppText variant="small">{hint}</AppText> : null}
      <TextInput
        ref={ref}
        accessibilityLabel={fullLabel}
        accessibilityHint={error ? `Error: ${error}` : hint}
        placeholderTextColor={colors.textMuted}
        multiline={multiline}
        textAlignVertical={multiline ? 'top' : 'center'}
        style={[styles.input, multiline && styles.multiline, error ? styles.inputError : null, style]}
        {...rest}
      />
      <FieldError message={error} />
    </View>
  );
});

const styles = StyleSheet.create({
  wrapper: { gap: spacing.xs },
  input: {
    minHeight: touchTarget,
    borderWidth: 1,
    borderColor: colors.borderStrong,
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: fontSize.body,
    color: colors.text,
  },
  multiline: { minHeight: 120, paddingTop: spacing.md },
  inputError: { borderColor: colors.danger, borderWidth: 2 },
});
