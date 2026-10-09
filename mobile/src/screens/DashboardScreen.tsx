import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import type { PropertySummary, UpcomingDeadline } from '@/api';
import { StatTile } from '@/components/StatTile';
import {
  AppText,
  Badge,
  Button,
  CenteredScreen,
  EmptyState,
  ErrorState,
  LoadingState,
  PressableCard,
  Screen,
  Section,
} from '@/components/ui';
import { useDashboard, useMe, useMetros, useProperties } from '@/hooks/queries';
import { describeDue, formatDate, isOverdue } from '@/lib/dates';
import { METRO_LABELS, pluralize } from '@/lib/labels';
import { formatRand } from '@/lib/money';
import { routes } from '@/lib/routes';
import { spacing } from '@/theme';

export function DashboardScreen() {
  const dashboard = useDashboard();
  const properties = useProperties();
  const me = useMe();
  const metros = useMetros();

  const refresh = () => {
    void dashboard.refetch();
    void properties.refetch();
  };

  if (dashboard.isPending || properties.isPending) {
    return (
      <CenteredScreen>
        <LoadingState label="Loading your dashboard…" />
      </CenteredScreen>
    );
  }
  if (dashboard.isError || properties.isError) {
    return (
      <CenteredScreen>
        <ErrorState error={dashboard.error ?? properties.error} onRetry={refresh} />
      </CenteredScreen>
    );
  }

  const stats = dashboard.data;
  const list = properties.data;
  const firstName = me.data?.name.split(' ')[0];
  const metroName = (code: PropertySummary['metro']) =>
    metros.data?.find((m) => m.code === code)?.name ?? METRO_LABELS[code];

  return (
    <Screen bottomInset={false} refreshing={dashboard.isRefetching || properties.isRefetching} onRefresh={refresh}>
      <AppText variant="title" accessibilityRole="header">
        {firstName ? `Hi, ${firstName}` : 'Your bills at a glance'}
      </AppText>

      <View style={styles.stats}>
        <StatTile
          label="Possible overcharge"
          value={formatRand(stats.potential_overcharge_cents)}
          emphasis
          testID="stat-overcharge"
        />
        <StatTile label="Recovered so far" value={formatRand(stats.recovered_cents)} />
        <StatTile label="Open findings" value={String(stats.open_findings_count)} />
        <StatTile label="Active disputes" value={String(stats.active_disputes_count)} />
      </View>
      <AppText variant="small">
        Totals only include medium and high findings. These are possible problems, not confirmed errors.
      </AppText>

      <Section title="Upcoming deadlines">
        {stats.upcoming_deadlines.length === 0 ? (
          <AppText variant="muted">
            Nothing due right now. Deadlines appear here when a bill has a possible problem or a dispute needs a reply.
          </AppText>
        ) : (
          stats.upcoming_deadlines.map((deadline) => (
            <DeadlineRow key={`${deadline.type}-${deadline.bill_id}-${deadline.dispute_id ?? 0}`} deadline={deadline} />
          ))
        )}
      </Section>

      <Section
        title="Your properties"
        action={
          list.length > 0 ? (
            <Button
              title="Add a property"
              icon="add"
              variant="secondary"
              onPress={() => router.push(routes.newProperty)}
            />
          ) : undefined
        }>
        {list.length === 0 ? (
          <EmptyState
            icon="home-outline"
            title="Add your first property"
            body="Start by adding the property your municipal bill is for. You'll need the account number printed on your bill. Then you can add a bill and we'll check it for possible mistakes."
            actionLabel="Add a property"
            onAction={() => router.push(routes.newProperty)}
          />
        ) : (
          list.map((property) => (
            <PressableCard
              key={property.id}
              onPress={() => router.push(routes.property(property.id))}
              accessibilityLabel={`${property.nickname}, ${metroName(property.metro)}, ${pluralize(property.open_findings_count, 'open finding')}`}
              accessibilityHint="Opens this property's bills and outages">
              <View style={styles.rowBetween}>
                <AppText variant="heading" style={styles.flex}>
                  {property.nickname}
                </AppText>
                {property.open_findings_count > 0 ? (
                  <Badge label={pluralize(property.open_findings_count, 'open finding')} tone="warning" />
                ) : (
                  <Badge label="No open findings" tone="success" />
                )}
              </View>
              <AppText variant="small">{`${metroName(property.metro)} · Account ${property.account_number_masked}`}</AppText>
              <AppText variant="small">{pluralize(property.bills_count, 'bill')}</AppText>
            </PressableCard>
          ))
        )}
      </Section>
    </Screen>
  );
}

function DeadlineRow({ deadline }: { deadline: UpcomingDeadline }) {
  const overdue = isOverdue(deadline.due_on);
  const target = deadline.dispute_id ? routes.dispute(deadline.dispute_id) : routes.bill(deadline.bill_id);
  return (
    <PressableCard
      onPress={() => router.push(target)}
      accessibilityLabel={`${overdue ? 'Overdue. ' : ''}${deadline.label}, ${deadline.property_nickname}, due ${formatDate(deadline.due_on)}`}
      style={overdue ? styles.overdue : undefined}>
      <View style={styles.rowBetween}>
        <Badge label={overdue ? 'Overdue' : describeDue(deadline.due_on)} tone={overdue ? 'danger' : 'info'} />
        <AppText variant="strong">{formatDate(deadline.due_on)}</AppText>
      </View>
      <AppText>{deadline.label}</AppText>
      <AppText variant="small">{deadline.property_nickname}</AppText>
    </PressableCard>
  );
}

const styles = StyleSheet.create({
  stats: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md },
  rowBetween: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.sm,
    flexWrap: 'wrap',
  },
  flex: { flex: 1, minWidth: 140 },
  overdue: { borderColor: '#E7A49D', borderWidth: 2 },
});
