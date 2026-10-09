import { useQueryClient } from '@tanstack/react-query';
import * as Clipboard from 'expo-clipboard';
import { router, Stack } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import * as api from '@/api';
import type { DisputeChannel, Dispute, DisputeOutcome, LoggableEventType, Metro } from '@/api';
import { MoneyField } from '@/components/MoneyField';
import {
  AppText,
  Badge,
  Button,
  Card,
  CenteredScreen,
  ChoiceChips,
  DetailRow,
  Divider,
  ErrorState,
  FormErrorSummary,
  LoadingState,
  Notice,
  Screen,
  Section,
  SelectField,
  TextField,
} from '@/components/ui';
import { useDispute, useMetro } from '@/hooks/queries';
import { useRefreshAll } from '@/hooks/useRefreshAll';
import { confirmAction, showMessage } from '@/lib/confirm';
import { describeDue, formatDate, formatDateTime, isOverdue } from '@/lib/dates';
import { errorMessage, fieldErrorsFrom, type FieldErrors } from '@/lib/forms';
import {
  DISPUTE_CHANNEL_LABELS,
  DISPUTE_CHANNEL_OPTIONS,
  DISPUTE_EVENT_LABELS,
  DISPUTE_STATUS_LABELS,
  isActiveDispute,
  LOGGABLE_EVENT_OPTIONS,
  type Option,
} from '@/lib/labels';
import { formatRand, parseRandToCents } from '@/lib/money';
import { queryKeys } from '@/lib/queryKeys';
import { goBackOr, routes } from '@/lib/routes';
import { colors, spacing } from '@/theme';

const STATUS_TONES = {
  draft: 'neutral',
  submitted: 'info',
  acknowledged: 'info',
  escalated: 'warning',
  resolved: 'success',
  rejected: 'danger',
} as const;

export function DisputeDetailScreen({ disputeId }: { disputeId: number }) {
  const dispute = useDispute(disputeId);
  const { metro } = useMetro(dispute.data?.property.metro);
  const queryClient = useQueryClient();
  const refreshAll = useRefreshAll();

  if (dispute.isPending) {
    return (
      <CenteredScreen>
        <LoadingState label="Loading your dispute…" />
      </CenteredScreen>
    );
  }
  if (dispute.isError) {
    return (
      <CenteredScreen>
        <ErrorState error={dispute.error} onRetry={() => dispute.refetch()} />
      </CenteredScreen>
    );
  }

  const d = dispute.data;
  const active = isActiveDispute(d.status);
  const final = d.status === 'resolved' || d.status === 'rejected';

  const apply = (updated: Dispute) => {
    queryClient.setQueryData(queryKeys.dispute(updated.id), updated);
    void refreshAll();
  };

  return (
    <Screen refreshing={dispute.isRefetching} onRefresh={() => void dispute.refetch()}>
      <Stack.Screen options={{ title: 'Dispute' }} />

      <Card>
        <View style={styles.rowBetween}>
          <AppText variant="title" accessibilityRole="header" style={styles.flex}>
            {d.property.nickname}
          </AppText>
          <Badge label={DISPUTE_STATUS_LABELS[d.status]} tone={STATUS_TONES[d.status]} />
        </View>
        <Button
          title={d.bill.bill_date ? `Bill of ${formatDate(d.bill.bill_date)}` : 'View the bill'}
          variant="ghost"
          icon="receipt-outline"
          accessibilityLabel="Open the bill for this dispute"
          onPress={() => router.push(routes.bill(d.bill.id))}
          style={styles.inlineAction}
        />
        <DetailRow label="Amount disputed" value={formatRand(d.amount_disputed_cents)} />
        <DetailRow label="Lodge by" value={formatDate(d.lodge_deadline, 'Unknown')} />
        {d.status === 'draft' && d.lodge_deadline ? (
          <AppText variant="small" style={isOverdue(d.lodge_deadline) ? styles.overdue : undefined}>
            {describeDue(d.lodge_deadline)}
          </AppText>
        ) : null}
        {d.submitted_at ? <DetailRow label="Sent on" value={formatDate(d.submitted_at)} /> : null}
        {d.channel ? <DetailRow label="Sent by" value={DISPUTE_CHANNEL_LABELS[d.channel]} /> : null}
        {d.municipality_reference ? (
          <DetailRow label="Municipality reference" value={d.municipality_reference} />
        ) : null}
        {d.response_due_at ? <DetailRow label="Response due" value={formatDate(d.response_due_at)} /> : null}
        {active && d.response_due_at ? (
          <AppText variant="small" style={isOverdue(d.response_due_at) ? styles.overdue : undefined}>
            {isOverdue(d.response_due_at)
              ? "The municipality hasn't responded in time. You can escalate."
              : describeDue(d.response_due_at)}
          </AppText>
        ) : null}
        {d.escalation_level > 0 ? (
          <DetailRow
            label="Escalated to"
            value={
              metro?.escalation_steps.find((s) => s.level === d.escalation_level)?.name ?? `Level ${d.escalation_level}`
            }
          />
        ) : null}
        {final ? (
          <>
            <Divider />
            <DetailRow label="Closed on" value={formatDate(d.resolved_at)} />
            {d.status === 'resolved' ? (
              <DetailRow label="Recovered" value={formatRand(d.outcome_amount_cents ?? 0)} />
            ) : null}
          </>
        ) : null}
      </Card>

      {metro && !metro.verified ? (
        <Notice tone="neutral">
          The deadlines and escalation steps for this municipality are unverified defaults. Check them on your bill or
          with the municipality.
        </Notice>
      ) : null}

      <LetterSection dispute={d} onSaved={apply} onDeleted={() => goBackOr(routes.disputes)} />

      {!final ? <ChannelsSection metro={metro} /> : null}
      {d.status === 'draft' ? <SubmitSection dispute={d} onDone={apply} /> : null}

      {active ? (
        <>
          <LogEventSection dispute={d} onDone={apply} />
          {d.next_step ? <EscalateSection dispute={d} onDone={apply} /> : null}
          <CloseSection dispute={d} onDone={apply} />
        </>
      ) : null}

      <Section title="Timeline">
        <Card>
          {d.events.map((event, index) => (
            <View key={event.id} style={styles.event}>
              {index > 0 ? <Divider /> : null}
              <AppText variant="strong">{DISPUTE_EVENT_LABELS[event.type]}</AppText>
              <AppText variant="small">{formatDateTime(event.occurred_at)}</AppText>
              {event.note ? <AppText>{event.note}</AppText> : null}
            </View>
          ))}
          {d.events.length === 0 ? <AppText variant="muted">Nothing has happened yet.</AppText> : null}
        </Card>
      </Section>
    </Screen>
  );
}

function useCopyFeedback() {
  const [copied, setCopied] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current);
    },
    [],
  );
  const copy = async (text: string) => {
    await Clipboard.setStringAsync(text);
    setCopied(true);
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => setCopied(false), 2500);
  };
  return { copied, copy };
}

function LetterSection({
  dispute,
  onSaved,
  onDeleted,
}: {
  dispute: Dispute;
  onSaved: (d: Dispute) => void;
  onDeleted: () => void;
}) {
  const queryClient = useQueryClient();
  const refreshAll = useRefreshAll();
  const isDraft = dispute.status === 'draft';
  const [subject, setSubject] = useState(dispute.letter_subject);
  const [body, setBody] = useState(dispute.letter_body);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [saved, setSaved] = useState(false);
  const { copied, copy } = useCopyFeedback();

  const changed = subject !== dispute.letter_subject || body !== dispute.letter_body;

  const save = async () => {
    const local: FieldErrors = {};
    if (!subject.trim()) local.letter_subject = 'The letter needs a subject.';
    if (!body.trim()) local.letter_body = "The letter can't be empty.";
    setErrors(local);
    setError(null);
    setSaved(false);
    if (Object.keys(local).length > 0) return;
    setSaving(true);
    try {
      onSaved(await api.updateDispute(dispute.id, { letter_subject: subject, letter_body: body }));
      setSaved(true);
    } catch (e) {
      setErrors(fieldErrorsFrom(e));
      setError(e);
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    const ok = await confirmAction({
      title: 'Delete this draft?',
      message: 'The letter will be deleted and the possible problems it covered will be open again.',
      confirmLabel: 'Delete draft',
      destructive: true,
    });
    if (!ok) return;
    setDeleting(true);
    try {
      await api.deleteDispute(dispute.id);
      onDeleted();
      queryClient.removeQueries({ queryKey: queryKeys.dispute(dispute.id) });
      void refreshAll();
    } catch (e) {
      setDeleting(false);
      showMessage("Couldn't delete the draft", errorMessage(e));
    }
  };

  const copyButton = (
    <Button
      title={copied ? 'Copied' : 'Copy letter'}
      variant="secondary"
      icon={copied ? 'checkmark' : 'copy-outline'}
      accessibilityLabel={copied ? 'Letter copied' : 'Copy letter'}
      onPress={() =>
        void copy(isDraft ? `${subject}\n\n${body}` : `${dispute.letter_subject}\n\n${dispute.letter_body}`)
      }
    />
  );

  if (!isDraft) {
    return (
      <Section title="Your letter">
        <Card>
          <AppText variant="strong" selectable>
            {dispute.letter_subject}
          </AppText>
          <AppText selectable>{dispute.letter_body}</AppText>
        </Card>
        {copyButton}
      </Section>
    );
  }

  return (
    <Section
      title="Your letter"
      description="Read it carefully and change anything that isn't right before you send it.">
      <TextField label="Subject" value={subject} onChangeText={setSubject} error={errors.letter_subject} />
      <TextField
        label="Letter"
        value={body}
        onChangeText={setBody}
        multiline
        style={styles.letter}
        error={errors.letter_body}
      />
      <FormErrorSummary error={error} fieldErrors={errors} shownFields={['letter_subject', 'letter_body']} />
      {saved && !changed ? <Notice tone="success">Your changes are saved.</Notice> : null}
      <Button title="Save changes" icon="save-outline" onPress={save} loading={saving} disabled={!changed} />
      {copyButton}
      <Button title="Delete draft" variant="ghost" icon="trash-outline" onPress={remove} loading={deleting} />
    </Section>
  );
}

function ChannelsSection({ metro }: { metro: Metro | undefined }) {
  if (!metro || metro.dispute_channels.length === 0) return null;
  return (
    <Section title="How to send it" description={`Ways to lodge a dispute with ${metro.name}.`}>
      <Card>
        {metro.dispute_channels.map((channel, index) => (
          <View key={`${channel.type}-${index}`} style={styles.event}>
            {index > 0 ? <Divider /> : null}
            <AppText variant="strong">{channel.label}</AppText>
            <AppText selectable>{channel.value}</AppText>
          </View>
        ))}
      </Card>
      <AppText variant="small">Ask for a reference number when you lodge it, and keep proof that you sent it.</AppText>
    </Section>
  );
}

function SubmitSection({ dispute, onDone }: { dispute: Dispute; onDone: (d: Dispute) => void }) {
  const [channel, setChannel] = useState<DisputeChannel | null>(null);
  const [reference, setReference] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    setError(null);
    if (!channel) {
      setErrors({ channel: 'Choose how you sent the letter.' });
      return;
    }
    setErrors({});
    setSaving(true);
    try {
      onDone(await api.submitDispute(dispute.id, { channel, municipality_reference: reference.trim() || null }));
    } catch (e) {
      setErrors(fieldErrorsFrom(e));
      setError(e);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section
      title="Mark as sent"
      description="Once you've sent the letter to the municipality, record it here so we can track the response deadline.">
      <SelectField
        label="How did you send it?"
        value={channel}
        options={DISPUTE_CHANNEL_OPTIONS}
        onChange={setChannel}
        error={errors.channel}
      />
      <TextField
        label="Municipality reference number"
        optional
        value={reference}
        onChangeText={setReference}
        autoCapitalize="characters"
        error={errors.municipality_reference}
      />
      <FormErrorSummary error={error} fieldErrors={errors} shownFields={['channel', 'municipality_reference']} />
      <Button title="Mark as submitted" icon="send-outline" onPress={submit} loading={saving} />
    </Section>
  );
}

function LogEventSection({ dispute, onDone }: { dispute: Dispute; onDone: (d: Dispute) => void }) {
  const options: Option<LoggableEventType>[] =
    dispute.status === 'submitted'
      ? LOGGABLE_EVENT_OPTIONS
      : LOGGABLE_EVENT_OPTIONS.filter((o) => o.value !== 'acknowledged');
  const [type, setType] = useState<LoggableEventType | null>(null);
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    setError(null);
    if (!type) {
      setErrors({ type: 'Choose what happened.' });
      return;
    }
    if (type === 'note' && !note.trim()) {
      setErrors({ note: 'Write the note you want to keep.' });
      return;
    }
    setErrors({});
    setSaving(true);
    try {
      onDone(await api.addDisputeEvent(dispute.id, { type, note: note.trim() || null }));
      setType(null);
      setNote('');
    } catch (e) {
      setErrors(fieldErrorsFrom(e));
      setError(e);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Log an update" description="Keep a record of what the municipality tells you.">
      <SelectField label="What happened?" value={type} options={options} onChange={setType} error={errors.type} />
      <TextField
        label="Details"
        optional={type !== 'note'}
        value={note}
        onChangeText={setNote}
        multiline
        error={errors.note}
      />
      <FormErrorSummary error={error} fieldErrors={errors} shownFields={['type', 'note']} />
      <Button title="Save update" variant="secondary" icon="add" onPress={submit} loading={saving} />
    </Section>
  );
}

function EscalateSection({ dispute, onDone }: { dispute: Dispute; onDone: (d: Dispute) => void }) {
  const step = dispute.next_step;
  const [note, setNote] = useState('');
  const [error, setError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  if (!step) return null;

  const escalate = async () => {
    const ok = await confirmAction({
      title: `Escalate to ${step.name}?`,
      message: `This records that you've taken your dispute to ${step.name}. We'll start a new response deadline from today.`,
      confirmLabel: 'Escalate',
    });
    if (!ok) return;
    setError(null);
    setSaving(true);
    try {
      onDone(await api.escalateDispute(dispute.id, { note: note.trim() || null }));
      setNote('');
    } catch (e) {
      setError(e);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section
      title="Escalate"
      description="If the municipality doesn't respond in time, you can take it to the next level.">
      <Card>
        <AppText variant="small">Next step</AppText>
        <AppText variant="heading">{step.name}</AppText>
        <AppText>{step.description}</AppText>
        <AppText variant="small">{`You can escalate if there's no response by ${formatDate(step.due_at)}.`}</AppText>
      </Card>
      <TextField label="Note" optional value={note} onChangeText={setNote} multiline />
      <FormErrorSummary error={error} fieldErrors={fieldErrorsFrom(error)} shownFields={['note']} />
      <Button
        title={`Escalate to ${step.name}`}
        variant="secondary"
        icon="arrow-up-circle-outline"
        onPress={escalate}
        loading={saving}
      />
    </Section>
  );
}

const OUTCOME_OPTIONS: Option<DisputeOutcome>[] = [
  { value: 'resolved', label: 'Resolved' },
  { value: 'rejected', label: 'Rejected' },
];

function CloseSection({ dispute, onDone }: { dispute: Dispute; onDone: (d: Dispute) => void }) {
  const [outcome, setOutcome] = useState<DisputeOutcome | null>(null);
  const [amount, setAmount] = useState('');
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    setError(null);
    const local: FieldErrors = {};
    if (!outcome) local.outcome = 'Choose how the dispute ended.';
    const cents = amount.trim() ? parseRandToCents(amount) : null;
    if (amount.trim() && cents === null) local.outcome_amount_cents = 'Enter the amount in rand, like 980.00.';
    setErrors(local);
    if (Object.keys(local).length > 0 || !outcome) return;

    const ok = await confirmAction({
      title: 'Close this dispute?',
      message: "You won't be able to log more updates or escalate it afterwards.",
      confirmLabel: 'Close dispute',
    });
    if (!ok) return;

    setSaving(true);
    try {
      onDone(
        await api.resolveDispute(dispute.id, {
          outcome,
          outcome_amount_cents: outcome === 'resolved' ? cents : null,
          note: note.trim() || null,
        }),
      );
    } catch (e) {
      setErrors(fieldErrorsFrom(e));
      setError(e);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Close dispute" description="When the municipality gives you a final answer, record the outcome.">
      <ChoiceChips
        label="How did it end?"
        value={outcome}
        options={OUTCOME_OPTIONS}
        onChange={setOutcome}
        error={errors.outcome}
      />
      {outcome === 'resolved' ? (
        <MoneyField
          label="Amount recovered"
          optional
          hint="Credits on your account count too."
          value={amount}
          onChangeText={setAmount}
          error={errors.outcome_amount_cents}
        />
      ) : null}
      <TextField label="Note" optional value={note} onChangeText={setNote} multiline error={errors.note} />
      <FormErrorSummary error={error} fieldErrors={errors} shownFields={['outcome', 'outcome_amount_cents', 'note']} />
      <Button
        title="Close dispute"
        variant="secondary"
        icon="checkmark-done-outline"
        onPress={submit}
        loading={saving}
      />
    </Section>
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
  inlineAction: { alignSelf: 'flex-start', marginLeft: -spacing.lg },
  event: { gap: 2 },
  letter: { minHeight: 280 },
});
