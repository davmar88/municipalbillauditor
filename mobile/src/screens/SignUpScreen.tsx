import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { useAuth } from '@/auth/AuthProvider';
import { aiConsentText, PopiaNotice } from '@/components/ConsentText';
import { AppText, Button, Card, Checkbox, FormErrorSummary, Screen, TextField } from '@/components/ui';
import { fieldErrorsFrom, type FieldErrors } from '@/lib/forms';
import { goBackOr, routes } from '@/lib/routes';
import { spacing } from '@/theme';

const SHOWN_FIELDS = ['name', 'email', 'password', 'password_confirmation', 'popia_consent', 'ai_extraction_consent'];

export function SignUpScreen() {
  const { signUp } = useAuth();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [popiaConsent, setPopiaConsent] = useState(false);
  const [aiConsent, setAiConsent] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<unknown>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    const local: FieldErrors = {};
    if (!name.trim()) local.name = 'Enter your name.';
    if (!email.trim()) local.email = 'Enter your email address.';
    if (password.length < 8) local.password = 'Use at least 8 characters.';
    if (confirmation !== password) local.password_confirmation = "The passwords don't match.";
    if (!popiaConsent) {
      local.popia_consent = "Please tick this box to continue. We can't check your bills without your permission.";
    }
    setErrors(local);
    setError(null);
    if (Object.keys(local).length > 0) return;

    setSubmitting(true);
    try {
      await signUp({
        name: name.trim(),
        email: email.trim(),
        password,
        password_confirmation: confirmation,
        popia_consent: true,
        ai_extraction_consent: aiConsent,
      });
    } catch (e) {
      setErrors(fieldErrorsFrom(e));
      setError(e);
      setSubmitting(false);
    }
  };

  return (
    <Screen>
      <AppText variant="muted">It's free to check your bills. You'll need an email address and a password.</AppText>
      <TextField
        label="Your name"
        value={name}
        onChangeText={setName}
        autoComplete="name"
        textContentType="name"
        error={errors.name}
      />
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
        hint="At least 8 characters."
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        autoComplete="new-password"
        textContentType="newPassword"
        error={errors.password}
      />
      <TextField
        label="Confirm password"
        value={confirmation}
        onChangeText={setConfirmation}
        secureTextEntry
        autoComplete="new-password"
        textContentType="newPassword"
        error={errors.password_confirmation}
      />

      <Card>
        <AppText variant="heading" accessibilityRole="header">
          Your privacy
        </AppText>
        <PopiaNotice />
        <Checkbox
          label="I agree that my information may be used this way"
          checked={popiaConsent}
          onChange={setPopiaConsent}
          error={errors.popia_consent}
          testID="popia-consent"
        />
      </Card>

      <Card>
        <AppText variant="heading" accessibilityRole="header">
          AI bill reading (optional)
        </AppText>
        <Checkbox
          label="Read my bills with AI"
          description={aiConsentText(false)}
          checked={aiConsent}
          onChange={setAiConsent}
          error={errors.ai_extraction_consent}
          testID="ai-consent"
        />
      </Card>

      <FormErrorSummary error={error} fieldErrors={errors} shownFields={SHOWN_FIELDS} />
      <Button title="Create account" onPress={submit} loading={submitting} />
      <View style={styles.footer}>
        <AppText variant="muted">Already have an account?</AppText>
        <Button title="Sign in" variant="secondary" onPress={() => goBackOr(routes.signIn)} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  footer: { gap: spacing.sm, marginTop: spacing.md },
});
