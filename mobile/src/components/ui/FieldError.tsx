import Ionicons from '@expo/vector-icons/Ionicons';
import { StyleSheet, View } from 'react-native';

import { colors, spacing } from '@/theme';

import { AppText } from './AppText';

/** Inline error under a field. Announced politely by screen readers. */
export function FieldError({ message }: { message?: string | null }) {
  if (!message) return null;
  return (
    <View style={styles.row} accessibilityLiveRegion="polite" accessibilityRole="alert">
      <Ionicons
        name="alert-circle"
        size={16}
        color={colors.danger}
        accessibilityElementsHidden
        importantForAccessibility="no"
      />
      <AppText variant="small" style={styles.text}>
        {message}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.xs },
  text: { color: colors.danger, flex: 1 },
});
