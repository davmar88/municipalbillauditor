import type { ReactNode } from 'react';
import { StyleSheet, View } from 'react-native';

import { colors, spacing } from '@/theme';

import { AppText } from './AppText';

/** A titled block of content on a page. */
export function Section({
  title,
  description,
  children,
  action,
}: {
  title: string;
  description?: string;
  children?: ReactNode;
  action?: ReactNode;
}) {
  return (
    <View style={styles.section}>
      <View style={styles.header}>
        <View style={styles.titles}>
          <AppText variant="heading" accessibilityRole="header">
            {title}
          </AppText>
          {description ? <AppText variant="small">{description}</AppText> : null}
        </View>
        {action}
      </View>
      {children}
    </View>
  );
}

/** A label/value line inside a card. */
export function DetailRow({ label, value, children }: { label: string; value?: string; children?: ReactNode }) {
  return (
    // With a plain value the row reads as one item; with children (e.g. a button) they stay separately focusable.
    <View
      style={styles.row}
      accessible={value !== undefined}
      accessibilityLabel={value !== undefined ? `${label}: ${value}` : undefined}>
      <AppText variant="small" style={styles.rowLabel}>
        {label}
      </AppText>
      <View style={styles.rowValue}>
        {value !== undefined ? <AppText variant="strong">{value}</AppText> : children}
      </View>
    </View>
  );
}

export function Divider() {
  return <View style={styles.divider} />;
}

const styles = StyleSheet.create({
  section: { gap: spacing.md },
  header: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    justifyContent: 'space-between',
    gap: spacing.md,
    flexWrap: 'wrap',
  },
  titles: { flex: 1, minWidth: 180, gap: 2 },
  row: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
    alignItems: 'baseline',
    gap: spacing.sm,
    paddingVertical: 2,
  },
  rowLabel: { flexShrink: 1, minWidth: 110 },
  rowValue: { flexShrink: 1, alignItems: 'flex-end' },
  divider: { height: 1, backgroundColor: colors.border, marginVertical: spacing.xs },
});
