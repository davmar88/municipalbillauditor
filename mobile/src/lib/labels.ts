/** Human labels for every enum the UI shows. Plain words, no jargon. */
import type {
  BillStatus,
  DeadlineType,
  DisputeChannel,
  DisputeEventType,
  DisputeStatus,
  ExtractionSource,
  FindingRule,
  FindingStatus,
  LoggableEventType,
  MetroCode,
  OutageService,
  PropertyType,
  ReadingType,
  Service,
  Severity,
  Unit,
} from '@/api';

export type Option<T extends string> = { value: T; label: string; description?: string };

function toOptions<T extends string>(labels: Record<T, string>): Option<T>[] {
  return (Object.keys(labels) as T[]).map((value) => ({ value, label: labels[value] }));
}

/** Fallback names; the full official name comes from GET /metros. */
export const METRO_LABELS: Record<MetroCode, string> = {
  johannesburg: 'City of Johannesburg',
  tshwane: 'City of Tshwane',
  cape_town: 'City of Cape Town',
  ethekwini: 'eThekwini (Durban)',
  ekurhuleni: 'Ekurhuleni',
  nelson_mandela_bay: 'Nelson Mandela Bay',
  buffalo_city: 'Buffalo City',
  mangaung: 'Mangaung',
  other: 'Another municipality',
};

export const PROPERTY_TYPE_LABELS: Record<PropertyType, string> = {
  residential: 'House (single home)',
  sectional_title: 'Sectional title unit (flat or townhouse)',
  bulk_residential: 'Whole complex or block (body corporate)',
  commercial: 'Business premises',
  industrial: 'Industrial premises',
  agricultural: 'Farm or smallholding',
  vacant_land: 'Empty plot (vacant land)',
};
export const PROPERTY_TYPE_OPTIONS = toOptions(PROPERTY_TYPE_LABELS);

export const SERVICE_LABELS: Record<Service, string> = {
  water: 'Water',
  electricity: 'Electricity',
  sewerage: 'Sewerage',
  refuse: 'Refuse removal',
  rates: 'Property rates',
  other: 'Other',
};
export const SERVICE_OPTIONS = toOptions(SERVICE_LABELS);

export const OUTAGE_SERVICE_LABELS: Record<OutageService, string> = {
  water: 'Water',
  electricity: 'Electricity',
};
export const OUTAGE_SERVICE_OPTIONS = toOptions(OUTAGE_SERVICE_LABELS);

export const READING_TYPE_LABELS: Record<ReadingType, string> = {
  actual: 'Actual reading',
  estimated: 'Estimated reading',
  unknown: "Not sure / doesn't say",
};
export const READING_TYPE_OPTIONS = toOptions(READING_TYPE_LABELS);

export type UnitChoice = 'kl' | 'kwh' | 'none';
export const UNIT_CHOICE_LABELS: Record<UnitChoice, string> = {
  none: 'No unit',
  kl: 'kl (water)',
  kwh: 'kWh (electricity)',
};
export const UNIT_OPTIONS = toOptions(UNIT_CHOICE_LABELS);

export function unitLabel(unit: Unit): string {
  if (unit === 'kl') return 'kl';
  if (unit === 'kwh') return 'kWh';
  return '';
}

export const BILL_STATUS_LABELS: Record<BillStatus, string> = {
  needs_review: 'Waiting for your line items',
  extracting: 'Reading your bill',
  extraction_failed: "Couldn't read automatically",
  audited: 'Checked',
};

export const EXTRACTION_SOURCE_LABELS: Record<ExtractionSource, string> = {
  manual: 'Typed in by you',
  ai: 'Read from your upload by AI',
};

export const FINDING_RULE_LABELS: Record<FindingRule, string> = {
  estimated_reading: 'Estimated reading',
  consecutive_estimates: 'Estimated several months in a row',
  tariff_mismatch: 'Possibly the wrong tariff',
  outage_charge: 'Charged while supply was off',
  consumption_spike: 'Usage much higher than usual',
  arithmetic_mismatch: "Total doesn't match the line items",
};

export const SEVERITY_LABELS: Record<Severity, string> = {
  high: 'High',
  medium: 'Medium',
  low: 'For information',
};

export const FINDING_STATUS_LABELS: Record<FindingStatus, string> = {
  open: 'Open',
  dismissed: 'Dismissed',
  disputed: 'In a dispute',
};

export const DISPUTE_STATUS_LABELS: Record<DisputeStatus, string> = {
  draft: 'Draft',
  submitted: 'Sent',
  acknowledged: 'Acknowledged',
  escalated: 'Escalated',
  resolved: 'Resolved',
  rejected: 'Rejected',
};

export const DISPUTE_EVENT_LABELS: Record<DisputeEventType, string> = {
  created: 'Dispute started',
  letter_edited: 'Letter edited',
  submitted: 'Sent to the municipality',
  acknowledged: 'Municipality acknowledged it',
  response_received: 'Response received',
  escalated: 'Escalated',
  resolved: 'Closed as resolved',
  rejected: 'Closed as rejected',
  note: 'Note',
};

export const LOGGABLE_EVENT_LABELS: Record<LoggableEventType, string> = {
  acknowledged: 'The municipality acknowledged my dispute',
  response_received: 'I received a response',
  note: 'Just a note for my records',
};
export const LOGGABLE_EVENT_OPTIONS = toOptions(LOGGABLE_EVENT_LABELS);

export const DISPUTE_CHANNEL_LABELS: Record<DisputeChannel, string> = {
  email: 'Email',
  portal: 'Online portal',
  walk_in: 'In person at a walk-in centre',
  phone: 'Phone call',
  other: 'Another way',
};
export const DISPUTE_CHANNEL_OPTIONS = toOptions(DISPUTE_CHANNEL_LABELS);

export const DEADLINE_TYPE_LABELS: Record<DeadlineType, string> = {
  lodge_dispute: 'Lodge a dispute',
  escalate: 'Escalate',
};

/** Dispute statuses where the municipality still owes a response. */
export const ACTIVE_DISPUTE_STATUSES: readonly DisputeStatus[] = ['submitted', 'acknowledged', 'escalated'];

export function isActiveDispute(status: DisputeStatus): boolean {
  return ACTIVE_DISPUTE_STATUSES.includes(status);
}

export function pluralize(count: number, singular: string, plural = `${singular}s`): string {
  return `${count} ${count === 1 ? singular : plural}`;
}
