import { useQueryClient } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import * as api from '@/api';
import type { Bill, LineItem, SettableFindingStatus } from '@/api';
import { BILL_SHOWN_FIELDS, BillDetailsFields } from '@/components/BillDetailsFields';
import { FindingCard } from '@/components/FindingCard';
import { StartDisputePanel } from '@/components/StartDisputePanel';
import {
  AppText,
  Badge,
  Button,
  Card,
  CenteredScreen,
  DetailRow,
  Divider,
  ErrorState,
  FormErrorSummary,
  LoadingState,
  Notice,
  Screen,
  Section,
} from '@/components/ui';
import { useBill, useMetro, useProperty } from '@/hooks/queries';
import { useRefreshAll } from '@/hooks/useRefreshAll';
import {
  billToDraft,
  lineItemErrorsFromServer,
  validateBillDraft,
  type BillDraft,
  type BillDraftErrors,
} from '@/lib/billDraft';
import { confirmAction, showMessage } from '@/lib/confirm';
import { describeDue, formatDate, isOverdue } from '@/lib/dates';
import { openLocalFile } from '@/lib/files';
import { errorMessage, fieldErrorsFrom } from '@/lib/forms';
import {
  BILL_STATUS_LABELS,
  EXTRACTION_SOURCE_LABELS,
  pluralize,
  READING_TYPE_LABELS,
  SERVICE_LABELS,
  unitLabel,
} from '@/lib/labels';
import { formatRand, formatRandOrDash } from '@/lib/money';
import { formatNumber } from '@/lib/numbers';
import { queryKeys } from '@/lib/queryKeys';
import { goBackOr, routes } from '@/lib/routes';
import { colors, spacing } from '@/theme';

export function BillDetailScreen({ billId }: { billId: number }) {
  const bill = useBill(billId);
  const property = useProperty(bill.data?.property_id ?? 0);
  const { metro } = useMetro(property.data?.metro);
  const queryClient = useQueryClient();
  const refreshAll = useRefreshAll();

  const [editing, setEditing] = useState<boolean | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState<'audit' | 'file' | 'delete' | 'dispute' | null>(null);
  const [findingBusy, setFindingBusy] = useState<number | null>(null);
  const [startingDispute, setStartingDispute] = useState(false);
  const [disputeError, setDisputeError] = useState<string | null>(null);

  if (bill.isPending) {
    return (
      <CenteredScreen>
        <LoadingState label="Loading your bill…" />
      </CenteredScreen>
    );
  }
  if (bill.isError) {
    return (
      <CenteredScreen>
        <ErrorState error={bill.error} onRetry={() => bill.refetch()} />
      </CenteredScreen>
    );
  }

  const b = bill.data;
  const needsLineItems = b.line_items.length === 0 && (b.status === 'needs_review' || b.status === 'extraction_failed');
  const isEditing = editing ?? needsLineItems;
  const openFindings = b.findings.filter((f) => f.status === 'open');
  const title = b.bill_date ? `Bill of ${formatDate(b.bill_date)}` : 'Your bill';

  const applyBill = (updated: Bill) => {
    queryClient.setQueryData(queryKeys.bill(updated.id), updated);
    void refreshAll();
  };

  const recheck = async () => {
    setBusy('audit');
    setNotice(null);
    try {
      applyBill(await api.auditBill(b.id));
      setNotice({ tone: 'success', text: 'We checked your bill again.' });
    } catch (e) {
      setNotice({ tone: 'danger', text: errorMessage(e) });
    } finally {
      setBusy(null);
    }
  };

  const viewOriginal = async () => {
    setBusy('file');
    setNotice(null);
    try {
      const uri = await api.downloadBillFile(b.id, b.file_mime);
      await openLocalFile(uri, b.file_mime ?? 'application/octet-stream', 'Your original bill');
    } catch (e) {
      setNotice({ tone: 'danger', text: `We couldn't open the original file. ${errorMessage(e)}` });
    } finally {
      setBusy(null);
    }
  };

  const changeFindingStatus = async (findingId: number, status: SettableFindingStatus) => {
    setFindingBusy(findingId);
    try {
      await api.updateFinding(findingId, { status });
      await refreshAll();
    } catch (e) {
      showMessage("Couldn't update this finding", errorMessage(e));
    } finally {
      setFindingBusy(null);
    }
  };

  const createDispute = async (findingIds: number[]) => {
    setBusy('dispute');
    setDisputeError(null);
    try {
      const dispute = await api.createDispute(b.id, { finding_ids: findingIds });
      queryClient.setQueryData(queryKeys.dispute(dispute.id), dispute);
      void refreshAll();
      setStartingDispute(false);
      router.push(routes.dispute(dispute.id));
    } catch (e) {
      const fields = fieldErrorsFrom(e);
      setDisputeError(Object.values(fields)[0] ?? errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  const remove = async () => {
    const ok = await confirmAction({
      title: 'Delete this bill?',
      message: "This deletes the bill, its file, its findings and any dispute about it. You can't undo this.",
      confirmLabel: 'Delete bill',
      destructive: true,
    });
    if (!ok) return;
    setBusy('delete');
    try {
      await api.deleteBill(b.id);
      goBackOr(routes.property(b.property_id));
      queryClient.removeQueries({ queryKey: queryKeys.bill(b.id) });
      void refreshAll();
    } catch (e) {
      setBusy(null);
      showMessage("Couldn't delete the bill", errorMessage(e));
    }
  };

  return (
    <Screen refreshing={bill.isRefetching} onRefresh={() => void bill.refetch()}>
      <Stack.Screen options={{ title }} />

      <Card>
        <View style={styles.rowBetween}>
          <AppText variant="title" accessibilityRole="header" style={styles.flex}>
            {title}
          </AppText>
          <Badge
            label={BILL_STATUS_LABELS[b.status]}
            tone={b.status === 'audited' ? 'success' : b.status === 'extraction_failed' ? 'danger' : 'info'}
          />
        </View>
        {property.data ? <AppText variant="muted">{property.data.nickname}</AppText> : null}
        <DetailRow label="Bill date" value={formatDate(b.bill_date, 'Not entered')} />
        <DetailRow
          label="Billing period"
          value={
            b.period_start || b.period_end
              ? `${formatDate(b.period_start)} to ${formatDate(b.period_end)}`
              : 'Not entered'
          }
        />
        <DetailRow label="Due date" value={formatDate(b.due_date, 'Not entered')} />
        <DetailRow label="Current charges" value={formatRandOrDash(b.total_cents, 'Not entered')} />
        {b.extraction_source ? (
          <DetailRow label="Details" value={EXTRACTION_SOURCE_LABELS[b.extraction_source]} />
        ) : null}
        <Divider />
        <DetailRow
          label="Dispute by"
          value={b.dispute_deadline ? formatDate(b.dispute_deadline) : 'Add the bill date to see this'}
        />
        {b.dispute_deadline ? (
          <AppText variant="small" style={isOverdue(b.dispute_deadline) ? styles.overdue : undefined}>
            {describeDue(b.dispute_deadline)}
          </AppText>
        ) : null}
        {b.dispute_deadline && metro && !metro.verified ? (
          <AppText variant="small" testID="unverified-hint">
            This is an unverified default for this municipality. Check the deadline on your bill or with the
            municipality.
          </AppText>
        ) : null}
      </Card>

      {b.status === 'extracting' ? (
        <Notice tone="info" title="Reading your bill">
          We're reading the dates and charges from your file. This usually takes less than a minute, and this screen
          updates by itself.
        </Notice>
      ) : null}
      {b.status === 'extraction_failed' ? (
        <Notice tone="warning" title="We couldn't read this bill automatically">
          Please type in the line items from your bill below so we can check it.
        </Notice>
      ) : null}
      {b.status === 'needs_review' ? (
        <Notice tone="info" title="Next step: type in your line items">
          Copy each charge from your bill below. As soon as you save, we'll check the bill for possible problems.
        </Notice>
      ) : null}
      {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}

      <View style={styles.actions}>
        {b.has_file ? (
          <Button
            title="View original"
            variant="secondary"
            icon="document-attach-outline"
            onPress={viewOriginal}
            loading={busy === 'file'}
          />
        ) : null}
        {b.line_items.length > 0 ? (
          <Button
            title="Re-check bill"
            variant="secondary"
            icon="refresh"
            onPress={recheck}
            loading={busy === 'audit'}
          />
        ) : null}
      </View>

      {isEditing ? (
        <BillEditForm
          bill={b}
          onSaved={(updated) => {
            applyBill(updated);
            setEditing(false);
            setNotice({ tone: 'success', text: 'Saved. We checked your bill again.' });
          }}
          onCancel={b.line_items.length > 0 ? () => setEditing(false) : undefined}
        />
      ) : (
        <Section
          title="Line items"
          action={
            <Button
              title="Edit"
              variant="ghost"
              icon="create-outline"
              accessibilityLabel="Edit bill details and line items"
              onPress={() => setEditing(true)}
            />
          }>
          {b.line_items.length === 0 ? (
            <AppText variant="muted">No line items yet.</AppText>
          ) : (
            <Card>
              {b.line_items.map((item, index) => (
                <View key={item.id} style={styles.lineItemWrap}>
                  {index > 0 ? <Divider /> : null}
                  <LineItemRow item={item} />
                </View>
              ))}
            </Card>
          )}
        </Section>
      )}

      {b.status === 'audited' ? (
        <Section title="Possible problems" description={findingsDescription(b, openFindings.length)}>
          {b.findings.length === 0 ? (
            <Notice tone="success">
              We didn't spot any likely problems on this bill. That doesn't guarantee it's correct, so it's still worth
              comparing the readings with your meter.
            </Notice>
          ) : (
            b.findings.map((finding) => (
              <FindingCard
                key={finding.id}
                finding={finding}
                busy={findingBusy === finding.id}
                onChangeStatus={(status) => changeFindingStatus(finding.id, status)}
              />
            ))
          )}
        </Section>
      ) : null}

      {openFindings.length > 0 ? (
        startingDispute ? (
          <StartDisputePanel
            findings={b.findings}
            onSubmit={createDispute}
            onCancel={() => setStartingDispute(false)}
            submitting={busy === 'dispute'}
            error={disputeError}
          />
        ) : (
          <Button title="Start a dispute" icon="megaphone-outline" onPress={() => setStartingDispute(true)} />
        )
      ) : null}

      <Section title="Delete bill">
        <Button
          title="Delete this bill"
          variant="danger"
          icon="trash-outline"
          onPress={remove}
          loading={busy === 'delete'}
        />
      </Section>
    </Screen>
  );
}

/**
 * The line above the findings. The summary counts only medium and high findings (low ones are
 * information only), so a bill with only low findings gets its own wording instead of "0 possible problems".
 */
function findingsDescription(bill: Bill, openCount: number): string | undefined {
  const { open_count: count, potential_overcharge_cents: overcharge } = bill.findings_summary;
  if (count > 0) {
    return `${pluralize(count, 'possible problem')} to look at${
      overcharge > 0 ? `, with a possible overcharge of ${formatRand(overcharge)}` : ''
    }.`;
  }
  if (openCount > 0) return 'Nothing here looks like it needs action. The notes below are for your information.';
  return undefined;
}

function LineItemRow({ item }: { item: LineItem }) {
  const unit = unitLabel(item.unit);
  const withUnit = (n: number | null) => (n === null ? '' : `${formatNumber(n)}${unit ? ` ${unit}` : ''}`);
  const hasReadings = item.previous_reading !== null || item.current_reading !== null;
  return (
    <View style={styles.lineItem}>
      <View style={styles.rowBetween}>
        <AppText variant="strong" style={styles.flex}>
          {item.description || SERVICE_LABELS[item.service]}
        </AppText>
        <AppText variant="strong">{formatRand(item.amount_cents)}</AppText>
      </View>
      <View style={styles.badges}>
        <Badge label={SERVICE_LABELS[item.service]} />
        {item.reading_type !== 'unknown' ? (
          <Badge
            label={READING_TYPE_LABELS[item.reading_type]}
            tone={item.reading_type === 'estimated' ? 'warning' : 'neutral'}
          />
        ) : null}
      </View>
      {hasReadings ? (
        <AppText variant="small">{`Readings: ${withUnit(item.previous_reading) || '?'} to ${withUnit(item.current_reading) || '?'}`}</AppText>
      ) : null}
      {item.consumption !== null ? <AppText variant="small">{`Usage: ${withUnit(item.consumption)}`}</AppText> : null}
      {item.tariff_category ? <AppText variant="small">{`Tariff: ${item.tariff_category}`}</AppText> : null}
    </View>
  );
}

function BillEditForm({
  bill,
  onSaved,
  onCancel,
}: {
  bill: Bill;
  onSaved: (bill: Bill) => void;
  onCancel?: () => void;
}) {
  const [draft, setDraft] = useState<BillDraft>(() => billToDraft(bill));
  const [errors, setErrors] = useState<BillDraftErrors>({ fields: {}, lineItems: [] });
  const [error, setError] = useState<unknown>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const save = async () => {
    setError(null);
    setMessage(null);
    const result = validateBillDraft(draft, { requireLineItems: true });
    setErrors(result.errors);
    if (!result.valid) {
      setMessage('Please check the highlighted fields.');
      return;
    }
    setSaving(true);
    try {
      onSaved(await api.updateBill(bill.id, result.values));
    } catch (e) {
      const fields = fieldErrorsFrom(e);
      setErrors({ fields, lineItems: lineItemErrorsFromServer(fields, draft.line_items.length) });
      setError(e);
      setSaving(false);
    }
  };

  return (
    <View style={styles.editForm}>
      <BillDetailsFields draft={draft} onChange={setDraft} errors={errors} />
      <FormErrorSummary
        message={message}
        error={message ? undefined : error}
        fieldErrors={errors.fields}
        shownFields={BILL_SHOWN_FIELDS}
      />
      <Button title="Save and check bill" icon="checkmark" onPress={save} loading={saving} />
      {onCancel ? <Button title="Cancel" variant="ghost" onPress={onCancel} /> : null}
    </View>
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
  overdue: { color: colors.danger, fontWeight: '700' },
  actions: { gap: spacing.sm },
  lineItemWrap: { gap: spacing.sm },
  lineItem: { gap: spacing.xs },
  badges: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  editForm: { gap: spacing.lg },
});
