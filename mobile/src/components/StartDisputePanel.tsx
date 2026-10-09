import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import type { Finding } from '@/api';
import { SEVERITY_LABELS } from '@/lib/labels';
import { formatRand } from '@/lib/money';
import { spacing } from '@/theme';

import { AppText, Button, Card, Checkbox, FieldError } from './ui';

/** Lets the person pick which open findings go into a dispute. Medium and high are preselected. */
export function StartDisputePanel({
  findings,
  onSubmit,
  onCancel,
  submitting,
  error,
}: {
  findings: Finding[];
  onSubmit: (findingIds: number[]) => void;
  onCancel: () => void;
  submitting: boolean;
  error?: string | null;
}) {
  const open = findings.filter((f) => f.status === 'open');
  const [ticked, setTicked] = useState<number[]>(() => open.filter((f) => f.severity !== 'low').map((f) => f.id));
  const [localError, setLocalError] = useState<string | null>(null);
  // A finding can be dismissed (or disputed elsewhere) while this panel is open. It then drops out
  // of the list, so it must drop out of the selection too, or the API rejects the whole request.
  const selected = ticked.filter((id) => open.some((f) => f.id === id));

  const toggle = (id: number, checked: boolean) => {
    setTicked((current) => (checked ? [...current, id] : current.filter((x) => x !== id)));
  };

  const submit = () => {
    if (selected.length === 0) {
      setLocalError('Choose at least one possible problem to include.');
      return;
    }
    setLocalError(null);
    onSubmit(selected);
  };

  return (
    <Card>
      <AppText variant="heading" accessibilityRole="header">
        Start a dispute
      </AppText>
      <AppText variant="muted">
        Choose the possible problems to include. We'll draft a letter to the municipality that you can read and change
        before you send it.
      </AppText>
      <View style={styles.list}>
        {open.map((finding) => (
          <Checkbox
            key={finding.id}
            label={finding.title}
            description={`${SEVERITY_LABELS[finding.severity]}${
              finding.estimated_overcharge_cents !== null
                ? ` · possible overcharge ${formatRand(finding.estimated_overcharge_cents)}`
                : ''
            }`}
            checked={selected.includes(finding.id)}
            onChange={(checked) => toggle(finding.id, checked)}
          />
        ))}
      </View>
      <FieldError message={localError ?? error} />
      <Button title="Draft dispute letter" icon="document-text-outline" onPress={submit} loading={submitting} />
      <Button title="Cancel" variant="ghost" onPress={onCancel} />
    </Card>
  );
}

const styles = StyleSheet.create({
  list: { gap: spacing.xs },
});
