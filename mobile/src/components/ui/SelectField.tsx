import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { FlatList, Modal, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { Option } from '@/lib/labels';
import { colors, radius, spacing, touchTarget } from '@/theme';

import { AppText } from './AppText';
import { Button } from './Button';
import { FieldError } from './FieldError';

export interface SelectFieldProps<T extends string> {
  label: string;
  value: T | null;
  options: Option<T>[];
  onChange: (value: T) => void;
  placeholder?: string;
  error?: string;
  hint?: string;
  optional?: boolean;
  testID?: string;
}

/** A field that opens a full list of choices. Better than a native picker for long labels. */
export function SelectField<T extends string>({
  label,
  value,
  options,
  onChange,
  placeholder = 'Choose…',
  error,
  hint,
  optional,
  testID,
}: SelectFieldProps<T>) {
  const [open, setOpen] = useState(false);
  const insets = useSafeAreaInsets();
  const selected = options.find((option) => option.value === value);
  const fullLabel = optional ? `${label} (optional)` : label;

  return (
    <View style={styles.wrapper}>
      <AppText variant="label">{fullLabel}</AppText>
      {hint ? <AppText variant="small">{hint}</AppText> : null}
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`${fullLabel}: ${selected ? selected.label : 'not chosen yet'}`}
        accessibilityHint={error ? `Error: ${error}` : 'Opens a list of choices'}
        onPress={() => setOpen(true)}
        testID={testID}
        style={({ pressed }) => [styles.field, error ? styles.fieldError : null, pressed && styles.pressed]}>
        <AppText style={[styles.value, !selected && styles.placeholder]} numberOfLines={2}>
          {selected ? selected.label : placeholder}
        </AppText>
        <Ionicons name="chevron-down" size={20} color={colors.textMuted} />
      </Pressable>
      <FieldError message={error} />

      <Modal
        visible={open}
        animationType="slide"
        statusBarTranslucent
        navigationBarTranslucent
        onRequestClose={() => setOpen(false)}>
        <View
          style={[styles.sheet, { paddingTop: insets.top + spacing.lg, paddingBottom: insets.bottom + spacing.lg }]}>
          <View style={styles.sheetHeader}>
            <AppText variant="title" accessibilityRole="header" style={styles.sheetTitle}>
              {label}
            </AppText>
            <Button title="Close" variant="ghost" onPress={() => setOpen(false)} />
          </View>
          <FlatList
            data={options}
            keyExtractor={(option) => option.value}
            accessibilityRole="radiogroup"
            contentContainerStyle={styles.list}
            renderItem={({ item }) => {
              const isSelected = item.value === value;
              return (
                <Pressable
                  accessibilityRole="radio"
                  accessibilityLabel={item.label}
                  accessibilityHint={item.description}
                  accessibilityState={{ checked: isSelected }}
                  onPress={() => {
                    onChange(item.value);
                    setOpen(false);
                  }}
                  style={({ pressed }) => [
                    styles.option,
                    isSelected && styles.optionSelected,
                    pressed && styles.pressed,
                  ]}>
                  <Ionicons
                    name={isSelected ? 'radio-button-on' : 'radio-button-off'}
                    size={22}
                    color={isSelected ? colors.primary : colors.textMuted}
                  />
                  <View style={styles.optionText}>
                    <AppText variant={isSelected ? 'strong' : 'body'}>{item.label}</AppText>
                    {item.description ? <AppText variant="small">{item.description}</AppText> : null}
                  </View>
                </Pressable>
              );
            }}
          />
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  wrapper: { gap: spacing.xs },
  field: {
    minHeight: touchTarget,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    borderWidth: 1,
    borderColor: colors.borderStrong,
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  fieldError: { borderColor: colors.danger, borderWidth: 2 },
  value: { flex: 1 },
  placeholder: { color: colors.textMuted },
  pressed: { opacity: 0.8 },
  sheet: { flex: 1, backgroundColor: colors.background, paddingHorizontal: spacing.lg },
  sheetHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.md },
  sheetTitle: { flex: 1 },
  list: { gap: spacing.sm, paddingVertical: spacing.lg },
  option: {
    minHeight: touchTarget + 8,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  optionSelected: { borderColor: colors.primary, backgroundColor: colors.primarySoft },
  optionText: { flex: 1, gap: 2 },
});
