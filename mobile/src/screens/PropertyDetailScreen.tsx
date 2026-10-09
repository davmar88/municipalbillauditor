import { useQueryClient } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import * as api from '@/api';
import type { BillSummary } from '@/api';
import { OutagesSection } from '@/components/OutagesSection';
import {
  AppText,
  Badge,
  Button,
  Card,
  CenteredScreen,
  DetailRow,
  EmptyState,
  ErrorState,
  LoadingState,
  PressableCard,
  Screen,
  Section,
} from '@/components/ui';
import { useMetro, useProperty, usePropertyBills, usePropertyOutages } from '@/hooks/queries';
import { useRefreshAll } from '@/hooks/useRefreshAll';
import { confirmAction, showMessage } from '@/lib/confirm';
import { formatDate } from '@/lib/dates';
import { errorMessage } from '@/lib/forms';
import { BILL_STATUS_LABELS, METRO_LABELS, PROPERTY_TYPE_LABELS, pluralize } from '@/lib/labels';
import { formatRand, formatRandOrDash } from '@/lib/money';
import { queryKeys } from '@/lib/queryKeys';
import { goBackOr, routes } from '@/lib/routes';
import { spacing } from '@/theme';

export function PropertyDetailScreen({ propertyId }: { propertyId: number }) {
  const property = useProperty(propertyId);
  const bills = usePropertyBills(propertyId);
  const outages = usePropertyOutages(propertyId);
  const { metro } = useMetro(property.data?.metro);
  const queryClient = useQueryClient();
  const refreshAll = useRefreshAll();
  const [showAccount, setShowAccount] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const refresh = () => {
    void property.refetch();
    void bills.refetch();
    void outages.refetch();
  };

  if (property.isPending || bills.isPending || outages.isPending) {
    return (
      <CenteredScreen>
        <LoadingState />
      </CenteredScreen>
    );
  }
  if (property.isError || bills.isError || outages.isError) {
    return (
      <CenteredScreen>
        <ErrorState error={property.error ?? bills.error ?? outages.error} onRetry={refresh} />
      </CenteredScreen>
    );
  }

  const p = property.data;

  const remove = async () => {
    const ok = await confirmAction({
      title: `Delete ${p.nickname}?`,
      message:
        "This permanently deletes the property with all its bills, uploaded files, findings, outages and disputes. You can't undo this.",
      confirmLabel: 'Delete property',
      destructive: true,
    });
    if (!ok) return;
    setDeleting(true);
    try {
      await api.deleteProperty(p.id);
      goBackOr(routes.dashboard);
      queryClient.removeQueries({ queryKey: queryKeys.property(p.id) });
      void refreshAll();
    } catch (e) {
      setDeleting(false);
      showMessage("Couldn't delete the property", errorMessage(e));
    }
  };

  return (
    <Screen refreshing={property.isRefetching || bills.isRefetching} onRefresh={refresh}>
      <Stack.Screen options={{ title: p.nickname }} />
      <Card>
        <AppText variant="title" accessibilityRole="header">
          {p.nickname}
        </AppText>
        <DetailRow label="Municipality" value={metro?.name ?? METRO_LABELS[p.metro]} />
        <DetailRow label="Type" value={PROPERTY_TYPE_LABELS[p.property_type]} />
        <DetailRow label="Address" value={p.address} />
        <DetailRow label="Account number">
          <View style={styles.account}>
            <AppText variant="strong">{showAccount ? p.account_number : p.account_number_masked}</AppText>
            <Button
              title={showAccount ? 'Hide' : 'Show'}
              variant="ghost"
              accessibilityLabel={showAccount ? 'Hide account number' : 'Show full account number'}
              onPress={() => setShowAccount((v) => !v)}
            />
          </View>
        </DetailRow>
        <Button
          title="Edit details"
          variant="secondary"
          icon="create-outline"
          onPress={() => router.push(routes.editProperty(p.id))}
        />
      </Card>

      <Section
        title="Bills"
        action={
          bills.data.length > 0 ? (
            <Button title="Add a bill" icon="add" onPress={() => router.push(routes.addBill(p.id))} />
          ) : undefined
        }>
        {bills.data.length === 0 ? (
          <EmptyState
            title="No bills yet"
            body="Add your latest municipal bill. Take a photo, upload a PDF or type in the charges, and we'll check it for possible mistakes."
            actionLabel="Add a bill"
            onAction={() => router.push(routes.addBill(p.id))}
          />
        ) : (
          bills.data.map((bill) => <BillRow key={bill.id} bill={bill} />)
        )}
      </Section>

      <OutagesSection propertyId={p.id} outages={outages.data} />

      <Section title="Delete property">
        <AppText variant="small">This removes the property and everything linked to it from our systems.</AppText>
        <Button
          title="Delete this property"
          variant="danger"
          icon="trash-outline"
          onPress={remove}
          loading={deleting}
        />
      </Section>
    </Screen>
  );
}

function billTitle(bill: BillSummary): string {
  return bill.bill_date ? `Bill of ${formatDate(bill.bill_date)}` : `Bill added ${formatDate(bill.created_at)}`;
}

function BillRow({ bill }: { bill: BillSummary }) {
  const summary = bill.findings_summary;
  const statusTone = bill.status === 'audited' ? 'success' : bill.status === 'extraction_failed' ? 'danger' : 'info';
  const findingsText =
    summary.open_count === 0
      ? 'No open findings'
      : `${pluralize(summary.open_count, 'open finding')}${summary.high_count > 0 ? `, ${summary.high_count} high` : ''}`;
  return (
    <PressableCard
      onPress={() => router.push(routes.bill(bill.id))}
      accessibilityLabel={`${billTitle(bill)}, total ${formatRandOrDash(bill.total_cents, 'not entered')}, ${BILL_STATUS_LABELS[bill.status]}, ${findingsText}`}>
      <View style={styles.rowBetween}>
        <AppText variant="heading" style={styles.flex}>
          {billTitle(bill)}
        </AppText>
        <Badge label={BILL_STATUS_LABELS[bill.status]} tone={statusTone} />
      </View>
      <DetailRow label="Total" value={formatRandOrDash(bill.total_cents, 'Not entered')} />
      <View style={styles.badges}>
        <Badge
          label={findingsText}
          tone={summary.high_count > 0 ? 'danger' : summary.open_count > 0 ? 'warning' : 'neutral'}
        />
        {summary.potential_overcharge_cents > 0 ? (
          <AppText variant="small">{`Possible overcharge ${formatRand(summary.potential_overcharge_cents)}`}</AppText>
        ) : null}
      </View>
    </PressableCard>
  );
}

const styles = StyleSheet.create({
  account: { flexDirection: 'row', alignItems: 'center', gap: spacing.xs },
  rowBetween: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.sm,
    flexWrap: 'wrap',
  },
  flex: { flex: 1, minWidth: 140 },
  badges: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: spacing.sm },
});
