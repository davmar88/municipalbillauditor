/**
 * One function per endpoint in docs/api.md. Each unwraps the `{ data }`
 * envelope so callers get the resource itself.
 */
import { request, send } from './http'
import type {
  AuthResponse,
  Bill,
  BillSummary,
  CreateBillInput,
  CreateDisputeInput,
  Dashboard,
  DataEnvelope,
  Dispute,
  DisputeEventInput,
  DisputeSummary,
  EscalateDisputeInput,
  Finding,
  LoginInput,
  Metro,
  Outage,
  OutageInput,
  Property,
  PropertyInput,
  PropertySummary,
  RegisterInput,
  ResolveDisputeInput,
  SubmitDisputeInput,
  UpdateBillInput,
  UpdateDisputeInput,
  UpdateFindingInput,
  UpdateMeInput,
  User,
} from './types'

async function data<T>(promise: Promise<DataEnvelope<T>>): Promise<T> {
  return (await promise).data
}

/** Sent as `device_name` so tokens are recognisable in the account's token list. */
export const DEVICE_NAME = 'web'

// ---------------------------------------------------------------------------
// Auth and account
// ---------------------------------------------------------------------------

/** POST /auth/register */
export function register(input: RegisterInput): Promise<AuthResponse> {
  return request<AuthResponse>('POST', '/auth/register', {
    json: { device_name: DEVICE_NAME, ...input },
  })
}

/** POST /auth/login */
export function login(input: LoginInput): Promise<AuthResponse> {
  return request<AuthResponse>('POST', '/auth/login', {
    json: { device_name: DEVICE_NAME, ...input },
  })
}

/** POST /auth/logout */
export function logout(): Promise<void> {
  return request<void>('POST', '/auth/logout')
}

/** GET /me */
export function getMe(): Promise<User> {
  return data(request<DataEnvelope<User>>('GET', '/me'))
}

/** PATCH /me */
export function updateMe(input: UpdateMeInput): Promise<User> {
  return data(request<DataEnvelope<User>>('PATCH', '/me', { json: input }))
}

export interface ExportedFile {
  blob: Blob
  filename: string
}

/** GET /me/export — returned as a file so it can be saved exactly as sent. */
export async function exportMyData(): Promise<ExportedFile> {
  const response = await send('GET', '/me/export')
  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = /filename="?([^";]+)"?/i.exec(disposition)
  const blob = await response.blob()
  return {
    blob: blob.type ? blob : new Blob([blob], { type: 'application/json' }),
    filename: match?.[1] ?? 'my-data.json',
  }
}

/** DELETE /me */
export function deleteAccount(password: string): Promise<void> {
  return request<void>('DELETE', '/me', { json: { password } })
}

// ---------------------------------------------------------------------------
// Reference data and dashboard
// ---------------------------------------------------------------------------

/** GET /metros (public) */
export function listMetros(): Promise<Metro[]> {
  return data(request<DataEnvelope<Metro[]>>('GET', '/metros'))
}

/** GET /dashboard */
export function getDashboard(): Promise<Dashboard> {
  return data(request<DataEnvelope<Dashboard>>('GET', '/dashboard'))
}

// ---------------------------------------------------------------------------
// Properties
// ---------------------------------------------------------------------------

/** GET /properties */
export function listProperties(): Promise<PropertySummary[]> {
  return data(request<DataEnvelope<PropertySummary[]>>('GET', '/properties'))
}

/** POST /properties */
export function createProperty(input: PropertyInput): Promise<Property> {
  return data(
    request<DataEnvelope<Property>>('POST', '/properties', { json: input }),
  )
}

/** GET /properties/{id} */
export function getProperty(id: number): Promise<Property> {
  return data(request<DataEnvelope<Property>>('GET', `/properties/${id}`))
}

/** PATCH /properties/{id} */
export function updateProperty(
  id: number,
  input: Partial<PropertyInput>,
): Promise<Property> {
  return data(
    request<DataEnvelope<Property>>('PATCH', `/properties/${id}`, {
      json: input,
    }),
  )
}

/** DELETE /properties/{id} */
export function deleteProperty(id: number): Promise<void> {
  return request<void>('DELETE', `/properties/${id}`)
}

// ---------------------------------------------------------------------------
// Outages
// ---------------------------------------------------------------------------

/** GET /properties/{id}/outages */
export function listOutages(propertyId: number): Promise<Outage[]> {
  return data(
    request<DataEnvelope<Outage[]>>('GET', `/properties/${propertyId}/outages`),
  )
}

/** POST /properties/{id}/outages */
export function createOutage(
  propertyId: number,
  input: OutageInput,
): Promise<Outage> {
  return data(
    request<DataEnvelope<Outage>>('POST', `/properties/${propertyId}/outages`, {
      json: input,
    }),
  )
}

/** DELETE /outages/{id} */
export function deleteOutage(id: number): Promise<void> {
  return request<void>('DELETE', `/outages/${id}`)
}

// ---------------------------------------------------------------------------
// Bills
// ---------------------------------------------------------------------------

/** GET /properties/{id}/bills */
export function listBills(propertyId: number): Promise<BillSummary[]> {
  return data(
    request<DataEnvelope<BillSummary[]>>('GET', `/properties/${propertyId}/bills`),
  )
}

/**
 * Builds the multipart body for POST /properties/{id}/bills.
 * Empty fields are left out; `line_items` is sent as a JSON-encoded string.
 */
export function billFormData(input: CreateBillInput): FormData {
  const form = new FormData()
  if (input.file) form.append('file', input.file)
  const dates = ['bill_date', 'period_start', 'period_end', 'due_date'] as const
  for (const key of dates) {
    const value = input[key]
    if (value) form.append(key, value)
  }
  if (input.total_cents !== null && input.total_cents !== undefined) {
    form.append('total_cents', String(input.total_cents))
  }
  if (input.line_items && input.line_items.length > 0) {
    form.append('line_items', JSON.stringify(input.line_items))
  }
  return form
}

/** POST /properties/{id}/bills (multipart) */
export function createBill(
  propertyId: number,
  input: CreateBillInput,
): Promise<Bill> {
  return data(
    request<DataEnvelope<Bill>>('POST', `/properties/${propertyId}/bills`, {
      formData: billFormData(input),
    }),
  )
}

/** GET /bills/{id} */
export function getBill(id: number): Promise<Bill> {
  return data(request<DataEnvelope<Bill>>('GET', `/bills/${id}`))
}

/** PUT /bills/{id} — replaces all line items and re-audits. */
export function updateBill(id: number, input: UpdateBillInput): Promise<Bill> {
  return data(
    request<DataEnvelope<Bill>>('PUT', `/bills/${id}`, { json: input }),
  )
}

/** POST /bills/{id}/audit */
export function auditBill(id: number): Promise<Bill> {
  return data(request<DataEnvelope<Bill>>('POST', `/bills/${id}/audit`))
}

/** GET /bills/{id}/file — the original upload, fetched with the auth header. */
export async function getBillFile(id: number): Promise<Blob> {
  const response = await send('GET', `/bills/${id}/file`)
  return response.blob()
}

/** DELETE /bills/{id} */
export function deleteBill(id: number): Promise<void> {
  return request<void>('DELETE', `/bills/${id}`)
}

// ---------------------------------------------------------------------------
// Findings
// ---------------------------------------------------------------------------

/** PATCH /findings/{id} */
export function updateFinding(
  id: number,
  input: UpdateFindingInput,
): Promise<Finding> {
  return data(
    request<DataEnvelope<Finding>>('PATCH', `/findings/${id}`, { json: input }),
  )
}

// ---------------------------------------------------------------------------
// Disputes
// ---------------------------------------------------------------------------

/** GET /disputes */
export function listDisputes(): Promise<DisputeSummary[]> {
  return data(request<DataEnvelope<DisputeSummary[]>>('GET', '/disputes'))
}

/** POST /bills/{id}/disputes */
export function createDispute(
  billId: number,
  input: CreateDisputeInput,
): Promise<Dispute> {
  return data(
    request<DataEnvelope<Dispute>>('POST', `/bills/${billId}/disputes`, {
      json: input,
    }),
  )
}

/** GET /disputes/{id} */
export function getDispute(id: number): Promise<Dispute> {
  return data(request<DataEnvelope<Dispute>>('GET', `/disputes/${id}`))
}

/** PATCH /disputes/{id} (draft only) */
export function updateDispute(
  id: number,
  input: UpdateDisputeInput,
): Promise<Dispute> {
  return data(
    request<DataEnvelope<Dispute>>('PATCH', `/disputes/${id}`, { json: input }),
  )
}

/** DELETE /disputes/{id} (draft only) */
export function deleteDispute(id: number): Promise<void> {
  return request<void>('DELETE', `/disputes/${id}`)
}

/** POST /disputes/{id}/submit */
export function submitDispute(
  id: number,
  input: SubmitDisputeInput,
): Promise<Dispute> {
  return data(
    request<DataEnvelope<Dispute>>('POST', `/disputes/${id}/submit`, {
      json: input,
    }),
  )
}

/** POST /disputes/{id}/events */
export function addDisputeEvent(
  id: number,
  input: DisputeEventInput,
): Promise<Dispute> {
  return data(
    request<DataEnvelope<Dispute>>('POST', `/disputes/${id}/events`, {
      json: input,
    }),
  )
}

/** POST /disputes/{id}/escalate */
export function escalateDispute(
  id: number,
  input: EscalateDisputeInput = {},
): Promise<Dispute> {
  return data(
    request<DataEnvelope<Dispute>>('POST', `/disputes/${id}/escalate`, {
      json: input,
    }),
  )
}

/** POST /disputes/{id}/resolve */
export function resolveDispute(
  id: number,
  input: ResolveDisputeInput,
): Promise<Dispute> {
  return data(
    request<DataEnvelope<Dispute>>('POST', `/disputes/${id}/resolve`, {
      json: input,
    }),
  )
}
