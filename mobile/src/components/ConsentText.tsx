import { StyleSheet, View } from 'react-native';

import { spacing } from '@/theme';

import { AppText } from './ui';

/** The POPIA notice shown at sign-up. Keep in step with the web app's wording. */
export function PopiaNotice() {
  return (
    <View style={styles.box}>
      <AppText>
        <AppText variant="strong">What we collect: </AppText>
        your name and email, the municipal bills you add (including any photo or PDF), your municipal account numbers
        and property addresses.
      </AppText>
      <AppText>
        <AppText variant="strong">Why: </AppText>
        only to check your bills for possible errors and to help you prepare and keep track of disputes.
      </AppText>
      <AppText>
        <AppText variant="strong">Your choice: </AppText>
        you can download or delete everything at any time from your Account screen. Account numbers and addresses are
        stored encrypted, and your bill files are kept private.
      </AppText>
    </View>
  );
}

export function aiConsentText(inSettings: boolean): string {
  return (
    'To save you typing, we can send the photo or PDF of your bill to an AI provider outside South Africa so it can read ' +
    'the amounts and line items for you. If you leave this off, your bill image is never sent there and you type the line ' +
    'items in yourself. ' +
    (inSettings
      ? 'Turning it off only affects bills you upload from now on.'
      : 'You can change this at any time in your account settings.')
  );
}

const styles = StyleSheet.create({
  box: { gap: spacing.sm },
});
