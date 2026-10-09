import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { StyleSheet, Switch, View } from 'react-native';

import * as api from '@/api';
import type { User } from '@/api';
import { useAuth } from '@/auth/AuthProvider';
import { aiConsentText } from '@/components/ConsentText';
import {
  AppText,
  Button,
  Card,
  CenteredScreen,
  DetailRow,
  ErrorState,
  FormErrorSummary,
  LoadingState,
  Notice,
  Screen,
  Section,
  TextField,
} from '@/components/ui';
import { useMe } from '@/hooks/queries';
import { confirmAction, showMessage } from '@/lib/confirm';
import { formatDate } from '@/lib/dates';
import { openLocalFile } from '@/lib/files';
import { errorMessage, fieldErrorsFrom, type FieldErrors } from '@/lib/forms';
import { queryKeys } from '@/lib/queryKeys';
import { colors, spacing } from '@/theme';

export function AccountScreen() {
  const me = useMe();
  if (me.isPending) {
    return (
      <CenteredScreen>
        <LoadingState />
      </CenteredScreen>
    );
  }
  if (me.isError) {
    return (
      <CenteredScreen>
        <ErrorState error={me.error} onRetry={() => me.refetch()} />
      </CenteredScreen>
    );
  }
  return <AccountDetails user={me.data} />;
}

function AccountDetails({ user }: { user: User }) {
  const queryClient = useQueryClient();
  const { signOut, endSession } = useAuth();
  const [name, setName] = useState(user.name);
  const [nameErrors, setNameErrors] = useState<FieldErrors>({});
  const [nameError, setNameError] = useState<unknown>(null);
  const [savingName, setSavingName] = useState(false);
  const [nameSaved, setNameSaved] = useState(false);
  const [savingConsent, setSavingConsent] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [signingOut, setSigningOut] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [password, setPassword] = useState('');
  const [deleteErrors, setDeleteErrors] = useState<FieldErrors>({});
  const [deleteError, setDeleteError] = useState<unknown>(null);
  const [deleting, setDeleting] = useState(false);

  const saveName = async () => {
    setNameSaved(false);
    setNameError(null);
    if (!name.trim()) {
      setNameErrors({ name: 'Enter your name.' });
      return;
    }
    setNameErrors({});
    setSavingName(true);
    try {
      queryClient.setQueryData(queryKeys.me, await api.updateMe({ name: name.trim() }));
      setNameSaved(true);
    } catch (e) {
      setNameErrors(fieldErrorsFrom(e));
      setNameError(e);
    } finally {
      setSavingName(false);
    }
  };

  const toggleAi = async (value: boolean) => {
    setSavingConsent(true);
    try {
      queryClient.setQueryData(queryKeys.me, await api.updateMe({ ai_extraction_consent: value }));
    } catch (e) {
      showMessage("Couldn't change AI bill reading", errorMessage(e));
    } finally {
      setSavingConsent(false);
    }
  };

  const downloadData = async () => {
    setExporting(true);
    try {
      const uri = await api.exportMyData();
      await openLocalFile(uri, 'application/json', 'Save your data');
    } catch (e) {
      showMessage("Couldn't download your data", errorMessage(e));
    } finally {
      setExporting(false);
    }
  };

  const doSignOut = async () => {
    setSigningOut(true);
    await signOut();
  };

  const deleteAccount = async () => {
    setDeleteError(null);
    if (!password) {
      setDeleteErrors({ password: 'Enter your password to confirm.' });
      return;
    }
    setDeleteErrors({});
    const ok = await confirmAction({
      title: 'Delete your account?',
      message: "This permanently deletes your account, properties, bills, files and disputes. You can't undo this.",
      confirmLabel: 'Delete everything',
      destructive: true,
    });
    if (!ok) return;
    setDeleting(true);
    try {
      await api.deleteMe({ password });
      await endSession();
      showMessage('Account deleted', 'Your account and all your data have been deleted.');
    } catch (e) {
      setDeleteErrors(fieldErrorsFrom(e));
      setDeleteError(e);
      setDeleting(false);
    }
  };

  return (
    <Screen bottomInset={false}>
      <Section title="Your details">
        <TextField label="Name" value={name} onChangeText={setName} autoComplete="name" error={nameErrors.name} />
        <DetailRow label="Email" value={user.email} />
        <FormErrorSummary error={nameError} fieldErrors={nameErrors} shownFields={['name']} />
        {nameSaved && name.trim() === user.name ? <Notice tone="success">Your name is saved.</Notice> : null}
        <Button
          title="Save name"
          variant="secondary"
          onPress={saveName}
          loading={savingName}
          disabled={name.trim() === user.name}
        />
      </Section>

      <Section title="AI bill reading">
        <Card>
          <View style={styles.switchRow}>
            <AppText variant="strong" style={styles.flex} nativeID="ai-label">
              Read my bills with AI
            </AppText>
            <Switch
              value={user.ai_extraction_consent}
              onValueChange={toggleAi}
              disabled={savingConsent}
              accessibilityLabel="Read my bills with AI"
              accessibilityHint="Sends bill photos and PDFs to an AI provider outside South Africa"
              trackColor={{ true: colors.primary, false: colors.borderStrong }}
              testID="ai-consent-switch"
            />
          </View>
          <AppText variant="small">{aiConsentText(true)}</AppText>
        </Card>
      </Section>

      <Section title="Your privacy">
        <AppText variant="muted">
          {`You agreed to our privacy notice (version ${user.popia_consent_version}) on ${formatDate(user.popia_consented_at)}.`}
        </AppText>
        <Button
          title="Download my data"
          variant="secondary"
          icon="download-outline"
          onPress={downloadData}
          loading={exporting}
        />
        <AppText variant="small">
          You'll get a file with everything we hold about you, which you can save or send.
        </AppText>
      </Section>

      <Button title="Sign out" variant="secondary" icon="log-out-outline" onPress={doSignOut} loading={signingOut} />

      <Section title="Delete my account">
        <AppText variant="small">This permanently removes your account and every bill, file and dispute in it.</AppText>
        {deleteOpen ? (
          <Card>
            <TextField
              label="Your password"
              hint="Enter your password to confirm it's you."
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              autoComplete="current-password"
              error={deleteErrors.password}
            />
            <FormErrorSummary error={deleteError} fieldErrors={deleteErrors} shownFields={['password']} />
            <Button title="Delete my account permanently" variant="danger" onPress={deleteAccount} loading={deleting} />
            <Button title="Cancel" variant="ghost" onPress={() => setDeleteOpen(false)} />
          </Card>
        ) : (
          <Button title="Delete my account" variant="danger" icon="trash-outline" onPress={() => setDeleteOpen(true)} />
        )}
      </Section>
    </Screen>
  );
}

const styles = StyleSheet.create({
  switchRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, minHeight: 48 },
  flex: { flex: 1 },
});
