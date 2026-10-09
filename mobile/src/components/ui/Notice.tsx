import Ionicons from '@expo/vector-icons/Ionicons';
import type { ReactNode } from 'react';
import { StyleSheet, View } from 'react-native';

import { radius, spacing } from '@/theme';

import { AppText } from './AppText';
import { TONES, type Tone } from './Badge';
import type { IconName } from './Button';

const ICONS: Record<Tone, IconName> = {
  neutral: 'information-circle-outline',
  info: 'information-circle-outline',
  success: 'checkmark-circle-outline',
  warning: 'alert-circle-outline',
  danger: 'alert-circle-outline',
};

/** A coloured message box for hints, warnings and form-level errors. */
export function Notice({
  tone = 'info',
  title,
  children,
  testID,
}: {
  tone?: Tone;
  title?: string;
  children?: ReactNode;
  testID?: string;
}) {
  const palette = TONES[tone];
  return (
    <View
      style={[styles.box, { backgroundColor: palette.bg, borderLeftColor: palette.fg }]}
      accessibilityRole={tone === 'danger' ? 'alert' : undefined}
      accessibilityLiveRegion={tone === 'danger' ? 'polite' : undefined}
      testID={testID}>
      <Ionicons
        name={ICONS[tone]}
        size={22}
        color={palette.fg}
        accessibilityElementsHidden
        importantForAccessibility="no"
      />
      <View style={styles.text}>
        {title ? <AppText variant="strong">{title}</AppText> : null}
        {typeof children === 'string' ? <AppText>{children}</AppText> : children}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { flexDirection: 'row', gap: spacing.md, padding: spacing.md, borderRadius: radius.md, borderLeftWidth: 4 },
  text: { flex: 1, gap: spacing.xs },
});
