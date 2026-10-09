import { StyleSheet, View } from 'react-native';

import { colors, radius, spacing } from '@/theme';

import { AppText } from './ui';

export function StatTile({
  label,
  value,
  emphasis,
  testID,
}: {
  label: string;
  value: string;
  emphasis?: boolean;
  testID?: string;
}) {
  return (
    <View
      style={[styles.tile, emphasis && styles.emphasis]}
      accessible
      accessibilityLabel={`${label}: ${value}`}
      testID={testID}>
      <AppText variant="small" style={emphasis ? styles.emphasisLabel : undefined}>
        {label}
      </AppText>
      <AppText
        variant="title"
        style={emphasis ? styles.emphasisValue : undefined}
        adjustsFontSizeToFit
        numberOfLines={1}>
        {value}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  tile: {
    flexGrow: 1,
    flexBasis: '45%',
    minWidth: 140,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  emphasis: { backgroundColor: colors.primary, borderColor: colors.primary },
  emphasisLabel: { color: '#D5ECEF' },
  emphasisValue: { color: colors.onPrimary },
});
