import { StyleSheet, View } from 'react-native';

import type { BillDraft, BillDraftErrors } from '@/lib/billDraft';
import { spacing } from '@/theme';

import { DateField } from './DateField';
import { LineItemsEditor } from './LineItemsEditor';
import { MoneyField } from './MoneyField';
import { Section } from './ui';

/** Dates, total and line items of a bill. Used when adding a bill and when correcting one. */
export function BillDetailsFields({
  draft,
  onChange,
  errors,
}: {
  draft: BillDraft;
  onChange: (draft: BillDraft) => void;
  errors: BillDraftErrors;
}) {
  const set = (patch: Partial<BillDraft>) => onChange({ ...draft, ...patch });
  return (
    <>
      <Section
        title="Bill dates and total"
        description="Copy these from the top of your bill. You can leave out anything you can't find.">
        <View style={styles.fields}>
          <DateField
            label="Bill date"
            optional
            value={draft.bill_date}
            onChangeText={(bill_date) => set({ bill_date })}
            error={errors.fields.bill_date}
          />
          <DateField
            label="Billing period starts"
            optional
            value={draft.period_start}
            onChangeText={(period_start) => set({ period_start })}
            error={errors.fields.period_start}
          />
          <DateField
            label="Billing period ends"
            optional
            value={draft.period_end}
            onChangeText={(period_end) => set({ period_end })}
            error={errors.fields.period_end}
          />
          <DateField
            label="Due date"
            optional
            value={draft.due_date}
            onChangeText={(due_date) => set({ due_date })}
            error={errors.fields.due_date}
          />
          <MoneyField
            label="Current charges total"
            optional
            hint="The total for this period only, without arrears or your opening balance."
            value={draft.total}
            onChangeText={(total) => set({ total })}
            error={errors.fields.total_cents}
          />
        </View>
      </Section>
      <Section title="Line items" description="Each charge on your bill, with its amount.">
        <LineItemsEditor
          items={draft.line_items}
          onChange={(line_items) => set({ line_items })}
          errors={errors.lineItems}
          listError={errors.fields.line_items}
        />
      </Section>
    </>
  );
}

export const BILL_SHOWN_FIELDS = [
  'bill_date',
  'period_start',
  'period_end',
  'due_date',
  'total_cents',
  'line_items',
  'file',
] as const;

const styles = StyleSheet.create({
  fields: { gap: spacing.md },
});
