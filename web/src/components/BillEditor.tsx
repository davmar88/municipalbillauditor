import { READING_TYPES, SERVICES, type ReadingType, type Service } from '../api'
import type { ReactNode } from 'react'
import {
  defaultUnit,
  emptyLineItem,
  readingsDifference,
  sumDraftAmounts,
  usageAgainstReadings,
  withReading,
  type BillFieldsDraft,
  type LineItemDraft,
} from '../lib/billDraft'
import { formatNumber, formatRand } from '../lib/format'
import { READING_TYPE_LABELS, SERVICE_LABELS, UNIT_OPTION_LABELS, unitLabel } from '../lib/labels'
import { Button } from './Button'
import { MoneyField, SelectField, TextField } from './Field'

// ---------------------------------------------------------------------------
// Bill dates and total
// ---------------------------------------------------------------------------

interface BillFieldsEditorProps {
  value: BillFieldsDraft
  onChange: (value: BillFieldsDraft) => void
  errors: Record<string, string>
}

export function BillFieldsEditor({ value, onChange, errors }: BillFieldsEditorProps) {
  function set<K extends keyof BillFieldsDraft>(key: K, next: BillFieldsDraft[K]) {
    onChange({ ...value, [key]: next })
  }
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <TextField
        label="Bill date"
        optional
        hint="The date printed on the bill (statement date)."
        type="date"
        value={value.bill_date}
        onChange={(e) => set('bill_date', e.target.value)}
        error={errors.bill_date}
      />
      <TextField
        label="Payment due date"
        optional
        type="date"
        hint="When the municipality wants payment."
        value={value.due_date}
        onChange={(e) => set('due_date', e.target.value)}
        error={errors.due_date}
      />
      <TextField
        label="Billing period starts"
        optional
        type="date"
        value={value.period_start}
        onChange={(e) => set('period_start', e.target.value)}
        error={errors.period_start}
      />
      <TextField
        label="Billing period ends"
        optional
        type="date"
        value={value.period_end}
        onChange={(e) => set('period_end', e.target.value)}
        error={errors.period_end}
      />
      <MoneyField
        label="Current charges total"
        optional
        hint="The total for this period only, not including arrears or the opening balance."
        placeholder="0.00"
        value={value.total}
        onChange={(e) => set('total', e.target.value)}
        error={errors.total_cents}
        className="sm:col-span-2"
      />
    </div>
  )
}

// ---------------------------------------------------------------------------
// Line items
// ---------------------------------------------------------------------------

interface LineItemsEditorProps {
  items: LineItemDraft[]
  onChange: (items: LineItemDraft[]) => void
  /** Keys like `line_items.0.amount_cents`. */
  errors: Record<string, string>
}

const METERED: readonly (Service | '')[] = ['water', 'electricity']

/** Explains how the usage relates to the meter readings, so a stale usage is easy to spot. */
function usageHint(item: LineItemDraft, metered: boolean): ReactNode {
  const difference = readingsDifference(item.previous_reading, item.current_reading)
  const unit = item.unit ? ` ${unitLabel(item.unit)}` : ''
  switch (usageAgainstReadings(item)) {
    case 'blank':
      if (difference === null) return metered ? 'Leave blank to work it out from the readings.' : undefined
      if (difference < 0) {
        return 'The current reading is lower than the previous one. Check the readings, or type the usage from your bill.'
      }
      return `Leave blank and we'll work it out from the readings: ${formatNumber(difference)}${unit}.`
    case 'differs':
      return (
        <span className="text-amber-800">
          This doesn't match the readings, which show {formatNumber(difference ?? 0)}
          {unit}. Clear it to use the readings, unless your bill shows this usage.
        </span>
      )
    default:
      return metered ? 'Leave blank to work it out from the readings.' : undefined
  }
}

function LineItemFields({
  item,
  index,
  errors,
  onChange,
  onRemove,
}: {
  item: LineItemDraft
  index: number
  errors: Record<string, string>
  onChange: (item: LineItemDraft) => void
  onRemove: () => void
}) {
  const prefix = `line_items.${index}`
  const err = (field: string) => errors[`${prefix}.${field}`]
  const id = (field: string) => `${item.key}-${field}`

  function set<K extends keyof LineItemDraft>(key: K, value: LineItemDraft[K]) {
    onChange({ ...item, [key]: value })
  }

  function setService(service: Service | '') {
    // Pre-fill the unit when it was empty or still the previous service's usual unit.
    const unit = item.unit === '' || item.unit === defaultUnit(item.service) ? defaultUnit(service) : item.unit
    onChange({ ...item, service, unit })
  }

  const metered = METERED.includes(item.service)

  return (
    <fieldset className="rounded-xl border border-slate-200 bg-white p-4">
      <legend className="px-1 text-sm font-semibold text-slate-900">
        Line item {index + 1}
        {item.service ? `: ${SERVICE_LABELS[item.service]}` : ''}
      </legend>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SelectField
          id={id('service')}
          label="Service"
          value={item.service}
          onChange={(e) => setService(e.target.value as Service | '')}
          error={err('service')}
          required
        >
          <option value="">Choose</option>
          {SERVICES.map((service) => (
            <option key={service} value={service}>
              {SERVICE_LABELS[service]}
            </option>
          ))}
        </SelectField>
        <TextField
          id={id('description')}
          label="Description"
          optional
          placeholder="e.g. Water consumption"
          value={item.description}
          onChange={(e) => set('description', e.target.value)}
          error={err('description')}
          className="sm:col-span-1 lg:col-span-2"
        />
        <MoneyField
          id={id('amount')}
          label="Amount"
          placeholder="0.00"
          value={item.amount}
          onChange={(e) => set('amount', e.target.value)}
          error={err('amount_cents')}
          required
        />
        <TextField
          id={id('tariff')}
          label="Tariff category"
          optional
          placeholder="e.g. Residential"
          value={item.tariff_category}
          onChange={(e) => set('tariff_category', e.target.value)}
          error={err('tariff_category')}
          className="lg:col-span-2"
        />
        <SelectField
          id={id('reading-type')}
          label="Reading type"
          value={item.reading_type}
          onChange={(e) => set('reading_type', e.target.value as ReadingType)}
          error={err('reading_type')}
          className="lg:col-span-2"
        >
          {READING_TYPES.map((type) => (
            <option key={type} value={type}>
              {READING_TYPE_LABELS[type]}
            </option>
          ))}
        </SelectField>
        <TextField
          id={id('previous')}
          label="Previous reading"
          optional
          inputMode="decimal"
          value={item.previous_reading}
          onChange={(e) => onChange(withReading(item, 'previous_reading', e.target.value))}
          error={err('previous_reading')}
        />
        <TextField
          id={id('current')}
          label="Current reading"
          optional
          inputMode="decimal"
          value={item.current_reading}
          onChange={(e) => onChange(withReading(item, 'current_reading', e.target.value))}
          error={err('current_reading')}
        />
        <TextField
          id={id('consumption')}
          label="Usage"
          optional
          inputMode="decimal"
          hint={usageHint(item, metered)}
          value={item.consumption}
          onChange={(e) => set('consumption', e.target.value)}
          error={err('consumption')}
        />
        <SelectField
          id={id('unit')}
          label="Unit"
          value={item.unit}
          onChange={(e) => set('unit', e.target.value as LineItemDraft['unit'])}
          error={err('unit')}
        >
          <option value="">{UNIT_OPTION_LABELS['']}</option>
          <option value="kl">{UNIT_OPTION_LABELS.kl}</option>
          <option value="kwh">{UNIT_OPTION_LABELS.kwh}</option>
        </SelectField>
      </div>
      <div className="mt-4 flex justify-end">
        <Button variant="ghost" size="sm" onClick={onRemove} aria-label={`Remove line item ${index + 1}`}>
          Remove
        </Button>
      </div>
    </fieldset>
  )
}

export function LineItemsEditor({ items, onChange, errors }: LineItemsEditorProps) {
  const total = sumDraftAmounts(items)
  return (
    <div className="space-y-4">
      {items.map((item, index) => (
        <LineItemFields
          key={item.key}
          item={item}
          index={index}
          errors={errors}
          onChange={(next) => onChange(items.map((existing, i) => (i === index ? next : existing)))}
          onRemove={() => onChange(items.filter((_, i) => i !== index))}
        />
      ))}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <Button variant="secondary" onClick={() => onChange([...items, emptyLineItem()])}>
          {items.length === 0 ? 'Add a line item' : 'Add another line item'}
        </Button>
        {items.length > 0 && (
          <p className="text-sm text-slate-600" aria-live="polite">
            Line items add up to <span className="font-semibold text-slate-900 tabular-nums">{formatRand(total)}</span>
          </p>
        )}
      </div>
    </div>
  )
}
