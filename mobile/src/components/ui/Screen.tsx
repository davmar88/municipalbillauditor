import { useRef, type ReactNode } from 'react';
import { RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useKeyboardOverlap } from '@/hooks/useKeyboardOverlap';
import { colors, spacing } from '@/theme';

export interface ScreenProps {
  children: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  /** Tab screens sit above the tab bar, which already handles the bottom safe area. */
  bottomInset?: boolean;
  /** Screens without a header must keep clear of the status bar and notch. */
  topInset?: boolean;
  testID?: string;
}

/** Scrollable page body with consistent padding, keyboard handling and safe-area spacing. */
export function Screen({
  children,
  refreshing = false,
  onRefresh,
  bottomInset = true,
  topInset = false,
  testID,
}: ScreenProps) {
  const insets = useSafeAreaInsets();
  // On Android the keyboard no longer shrinks the window, so pad by the part it covers.
  const keyboardAreaRef = useRef<View>(null);
  const keyboard = useKeyboardOverlap(keyboardAreaRef);
  return (
    <View
      ref={keyboardAreaRef}
      onLayout={keyboard.onLayout}
      style={[styles.scroll, { paddingBottom: keyboard.overlap }]}>
      <ScrollView
        style={styles.fill}
        contentContainerStyle={[
          styles.content,
          {
            paddingTop: spacing.lg + (topInset ? insets.top : 0),
            paddingBottom: spacing.xxl + (bottomInset ? insets.bottom : 0),
            paddingLeft: spacing.lg + insets.left,
            paddingRight: spacing.lg + insets.right,
          },
        ]}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        automaticallyAdjustKeyboardInsets
        refreshControl={
          onRefresh ? (
            <RefreshControl
              refreshing={refreshing}
              onRefresh={onRefresh}
              tintColor={colors.primary}
              colors={[colors.primary]}
            />
          ) : undefined
        }
        testID={testID}>
        <View style={styles.inner}>{children}</View>
      </ScrollView>
    </View>
  );
}

/** A full-height plain container for loading and error states. */
export function CenteredScreen({ children }: { children: ReactNode }) {
  return <View style={styles.centered}>{children}</View>;
}

const styles = StyleSheet.create({
  scroll: { flex: 1, backgroundColor: colors.background },
  fill: { flex: 1 },
  content: { flexGrow: 1 },
  inner: { width: '100%', maxWidth: 720, alignSelf: 'center', gap: spacing.lg },
  centered: { flex: 1, backgroundColor: colors.background, justifyContent: 'center' },
});
