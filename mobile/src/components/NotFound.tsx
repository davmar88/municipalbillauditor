import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import { routes } from '@/lib/routes';
import { spacing } from '@/theme';

import { AppText, Button, CenteredScreen } from './ui';

export function NotFound() {
  return (
    <CenteredScreen>
      <View style={styles.box}>
        <AppText variant="title" accessibilityRole="header">
          We couldn't find that page
        </AppText>
        <AppText variant="muted">It may have been deleted, or the link is wrong.</AppText>
        <Button title="Go to your dashboard" onPress={() => router.replace(routes.dashboard)} />
      </View>
    </CenteredScreen>
  );
}

const styles = StyleSheet.create({
  box: { padding: spacing.xl, gap: spacing.md },
});
