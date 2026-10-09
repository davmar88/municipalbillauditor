import { StyleSheet, View } from 'react-native';

import type { Finding, SettableFindingStatus, Severity } from '@/api';
import { FINDING_STATUS_LABELS, SEVERITY_LABELS } from '@/lib/labels';
import { formatRand } from '@/lib/money';
import { formatPercent } from '@/lib/numbers';
import { colors, radius, spacing } from '@/theme';

import { AppText, Badge, Button, type Tone } from './ui';

const SEVERITY_TONES: Record<Severity, Tone> = { high: 'danger', medium: 'warning', low: 'neutral' };
const SEVERITY_BORDER: Record<Severity, string> = { high: colors.danger, medium: colors.warning, low: colors.border };

export interface FindingCardProps {
  finding: Finding;
  /** Called with the new status when the person dismisses or reopens the finding. */
  onChangeStatus: (status: SettableFindingStatus) => void;
  busy?: boolean;
}

/**
 * One possible problem on a bill. Low-severity findings are informational, so they're
 * visually quieter and labelled "For information".
 */
export function FindingCard({ finding, onChangeStatus, busy = false }: FindingCardProps) {
  const isLow = finding.severity === 'low';
  const dismissed = finding.status === 'dismissed';
  const statusTone: Tone = finding.status === 'disputed' ? 'info' : dismissed ? 'neutral' : 'success';

  return (
    <View
      style={[
        styles.card,
        { borderLeftColor: SEVERITY_BORDER[finding.severity] },
        isLow && styles.low,
        dismissed && styles.dismissed,
      ]}
      testID={`finding-${finding.id}`}>
      <View style={styles.badges}>
        <Badge label={SEVERITY_LABELS[finding.severity]} tone={SEVERITY_TONES[finding.severity]} />
        <Badge label={FINDING_STATUS_LABELS[finding.status]} tone={statusTone} />
        <AppText variant="small">{`Confidence ${formatPercent(finding.confidence)}`}</AppText>
      </View>

      <AppText variant={isLow ? 'strong' : 'heading'} style={isLow ? styles.lowText : undefined}>
        {finding.title}
      </AppText>
      <AppText variant={isLow ? 'small' : 'body'}>{finding.explanation}</AppText>

      {finding.estimated_overcharge_cents !== null ? (
        <AppText variant="strong" style={isLow ? styles.lowText : undefined}>
          {`Possible overcharge: ${formatRand(finding.estimated_overcharge_cents)}`}
        </AppText>
      ) : null}

      {finding.status === 'open' ? (
        <Button
          title="Dismiss"
          variant="ghost"
          icon="eye-off-outline"
          loading={busy}
          accessibilityLabel={`Dismiss: ${finding.title}`}
          accessibilityHint="Hides this from your totals. You can reopen it later."
          onPress={() => onChangeStatus('dismissed')}
          style={styles.action}
        />
      ) : null}
      {finding.status === 'dismissed' ? (
        <Button
          title="Reopen"
          variant="ghost"
          icon="refresh"
          loading={busy}
          accessibilityLabel={`Reopen: ${finding.title}`}
          onPress={() => onChangeStatus('open')}
          style={styles.action}
        />
      ) : null}
      {finding.status === 'disputed' ? <AppText variant="small">This is part of a dispute.</AppText> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    borderLeftWidth: 5,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  low: { backgroundColor: colors.surfaceMuted },
  lowText: { color: colors.textMuted },
  dismissed: { backgroundColor: colors.surfaceMuted },
  badges: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: spacing.sm },
  action: { alignSelf: 'flex-start', marginLeft: -spacing.lg },
});
