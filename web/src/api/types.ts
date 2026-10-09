/**
 * Types that mirror docs/api.md (API contract v1) exactly.
 * If the contract changes, change it here and nowhere else.
 *
 * Conventions from the contract:
 * - money is integer cents in fields ending `_cents`
 * - dates are `YYYY-MM-DD` strings, timestamps are ISO-8601 UTC strings
 * - ids are integers
 */

/** `YYYY-MM-DD` */
export type DateString = string
/** ISO-8601 UTC timestamp, e.g. `2026-10-09T05:17:14.000000Z` */
export type Timestamp = string

// ---------------------------------------------------------------------------
// Enums
// ---------------------------------------------------------------------------

export const METROS = [
  'johannesburg',
  'tshwane',
  'cape_town',
  'ethekwini',
  'ekurhuleni',
  'nelson_mandela_bay',
  'buffalo_city',
  'mangaung',
  'other',
] as const
export type MetroCode = (typeof METROS)[number]

export const PROPERTY_TYPES = [
  'residential',
  'sectional_title',
  'bulk_residential',
  'commercial',
  'industrial',
  'agricultural',
  'vacant_land',
] as const
export type PropertyType = (typeof PROPERTY_TYPES)[number]

export const SERVICES = [
  'water',
  'electricity',
  'sewerage',
  'refuse',
  'rates',
  'other',
] as const
export type Service = (typeof SERVICES)[number]

/** Outages can only be recorded for these services. */
export const OUTAGE_SERVICES = ['water', 'electricity'] as const
export type OutageService = (typeof OUTAGE_SERVICES)[number]

export const READING_TYPES = ['actual', 'estimated', 'unknown'] as const
export type ReadingType = (typeof READING_TYPES)[number]

export const UNITS = ['kl', 'kwh'] as const
/** `kl`, `kwh`, or `null` */
export type Unit = (typeof UNITS)[number] | null

export const BILL_STATUSES = [
  'needs_review',
  'extracting',
  'extraction_failed',
  'audited',
] as const
export type BillStatus = (typeof BILL_STATUSES)[number]

export const EXTRACTION_SOURCES = ['manual', 'ai'] as const
export type ExtractionSource = (typeof EXTRACTION_SOURCES)[number]

export const FINDING_RULES = [
  'estimated_reading',
  'consecutive_estimates',
  'tariff_mismatch',
  'outage_charge',
  'consumption_spike',
  'arithmetic_mismatch',
] as const
export type FindingRule = (typeof FINDING_RULES)[number]

export const SEVERITIES = ['low', 'medium', 'high'] as const
export type Severity = (typeof SEVERITIES)[number]

export const FINDING_STATUSES = ['open', 'dismissed', 'disputed'] as const
export type FindingStatus = (typeof FINDING_STATUSES)[number]

export const DISPUTE_STATUSES = [
  'draft',
  'submitted',
  'acknowledged',
  'escalated',
  'resolved',
  'rejected',
] as const
export type DisputeStatus = (typeof DISPUTE_STATUSES)[number]

export const DISPUTE_EVENT_TYPES = [
  'created',
  'letter_edited',
  'submitted',
  'acknowledged',
  'response_received',
  'escalated',
  'resolved',
  'rejected',
  'note',
] as const
export type DisputeEventType = (typeof DISPUTE_EVENT_TYPES)[number]

export const DEADLINE_TYPES = ['lodge_dispute', 'escalate'] as const
export type DeadlineType = (typeof DEADLINE_TYPES)[number]

/** Channels accepted by `POST /disputes/{id}/submit`. */
export const DISPUTE_CHANNELS = [
  'email',
  'portal',
  'walk_in',
  'phone',
  'other',
] as const
export type DisputeChannel = (typeof DISPUTE_CHANNELS)[number]

/** Event types a client may log with `POST /disputes/{id}/events`. */
export const LOGGABLE_EVENT_TYPES = [
  'acknowledged',
  'response_received',
  'note',
] as const
export type LoggableEventType = (typeof LOGGABLE_EVENT_TYPES)[number]

export const DISPUTE_OUTCOMES = ['resolved', 'rejected'] as const
export type DisputeOutcome = (typeof DISPUTE_OUTCOMES)[number]

// ---------------------------------------------------------------------------
// Envelopes and errors
// ---------------------------------------------------------------------------

/** Single resources and collections are wrapped in `{ data }`. */
export interface DataEnvelope<T> {
  data: T
}

/** Laravel's default 422 body. */
export interface ValidationErrorBody {
  message: string
  errors: Record<string, string[]>
}

/** Laravel's default 401/403/404/429 body. */
export interface MessageBody {
  message: string
}

// ---------------------------------------------------------------------------
// Resources
// ---------------------------------------------------------------------------

export interface User {
  id: number
  name: string
  email: string
  popia_consent_version: string
  popia_consented_at: Timestamp
  ai_extraction_consent: boolean
  created_at: Timestamp
}

export interface MetroDisputeChannel {
  type: string
  label: string
  value: string
}

export interface MetroEscalationStep {
  level: number
  name: string
  description: string
}

export interface Metro {
  code: MetroCode
  name: string
  dispute_window_days: number
  response_wait_days: number
  dispute_channels: MetroDisputeChannel[]
  escalation_steps: MetroEscalationStep[]
  /** false = sensible defaults not yet confirmed against the metro's by-law. */
  verified: boolean
}

/** Property as returned in list responses (`GET /properties`). */
export interface PropertySummary {
  id: number
  nickname: string
  metro: MetroCode
  account_number_masked: string
  address: string
  property_type: PropertyType
  bills_count: number
  open_findings_count: number
  created_at: Timestamp
}

/** Property as returned by `GET/POST/PATCH /properties/{id}`. */
export interface Property extends PropertySummary {
  account_number: string
}

export interface LineItem {
  id: number
  service: Service
  description: string
  tariff_category: string | null
  reading_type: ReadingType
  previous_reading: number | null
  current_reading: number | null
  consumption: number | null
  unit: Unit
  amount_cents: number
}

export interface Finding {
  id: number
  bill_id: number
  rule: FindingRule
  severity: Severity
  /** Between 0 and 1. */
  confidence: number
  title: string
  explanation: string
  estimated_overcharge_cents: number | null
  /** Rule-specific object, see docs/audit-rules.md. */
  evidence: Record<string, unknown>
  status: FindingStatus
  created_at: Timestamp
}

export interface FindingsSummary {
  open_count: number
  high_count: number
  potential_overcharge_cents: number
}

/** Bill as returned in collection responses (`GET /properties/{id}/bills`). */
export interface BillSummary {
  id: number
  property_id: number
  bill_date: DateString | null
  period_start: DateString | null
  period_end: DateString | null
  due_date: DateString | null
  /** Current charges for the period, excluding arrears. */
  total_cents: number | null
  status: BillStatus
  extraction_source: ExtractionSource
  has_file: boolean
  file_mime: string | null
  /** bill_date + metro.dispute_window_days; null when bill_date is null. */
  dispute_deadline: DateString | null
  findings_summary: FindingsSummary
  created_at: Timestamp
}

/** Bill as returned by single-bill endpoints. */
export interface Bill extends BillSummary {
  line_items: LineItem[]
  /** Ordered by severity desc then id. */
  findings: Finding[]
}

export interface Outage {
  id: number
  property_id: number
  service: Service
  starts_at: Timestamp
  ends_at: Timestamp
  notes: string | null
  created_at: Timestamp
}

export interface DisputeEvent {
  id: number
  type: DisputeEventType
  note: string | null
  occurred_at: Timestamp
}

export interface DisputeNextStep {
  name: string
  description: string
  due_at: Timestamp
}

/** Dispute as returned in collection responses (`GET /disputes`). */
export interface DisputeSummary {
  id: number
  bill_id: number
  property: { id: number; nickname: string; metro: MetroCode }
  bill: { id: number; bill_date: DateString | null }
  status: DisputeStatus
  finding_ids: number[]
  amount_disputed_cents: number
  letter_subject: string
  channel: DisputeChannel | null
  municipality_reference: string | null
  /** The bill's dispute_deadline. */
  lodge_deadline: DateString | null
  submitted_at: Timestamp | null
  response_due_at: Timestamp | null
  escalation_level: number
  /** Null when resolved/rejected/draft or there is no further step. */
  next_step: DisputeNextStep | null
  outcome_amount_cents: number | null
  resolved_at: Timestamp | null
  created_at: Timestamp
}

/** Dispute as returned by single-dispute endpoints. */
export interface Dispute extends DisputeSummary {
  letter_body: string
  /** Ordered by occurred_at ascending. */
  events: DisputeEvent[]
}

export interface UpcomingDeadline {
  type: DeadlineType
  due_on: DateString
  bill_id: number
  dispute_id: number | null
  property_nickname: string
  label: string
}

export interface Dashboard {
  properties_count: number
  open_findings_count: number
  potential_overcharge_cents: number
  active_disputes_count: number
  recovered_cents: number
  upcoming_deadlines: UpcomingDeadline[]
}

/** Body of `GET /me/export`. */
export interface DataExport {
  exported_at: Timestamp
  user: User
  properties: Array<Property & { bills: Bill[]; outages: Outage[] }>
  disputes: Dispute[]
}

// ---------------------------------------------------------------------------
// Request bodies
// ---------------------------------------------------------------------------

export interface AuthResponse {
  token: string
  user: User
}

export interface RegisterInput {
  name: string
  email: string
  /** min 8 characters */
  password: string
  password_confirmation: string
  /** must be true */
  popia_consent: true
  ai_extraction_consent?: boolean
  device_name?: string
}

export interface LoginInput {
  email: string
  password: string
  device_name?: string
}

export interface UpdateMeInput {
  name?: string
  ai_extraction_consent?: boolean
}

export interface PropertyInput {
  nickname: string
  metro: MetroCode
  account_number: string
  address: string
  property_type: PropertyType
}

export interface OutageInput {
  service: OutageService
  starts_at: Timestamp
  ends_at: Timestamp
  notes?: string | null
}

export interface LineItemInput {
  service: Service
  description?: string | null
  tariff_category?: string | null
  /** defaults to `unknown` */
  reading_type?: ReadingType
  previous_reading?: number | null
  current_reading?: number | null
  /** if null and both readings are present, the server computes current - previous */
  consumption?: number | null
  unit?: Unit
  /** integer >= 0 */
  amount_cents: number
}

/** Allowed upload types: jpeg, png, webp, heic, pdf; max 10 MB. */
export interface CreateBillInput {
  file?: Blob | null
  bill_date?: DateString | null
  period_start?: DateString | null
  period_end?: DateString | null
  due_date?: DateString | null
  total_cents?: number | null
  line_items?: LineItemInput[] | null
}

export interface UpdateBillInput {
  bill_date?: DateString | null
  period_start?: DateString | null
  period_end?: DateString | null
  due_date?: DateString | null
  total_cents?: number | null
  /** Replaces all line items. */
  line_items: LineItemInput[]
}

export interface UpdateFindingInput {
  status: Extract<FindingStatus, 'open' | 'dismissed'>
}

export interface CreateDisputeInput {
  /** Non-empty array of open findings on the bill. */
  finding_ids: number[]
}

export interface UpdateDisputeInput {
  letter_subject?: string
  letter_body?: string
}

export interface SubmitDisputeInput {
  channel: DisputeChannel
  municipality_reference?: string | null
  submitted_at?: Timestamp
}

export interface DisputeEventInput {
  type: LoggableEventType
  note?: string | null
  occurred_at?: Timestamp
}

export interface EscalateDisputeInput {
  note?: string | null
}

export interface ResolveDisputeInput {
  outcome: DisputeOutcome
  outcome_amount_cents?: number | null
  note?: string | null
}
