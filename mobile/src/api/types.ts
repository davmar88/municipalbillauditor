/**
 * Types for API v1. These mirror docs/api.md exactly: field names, enums and nullability.
 * If the contract changes, change it here and nowhere else.
 */

// ---------------------------------------------------------------------------
// Enums
// ---------------------------------------------------------------------------

export type MetroCode =
  | 'johannesburg'
  | 'tshwane'
  | 'cape_town'
  | 'ethekwini'
  | 'ekurhuleni'
  | 'nelson_mandela_bay'
  | 'buffalo_city'
  | 'mangaung'
  | 'other';

export type PropertyType =
  'residential' | 'sectional_title' | 'bulk_residential' | 'commercial' | 'industrial' | 'agricultural' | 'vacant_land';

export type Service = 'water' | 'electricity' | 'sewerage' | 'refuse' | 'rates' | 'other';

export type ReadingType = 'actual' | 'estimated' | 'unknown';

export type Unit = 'kl' | 'kwh' | null;

export type BillStatus = 'needs_review' | 'extracting' | 'extraction_failed' | 'audited';

export type ExtractionSource = 'manual' | 'ai';

export type FindingRule =
  | 'estimated_reading'
  | 'consecutive_estimates'
  | 'tariff_mismatch'
  | 'outage_charge'
  | 'consumption_spike'
  | 'arithmetic_mismatch';

export type Severity = 'low' | 'medium' | 'high';

export type FindingStatus = 'open' | 'dismissed' | 'disputed';

export type DisputeStatus = 'draft' | 'submitted' | 'acknowledged' | 'escalated' | 'resolved' | 'rejected';

export type DisputeEventType =
  | 'created'
  | 'letter_edited'
  | 'submitted'
  | 'acknowledged'
  | 'response_received'
  | 'escalated'
  | 'resolved'
  | 'rejected'
  | 'note';

export type DeadlineType = 'lodge_dispute' | 'escalate';

/** `channel` accepted by POST /disputes/{id}/submit. */
export type DisputeChannel = 'email' | 'portal' | 'walk_in' | 'phone' | 'other';

/** `type` accepted by POST /disputes/{id}/events. */
export type LoggableEventType = 'acknowledged' | 'response_received' | 'note';

/** `outcome` accepted by POST /disputes/{id}/resolve. */
export type DisputeOutcome = 'resolved' | 'rejected';

/** `service` accepted for outages. */
export type OutageService = 'water' | 'electricity';

/** `status` accepted by PATCH /findings/{id}. */
export type SettableFindingStatus = 'open' | 'dismissed';

// ---------------------------------------------------------------------------
// Envelopes and errors
// ---------------------------------------------------------------------------

export interface DataEnvelope<T> {
  data: T;
}

/** Laravel's default error body. `errors` is present on 422 only. */
export interface ErrorBody {
  message: string;
  errors?: Record<string, string[]>;
}

// ---------------------------------------------------------------------------
// Resources
// ---------------------------------------------------------------------------

export interface User {
  id: number;
  name: string;
  email: string;
  popia_consent_version: string;
  popia_consented_at: string;
  ai_extraction_consent: boolean;
  created_at: string;
}

export interface MetroDisputeChannel {
  type: string;
  label: string;
  value: string;
}

export interface EscalationStep {
  level: number;
  name: string;
  description: string;
}

export interface Metro {
  code: MetroCode;
  name: string;
  dispute_window_days: number;
  response_wait_days: number;
  dispute_channels: MetroDisputeChannel[];
  escalation_steps: EscalationStep[];
  verified: boolean;
}

/** Property as returned in collections (GET /properties): account number is masked only. */
export interface PropertySummary {
  id: number;
  nickname: string;
  metro: MetroCode;
  account_number_masked: string;
  address: string;
  property_type: PropertyType;
  bills_count: number;
  open_findings_count: number;
  created_at: string;
}

/** Property as returned by GET/POST/PATCH /properties/{id}. */
export interface Property extends PropertySummary {
  account_number: string;
}

export interface LineItem {
  id: number;
  service: Service;
  description: string;
  tariff_category: string | null;
  reading_type: ReadingType;
  previous_reading: number | null;
  current_reading: number | null;
  consumption: number | null;
  unit: Unit;
  amount_cents: number;
}

export interface Finding {
  id: number;
  bill_id: number;
  rule: FindingRule;
  severity: Severity;
  /** Between 0 and 1. */
  confidence: number;
  title: string;
  explanation: string;
  estimated_overcharge_cents: number | null;
  /** Rule-specific; see docs/audit-rules.md. */
  evidence: Record<string, unknown>;
  status: FindingStatus;
  created_at: string;
}

export interface FindingsSummary {
  open_count: number;
  high_count: number;
  potential_overcharge_cents: number;
}

/** Bill as returned in collections (GET /properties/{id}/bills): no line items or findings. */
export interface BillSummary {
  id: number;
  property_id: number;
  bill_date: string | null;
  period_start: string | null;
  period_end: string | null;
  due_date: string | null;
  /** Current charges for the period, excluding arrears. */
  total_cents: number | null;
  status: BillStatus;
  extraction_source: ExtractionSource;
  has_file: boolean;
  /** Null when `has_file` is false. */
  file_mime: string | null;
  /** bill_date + metro.dispute_window_days; null when bill_date is null. */
  dispute_deadline: string | null;
  findings_summary: FindingsSummary;
  created_at: string;
}

export interface Bill extends BillSummary {
  line_items: LineItem[];
  /** Ordered by severity desc, then id. */
  findings: Finding[];
}

export interface Outage {
  id: number;
  property_id: number;
  service: OutageService;
  starts_at: string;
  ends_at: string;
  notes: string | null;
  created_at: string;
}

export interface DisputeNextStep {
  name: string;
  description: string;
  due_at: string;
}

export interface DisputeEvent {
  id: number;
  type: DisputeEventType;
  note: string | null;
  occurred_at: string;
}

/** Dispute as returned in collections (GET /disputes): no events or letter body. */
export interface DisputeSummary {
  id: number;
  bill_id: number;
  property: { id: number; nickname: string; metro: MetroCode };
  bill: { id: number; bill_date: string | null };
  status: DisputeStatus;
  finding_ids: number[];
  amount_disputed_cents: number;
  letter_subject: string;
  /** Null until the dispute is submitted. */
  channel: DisputeChannel | null;
  municipality_reference: string | null;
  /** The bill's dispute_deadline. */
  lodge_deadline: string | null;
  submitted_at: string | null;
  response_due_at: string | null;
  escalation_level: number;
  /** Null when resolved/rejected/draft or there is no further step. */
  next_step: DisputeNextStep | null;
  outcome_amount_cents: number | null;
  resolved_at: string | null;
  created_at: string;
}

export interface Dispute extends DisputeSummary {
  letter_body: string;
  /** Ordered by occurred_at ascending. */
  events: DisputeEvent[];
}

export interface UpcomingDeadline {
  type: DeadlineType;
  due_on: string;
  bill_id: number;
  dispute_id: number | null;
  property_nickname: string;
  label: string;
}

export interface Dashboard {
  properties_count: number;
  open_findings_count: number;
  potential_overcharge_cents: number;
  active_disputes_count: number;
  recovered_cents: number;
  upcoming_deadlines: UpcomingDeadline[];
}

export interface AuthResponse {
  token: string;
  user: User;
}

/** Body of GET /me/export. */
export interface MyDataExport {
  exported_at: string;
  user: User;
  properties: (Property & { bills: Bill[]; outages: Outage[] })[];
  disputes: Dispute[];
}

// ---------------------------------------------------------------------------
// Request bodies
// ---------------------------------------------------------------------------

export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  popia_consent: true;
  ai_extraction_consent?: boolean;
  device_name?: string;
}

export interface LoginInput {
  email: string;
  password: string;
  device_name?: string;
}

export interface UpdateMeInput {
  name?: string;
  ai_extraction_consent?: boolean;
}

export interface DeleteMeInput {
  password: string;
}

export interface PropertyInput {
  nickname: string;
  metro: MetroCode;
  account_number: string;
  address: string;
  property_type: PropertyType;
}

export type PropertyPatchInput = Partial<PropertyInput>;

export interface OutageInput {
  service: OutageService;
  starts_at: string;
  ends_at: string;
  notes?: string | null;
}

export interface LineItemInput {
  service: Service;
  description?: string | null;
  tariff_category?: string | null;
  reading_type?: ReadingType;
  previous_reading?: number | null;
  current_reading?: number | null;
  consumption?: number | null;
  unit?: Unit;
  /** Integer, >= 0. */
  amount_cents: number;
}

/** A local file to upload, in React Native's FormData shape. */
export interface UploadFile {
  uri: string;
  name: string;
  type: string;
}

export interface CreateBillInput {
  file?: UploadFile | null;
  bill_date?: string | null;
  period_start?: string | null;
  period_end?: string | null;
  due_date?: string | null;
  total_cents?: number | null;
  line_items?: LineItemInput[];
}

export interface UpdateBillInput {
  bill_date?: string | null;
  period_start?: string | null;
  period_end?: string | null;
  due_date?: string | null;
  total_cents?: number | null;
  line_items: LineItemInput[];
}

export interface UpdateFindingInput {
  status: SettableFindingStatus;
}

export interface CreateDisputeInput {
  finding_ids: number[];
}

export interface UpdateDisputeInput {
  letter_subject?: string;
  letter_body?: string;
}

export interface SubmitDisputeInput {
  channel: DisputeChannel;
  municipality_reference?: string | null;
  submitted_at?: string;
}

export interface AddDisputeEventInput {
  type: LoggableEventType;
  note?: string | null;
  occurred_at?: string;
}

export interface EscalateDisputeInput {
  note?: string | null;
}

export interface ResolveDisputeInput {
  outcome: DisputeOutcome;
  outcome_amount_cents?: number | null;
  note?: string | null;
}
