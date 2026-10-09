import Ionicons from '@expo/vector-icons/Ionicons';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { errorMessage } from '@/lib/forms';
import { colors, spacing } from '@/theme';

import { AppText } from './AppText';
import { Button, type IconName } from './Button';

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return (
    <View
      style={styles.center}
      accessibilityRole="progressbar"
      accessibilityLabel={label}
      accessibilityLiveRegion="polite">
      <ActivityIndicator size="large" color={colors.primary} />
      <AppText variant="muted">{label}</AppText>
    </View>
  );
}

export function ErrorState({
  error,
  onRetry,
  title = "We couldn't load this",
}: {
  error: unknown;
  onRetry?: () => void;
  title?: string;
}) {
  return (
    <View style={styles.center} accessibilityRole="alert">
      <Ionicons name="cloud-offline-outline" size={40} color={colors.textMuted} />
      <AppText variant="heading" style={styles.centerText}>
        {title}
      </AppText>
      <AppText variant="muted" style={styles.centerText}>
        {errorMessage(error)}
      </AppText>
      {onRetry ? <Button title="Try again" icon="refresh" variant="secondary" onPress={onRetry} /> : null}
    </View>
  );
}

export function EmptyState({
  icon = 'document-text-outline',
  title,
  body,
  actionLabel,
  onAction,
}: {
  icon?: IconName;
  title: string;
  body?: string;
  actionLabel?: string;
  onAction?: () => void;
}) {
  return (
    <View style={styles.empty}>
      <Ionicons
        name={icon}
        size={36}
        color={colors.primary}
        accessibilityElementsHidden
        importantForAccessibility="no"
      />
      <AppText variant="heading" style={styles.centerText}>
        {title}
      </AppText>
      {body ? (
        <AppText variant="muted" style={styles.centerText}>
          {body}
        </AppText>
      ) : null}
      {actionLabel && onAction ? (
        <Button title={actionLabel} icon="add" onPress={onAction} style={styles.action} />
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.md,
    padding: spacing.xl,
    minHeight: 240,
  },
  centerText: { textAlign: 'center' },
  empty: {
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.xl,
    paddingHorizontal: spacing.lg,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: colors.borderStrong,
    borderRadius: 14,
    backgroundColor: colors.surface,
  },
  action: { alignSelf: 'stretch', marginTop: spacing.sm },
});
