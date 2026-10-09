import { router } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { useAuth } from '@/auth/AuthProvider';
import { AppText, Button, FormErrorSummary, Notice, Screen, TextField } from '@/components/ui';
import { fieldErrorsFrom, type FieldErrors } from '@/lib/forms';
import { routes } from '@/lib/routes';
import { spacing } from '@/theme';

export function SignInScreen() {
  const { signIn, sessionExpired } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<unknown>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    const local: FieldErrors = {};
    if (!email.trim()) local.email = 'Enter your email address.';
    if (!password) local.password = 'Enter your password.';
    setErrors(local);
    setError(null);
    if (Object.keys(local).length > 0) return;

    setSubmitting(true);
    try {
      await signIn({ email: email.trim(), password });
    } catch (e) {
      setErrors(fieldErrorsFrom(e));
      setError(e);
      setSubmitting(false);
    }
  };

  return (
    <Screen topInset>
      <View style={styles.hero}>
        <AppText variant="hero" accessibilityRole="header">
          Municipal Bill Auditor
        </AppText>
        <AppText variant="muted">Check your municipal bill for possible mistakes, and get help disputing them.</AppText>
      </View>

      {sessionExpired ? <Notice tone="info">You were signed out. Please sign in again.</Notice> : null}

      <AppText variant="title" accessibilityRole="header">
        Sign in
      </AppText>
      <TextField
        label="Email"
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        autoComplete="email"
        keyboardType="email-address"
        textContentType="emailAddress"
        error={errors.email}
      />
      <TextField
        label="Password"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        autoComplete="current-password"
        textContentType="password"
        error={errors.password}
        onSubmitEditing={submit}
      />
      <FormErrorSummary error={error} fieldErrors={errors} shownFields={['email', 'password']} />
      <Button title="Sign in" onPress={submit} loading={submitting} />

      <View style={styles.footer}>
        <AppText variant="muted">New here?</AppText>
        <Button title="Create an account" variant="secondary" onPress={() => router.push(routes.signUp)} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  hero: { gap: spacing.sm, marginTop: spacing.xl, marginBottom: spacing.md },
  footer: { gap: spacing.sm, marginTop: spacing.lg },
});
