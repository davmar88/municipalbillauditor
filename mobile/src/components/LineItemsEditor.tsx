import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import type { Service } from '@/api';
import {
  emptyLineItemDraft,
  showsReadings,
  updateLineItemDraft,
  usageHint,
  type LineItemDraft,
  type LineItemErrors,
  type LineItemPatch,
} from '@/lib/billDraft';
import { READING_TYPE_OPTIONS, SERVICE_LABELS, SERVICE_OPTIONS, UNIT_OPTIONS, unitLabel } from '@/lib/labels';
import { numberReadBack } from '@/lib/numbers';
import { spacing } from '@/theme';

import { MoneyField } from './MoneyField';
import { AppText, Button, Card, ChoiceChips, FieldError, SelectField, TextField } from './ui';

/** Rows of line items as typed by the person, one card per charge on the bill. */
export function LineItemsEditor({
  items,
  onChange,
  errors,
  listError,
}: {
  items: LineItemDraft[];
  onChange: (items: LineItemDraft[]) => void;
  errors: LineItemErrors[];
  listError?: string;
}) {
  // Readings show automatically for water and electricity; other lines can open them on request.
  const [expanded, setExpanded] = useState<string[]>([]);

  const update = (index: number, patch: LineItemPatch) => {
    onChange(items.map((item, i) => (i === index ? updateLineItemDraft(item, patch) : item)));
  };

  const setService = (index: number, service: Service) => {
    const item = items[index];
    const patch: LineItemPatch = { service };
    // Helpful default: water is in kl and electricity in kWh, unless the person already chose.
    if (item.unit === 'none' && service === 'water') patch.unit = 'kl';
    if (item.unit === 'none' && service === 'electricity') patch.unit = 'kwh';
    update(index, patch);
  };

  return (
    <View style={styles.list}>
      {items.length === 0 ? (
        <AppText variant="muted">
          A line item is one charge on your bill, like water, electricity or rates. Add one for each charge you want us
          to check.
        </AppText>
      ) : null}

      {items.map((item, index) => {
        const rowErrors = errors[index] ?? {};
        const name = item.service ? SERVICE_LABELS[item.service] : `Line ${index + 1}`;
        const unit = unitLabel(item.unit === 'none' ? null : item.unit);
        return (
          <Card key={item.key} testID={`line-item-${index}`}>
            <View style={styles.header}>
              <AppText variant="heading" style={styles.flex}>
                {`Line ${index + 1}${item.service ? `: ${SERVICE_LABELS[item.service]}` : ''}`}
              </AppText>
              <Button
                title="Remove"
                variant="ghost"
                icon="trash-outline"
                accessibilityLabel={`Remove ${name}`}
                onPress={() => onChange(items.filter((_, i) => i !== index))}
              />
            </View>
            <SelectField
              label="Service"
              value={item.service}
              options={SERVICE_OPTIONS}
              onChange={(service) => setService(index, service)}
              error={rowErrors.service}
            />
            <TextField
              label="Description"
              optional
              value={item.description}
              onChangeText={(description) => update(index, { description })}
              placeholder="e.g. Water consumption"
              error={rowErrors.description}
            />
            <TextField
              label="Tariff category"
              optional
              hint="As printed on the bill, e.g. Residential or Business."
              value={item.tariff_category}
              onChangeText={(tariff_category) => update(index, { tariff_category })}
              error={rowErrors.tariff_category}
            />
            {showsReadings(item) || expanded.includes(item.key) ? (
              <>
                <SelectField
                  label="Type of meter reading"
                  value={item.reading_type}
                  options={READING_TYPE_OPTIONS}
                  onChange={(reading_type) => update(index, { reading_type })}
                  hint="Bills usually mark readings as actual (A) or estimated (E)."
                  error={rowErrors.reading_type}
                />
                <View style={styles.row}>
                  <View style={styles.flex}>
                    <TextField
                      label="Previous reading"
                      optional
                      hint={numberReadBack(item.previous_reading, unit) ?? undefined}
                      value={item.previous_reading}
                      onChangeText={(previous_reading) => update(index, { previous_reading })}
                      keyboardType="decimal-pad"
                      error={rowErrors.previous_reading}
                    />
                  </View>
                  <View style={styles.flex}>
                    <TextField
                      label="Current reading"
                      optional
                      hint={numberReadBack(item.current_reading, unit) ?? undefined}
                      value={item.current_reading}
                      onChangeText={(current_reading) => update(index, { current_reading })}
                      keyboardType="decimal-pad"
                      error={rowErrors.current_reading}
                    />
                  </View>
                </View>
                <TextField
                  label="Usage"
                  optional
                  hint={usageHint(item)}
                  value={item.consumption}
                  onChangeText={(consumption) => update(index, { consumption })}
                  keyboardType="decimal-pad"
                  error={rowErrors.consumption}
                />
                <ChoiceChips
                  label="Unit"
                  value={item.unit}
                  options={UNIT_OPTIONS}
                  onChange={(unit) => update(index, { unit })}
                  error={rowErrors.unit}
                />
              </>
            ) : (
              <Button
                title="Add meter readings or usage"
                variant="ghost"
                icon="speedometer-outline"
                accessibilityLabel={`Add meter readings or usage for line ${index + 1}`}
                onPress={() => setExpanded((keys) => [...keys, item.key])}
                style={styles.inlineAction}
              />
            )}
            <MoneyField
              label="Amount"
              value={item.amount}
              onChangeText={(amount) => update(index, { amount })}
              error={rowErrors.amount}
            />
          </Card>
        );
      })}

      <FieldError message={listError} />
      <Button
        title={items.length === 0 ? 'Add a line from your bill' : 'Add another line'}
        variant="secondary"
        icon="add"
        onPress={() => onChange([...items, emptyLineItemDraft()])}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  list: { gap: spacing.md },
  header: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  row: { flexDirection: 'row', gap: spacing.md, flexWrap: 'wrap' },
  flex: { flex: 1, minWidth: 130 },
  inlineAction: { alignSelf: 'flex-start', marginLeft: -spacing.lg },
});
