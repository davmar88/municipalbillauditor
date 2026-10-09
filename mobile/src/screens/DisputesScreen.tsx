import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import type { DisputeSummary } from '@/api';
import {
  AppText,
  Badge,
  CenteredScreen,
  DetailRow,
  EmptyState,
  ErrorState,
  LoadingState,
  PressableCard,
  Screen,
} from '@/components/ui';
import { useDisputes } from '@/hooks/queries';
import { formatDate, isOverdue } from '@/lib/dates';
import { DISPUTE_STATUS_LABELS, isActiveDispute } from '@/lib/labels';
import { formatRand } from '@/lib/money';
import { routes } from '@/lib/routes';
import { colors, spacing } from '@/theme';

const STATUS_TONES = {
  draft: 'neutral',
  submitted: 'info',
  acknowledged: 'info',
  escalated: 'warning',
  resolved: 'success',
  rejected: 'danger',
} as const;

export function DisputesScreen() {
  const disputes = useDisputes();

  if (disputes.isPending) {
    return (
      <CenteredScreen>
        <LoadingState label="Loading your disputes…" />
      </CenteredScreen>
    );
  }
  if (disputes.isError) {
    return (
      <CenteredScreen>
        <ErrorState error={disputes.error} onRetry={() => disputes.refetch()} />
      </CenteredScreen>
    );
  }

  return (
    <Screen bottomInset={false} refreshing={disputes.isRefetching} onRefresh={() => void disputes.refetch()}>
      {disputes.data.length === 0 ? (
        <EmptyState
          icon="megaphone-outline"
          title="No disputes yet"
          body="When we find a possible problem on one of your bills, you can start a dispute from that bill. We'll draft the letter and track the deadlines for you."
        />
      ) : (
        disputes.data.map((dispute) => <DisputeRow key={dispute.id} dispute={dispute} />)
      )}
    </Screen>
  );
}

function DisputeRow({ dispute }: { dispute: DisputeSummary }) {
  const billDate = dispute.bill.bill_date ? formatDate(dispute.bill.bill_date) : 'date not entered';
  const overdue =
    isActiveDispute(dispute.status) && dispute.response_due_at !== null && isOverdue(dispute.response_due_at);
  const responseDue = dispute.response_due_at
    ? formatDate(dispute.response_due_at)
    : dispute.status === 'draft'
      ? 'Not sent yet'
      : '—';
  return (
    <PressableCard
      onPress={() => router.push(routes.dispute(dispute.id))}
      accessibilityLabel={`${dispute.property.nickname}, bill of ${billDate}, ${DISPUTE_STATUS_LABELS[dispute.status]}, ${formatRand(
        dispute.amount_disputed_cents,
      )} disputed, response due ${responseDue}${overdue ? ', overdue' : ''}`}>
      <View style={styles.rowBetween}>
        <AppText variant="heading" style={styles.flex}>
          {dispute.property.nickname}
        </AppText>
        <Badge label={DISPUTE_STATUS_LABELS[dispute.status]} tone={STATUS_TONES[dispute.status]} />
      </View>
      <AppText variant="small">{`Bill of ${billDate}`}</AppText>
      <DetailRow label="Amount disputed" value={formatRand(dispute.amount_disputed_cents)} />
      <DetailRow label="Response due">
        <AppText variant="strong" style={overdue ? styles.overdue : undefined}>
          {overdue ? `${responseDue} (overdue)` : responseDue}
        </AppText>
      </DetailRow>
    </PressableCard>
  );
}

const styles = StyleSheet.create({
  rowBetween: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.sm,
    flexWrap: 'wrap',
  },
  flex: { flex: 1, minWidth: 140 },
  overdue: { color: colors.danger },
});
