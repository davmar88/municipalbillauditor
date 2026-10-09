import { downloadToCache, request } from './client';
import type {
  AddDisputeEventInput,
  AuthResponse,
  Bill,
  BillSummary,
  CreateBillInput,
  CreateDisputeInput,
  Dashboard,
  DataEnvelope,
  DeleteMeInput,
  Dispute,
  DisputeSummary,
  EscalateDisputeInput,
  Finding,
  LoginInput,
  Metro,
  Outage,
  OutageInput,
  Property,
  PropertyInput,
  PropertyPatchInput,
  PropertySummary,
  RegisterInput,
  ResolveDisputeInput,
  SubmitDisputeInput,
  UpdateBillInput,
  UpdateDisputeInput,
  UpdateFindingInput,
  UpdateMeInput,
  User,
} from './types';

/** One function per endpoint in docs/api.md. Single resources are unwrapped from `{ data }`. */

async function data<T>(promise: Promise<DataEnvelope<T>>): Promise<T> {
  return (await promise).data;
}

// --- Auth and account -------------------------------------------------------

export function register(input: RegisterInput): Promise<AuthResponse> {
  return request<AuthResponse>('POST', '/auth/register', { json: input });
}

export function login(input: LoginInput): Promise<AuthResponse> {
  return request<AuthResponse>('POST', '/auth/login', { json: input });
}

export function logout(): Promise<void> {
  return request<void>('POST', '/auth/logout');
}

export function getMe(): Promise<User> {
  return data(request<DataEnvelope<User>>('GET', '/me'));
}

export function updateMe(input: UpdateMeInput): Promise<User> {
  return data(request<DataEnvelope<User>>('PATCH', '/me', { json: input }));
}

/** GET /me/export, saved as my-data.json in the cache directory. Resolves to the local file URI. */
export function exportMyData(): Promise<string> {
  return downloadToCache('/me/export', 'my-data.json');
}

export function deleteMe(input: DeleteMeInput): Promise<void> {
  return request<void>('DELETE', '/me', { json: input });
}

// --- Reference data ---------------------------------------------------------

export function listMetros(): Promise<Metro[]> {
  return data(request<DataEnvelope<Metro[]>>('GET', '/metros'));
}

// --- Dashboard --------------------------------------------------------------

export function getDashboard(): Promise<Dashboard> {
  return data(request<DataEnvelope<Dashboard>>('GET', '/dashboard'));
}

// --- Properties -------------------------------------------------------------

export function listProperties(): Promise<PropertySummary[]> {
  return data(request<DataEnvelope<PropertySummary[]>>('GET', '/properties'));
}

export function createProperty(input: PropertyInput): Promise<Property> {
  return data(request<DataEnvelope<Property>>('POST', '/properties', { json: input }));
}

export function getProperty(id: number): Promise<Property> {
  return data(request<DataEnvelope<Property>>('GET', `/properties/${id}`));
}

export function updateProperty(id: number, input: PropertyPatchInput): Promise<Property> {
  return data(request<DataEnvelope<Property>>('PATCH', `/properties/${id}`, { json: input }));
}

export function deleteProperty(id: number): Promise<void> {
  return request<void>('DELETE', `/properties/${id}`);
}

// --- Outages ----------------------------------------------------------------

export function listOutages(propertyId: number): Promise<Outage[]> {
  return data(request<DataEnvelope<Outage[]>>('GET', `/properties/${propertyId}/outages`));
}

export function createOutage(propertyId: number, input: OutageInput): Promise<Outage> {
  return data(request<DataEnvelope<Outage>>('POST', `/properties/${propertyId}/outages`, { json: input }));
}

export function deleteOutage(id: number): Promise<void> {
  return request<void>('DELETE', `/outages/${id}`);
}

// --- Bills ------------------------------------------------------------------

export function listBills(propertyId: number): Promise<BillSummary[]> {
  return data(request<DataEnvelope<BillSummary[]>>('GET', `/properties/${propertyId}/bills`));
}

/**
 * Builds the multipart body for POST /properties/{id}/bills.
 * The file uses React Native's { uri, name, type } shape and line_items is JSON-encoded.
 */
export function buildBillFormData(input: CreateBillInput): FormData {
  const form = new FormData();
  if (input.file) {
    // React Native's FormData accepts this object for files; the DOM typings don't know it.
    form.append('file', { uri: input.file.uri, name: input.file.name, type: input.file.type } as unknown as Blob);
  }
  const scalars = ['bill_date', 'period_start', 'period_end', 'due_date'] as const;
  for (const key of scalars) {
    const value = input[key];
    if (value) form.append(key, value);
  }
  if (input.total_cents !== null && input.total_cents !== undefined) {
    form.append('total_cents', String(input.total_cents));
  }
  if (input.line_items && input.line_items.length > 0) {
    form.append('line_items', JSON.stringify(input.line_items));
  }
  return form;
}

export function createBill(propertyId: number, input: CreateBillInput): Promise<Bill> {
  return data(
    request<DataEnvelope<Bill>>('POST', `/properties/${propertyId}/bills`, { formData: buildBillFormData(input) }),
  );
}

export function getBill(id: number): Promise<Bill> {
  return data(request<DataEnvelope<Bill>>('GET', `/bills/${id}`));
}

export function updateBill(id: number, input: UpdateBillInput): Promise<Bill> {
  return data(request<DataEnvelope<Bill>>('PUT', `/bills/${id}`, { json: input }));
}

export function auditBill(id: number): Promise<Bill> {
  return data(request<DataEnvelope<Bill>>('POST', `/bills/${id}/audit`));
}

const EXTENSIONS: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/jpg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/heic': 'heic',
  'application/pdf': 'pdf',
};

/** GET /bills/{id}/file, saved in the cache directory. Resolves to the local file URI. */
export function downloadBillFile(id: number, mime: string | null): Promise<string> {
  const extension = (mime && EXTENSIONS[mime.toLowerCase()]) || 'bin';
  return downloadToCache(`/bills/${id}/file`, `bill-${id}.${extension}`);
}

export function deleteBill(id: number): Promise<void> {
  return request<void>('DELETE', `/bills/${id}`);
}

// --- Findings ---------------------------------------------------------------

export function updateFinding(id: number, input: UpdateFindingInput): Promise<Finding> {
  return data(request<DataEnvelope<Finding>>('PATCH', `/findings/${id}`, { json: input }));
}

// --- Disputes ---------------------------------------------------------------

export function listDisputes(): Promise<DisputeSummary[]> {
  return data(request<DataEnvelope<DisputeSummary[]>>('GET', '/disputes'));
}

export function createDispute(billId: number, input: CreateDisputeInput): Promise<Dispute> {
  return data(request<DataEnvelope<Dispute>>('POST', `/bills/${billId}/disputes`, { json: input }));
}

export function getDispute(id: number): Promise<Dispute> {
  return data(request<DataEnvelope<Dispute>>('GET', `/disputes/${id}`));
}

export function updateDispute(id: number, input: UpdateDisputeInput): Promise<Dispute> {
  return data(request<DataEnvelope<Dispute>>('PATCH', `/disputes/${id}`, { json: input }));
}

export function deleteDispute(id: number): Promise<void> {
  return request<void>('DELETE', `/disputes/${id}`);
}

export function submitDispute(id: number, input: SubmitDisputeInput): Promise<Dispute> {
  return data(request<DataEnvelope<Dispute>>('POST', `/disputes/${id}/submit`, { json: input }));
}

export function addDisputeEvent(id: number, input: AddDisputeEventInput): Promise<Dispute> {
  return data(request<DataEnvelope<Dispute>>('POST', `/disputes/${id}/events`, { json: input }));
}

export function escalateDispute(id: number, input: EscalateDisputeInput = {}): Promise<Dispute> {
  return data(request<DataEnvelope<Dispute>>('POST', `/disputes/${id}/escalate`, { json: input }));
}

export function resolveDispute(id: number, input: ResolveDisputeInput): Promise<Dispute> {
  return data(request<DataEnvelope<Dispute>>('POST', `/disputes/${id}/resolve`, { json: input }));
}
