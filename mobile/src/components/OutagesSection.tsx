import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import * as api from '@/api';
import type { Outage, OutageService } from '@/api';
import { useRefreshAll } from '@/hooks/useRefreshAll';
import { confirmAction, showMessage } from '@/lib/confirm';
import { formatDateTime, localDateTimeToIso, parseDateInput, parseTimeInput } from '@/lib/dates';
import { errorMessage, fieldErrorsFrom, type FieldErrors } from '@/lib/forms';
import { OUTAGE_SERVICE_LABELS, OUTAGE_SERVICE_OPTIONS } from '@/lib/labels';
import { spacing } from '@/theme';

import { DateField } from './DateField';
import { AppText, Button, Card, ChoiceChips, FormErrorSummary, Section, TextField } from './ui';

const SHOWN = ['service', 'starts_at', 'ends_at', 'notes'];

/** Water and electricity outages for a property. Adding or removing one re-checks overlapping bills. */
export function OutagesSection({ propertyId, outages }: { propertyId: number; outages: Outage[] }) {
  const refreshAll = useRefreshAll();
  const [adding, setAdding] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const remove = async (outage: Outage) => {
    const ok = await confirmAction({
      title: 'Delete this outage?',
      message: 'Bills from that time will be checked again without it.',
      confirmLabel: 'Delete',
      destructive: true,
    });
    if (!ok) return;
    setDeletingId(outage.id);
    try {
      await api.deleteOutage(outage.id);
      await refreshAll();
    } catch (e) {
      showMessage("Couldn't delete the outage", errorMessage(e));
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <Section
      title="Outages"
      description="If your water or electricity was off for a while, record it here. We'll check whether you were charged for that time.">
      {outages.length === 0 && !adding ? <AppText variant="muted">No outages recorded.</AppText> : null}
      {outages.map((outage) => (
        <Card key={outage.id}>
          <AppText variant="strong">{`${OUTAGE_SERVICE_LABELS[outage.service] ?? outage.service} off`}</AppText>
          <AppText>{`From ${formatDateTime(outage.starts_at)}`}</AppText>
          <AppText>{`Until ${formatDateTime(outage.ends_at)}`}</AppText>
          {outage.notes ? <AppText variant="small">{outage.notes}</AppText> : null}
          <Button
            title="Delete"
            variant="ghost"
            icon="trash-outline"
            loading={deletingId === outage.id}
            accessibilityLabel={`Delete ${OUTAGE_SERVICE_LABELS[outage.service] ?? ''} outage from ${formatDateTime(outage.starts_at)}`}
            onPress={() => remove(outage)}
            style={styles.inlineAction}
          />
        </Card>
      ))}
      {adding ? (
        <OutageForm
          propertyId={propertyId}
          onDone={async () => {
            setAdding(false);
            await refreshAll();
          }}
          onCancel={() => setAdding(false)}
        />
      ) : (
        <Button title="Record an outage" variant="secondary" icon="add" onPress={() => setAdding(true)} />
      )}
    </Section>
  );
}

function OutageForm({
  propertyId,
  onDone,
  onCancel,
}: {
  propertyId: number;
  onDone: () => void;
  onCancel: () => void;
}) {
  const [service, setService] = useState<OutageService | null>(null);
  const [startDate, setStartDate] = useState('');
  const [startTime, setStartTime] = useState('');
  const [endDate, setEndDate] = useState('');
  const [endTime, setEndTime] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    const local: FieldErrors = {};
    if (!service) local.service = 'Choose water or electricity.';
    const start = parseDateInput(startDate);
    const end = parseDateInput(endDate);
    if (!start) local.starts_at = 'Enter the date it went off, like 01/09/2026.';
    if (!end) local.ends_at = 'Enter the date it came back, like 09/09/2026.';
    const startMinutes = startTime.trim() ? parseTimeInput(startTime) : 0;
    const endMinutes = endTime.trim() ? parseTimeInput(endTime) : 23 * 60 + 59;
    if (startMinutes === null) local.start_time = 'Enter a time like 08:00.';
    if (endMinutes === null) local.end_time = 'Enter a time like 18:00.';
    const startsAt = start && startMinutes !== null ? localDateTimeToIso(start, startMinutes) : null;
    const endsAt = end && endMinutes !== null ? localDateTimeToIso(end, endMinutes) : null;
    if (startsAt && endsAt && endsAt <= startsAt) local.ends_at = 'The end must be after the start.';
    setErrors(local);
    setError(null);
    if (Object.keys(local).length > 0 || !service || !startsAt || !endsAt) return;

    setSaving(true);
    try {
      await api.createOutage(propertyId, {
        service,
        starts_at: startsAt,
        ends_at: endsAt,
        notes: notes.trim() || null,
      });
      onDone();
    } catch (e) {
      setErrors(fieldErrorsFrom(e));
      setError(e);
      setSaving(false);
    }
  };

  return (
    <Card>
      <AppText variant="heading">Record an outage</AppText>
      <ChoiceChips
        label="What was off?"
        value={service}
        options={OUTAGE_SERVICE_OPTIONS}
        onChange={setService}
        error={errors.service}
      />
      <DateField label="Went off on" value={startDate} onChangeText={setStartDate} error={errors.starts_at} />
      <TextField
        label="At what time"
        optional
        hint="Leave empty for the start of the day."
        value={startTime}
        onChangeText={setStartTime}
        placeholder="08:00"
        keyboardType="numbers-and-punctuation"
        error={errors.start_time}
      />
      <DateField label="Came back on" value={endDate} onChangeText={setEndDate} error={errors.ends_at} />
      <TextField
        label="At what time"
        optional
        hint="Leave empty for the end of the day."
        value={endTime}
        onChangeText={setEndTime}
        placeholder="18:00"
        keyboardType="numbers-and-punctuation"
        error={errors.end_time}
      />
      <TextField
        label="Notes"
        optional
        hint="For example, a notice from the municipality."
        value={notes}
        onChangeText={setNotes}
        multiline
        error={errors.notes}
      />
      <FormErrorSummary error={error} fieldErrors={errors} shownFields={SHOWN} />
      <View style={styles.actions}>
        <Button title="Save outage" onPress={submit} loading={saving} />
        <Button title="Cancel" variant="ghost" onPress={onCancel} />
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  actions: { gap: spacing.sm },
  inlineAction: { alignSelf: 'flex-start', marginLeft: -spacing.lg },
});
