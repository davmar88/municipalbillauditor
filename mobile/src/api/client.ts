import { Directory, File, Paths } from 'expo-file-system';

import { API_URL } from './config';
import type { ErrorBody } from './types';

/**
 * Low-level HTTP client. Holds the bearer token in memory (the auth provider
 * persists it in the secure store) and turns every non-2xx response into an ApiError.
 */

export class ApiError extends Error {
  readonly status: number;
  /** Field errors from a 422 response, keyed by field name (e.g. "email", "line_items.0.amount_cents"). */
  readonly errors: Record<string, string[]>;

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }
}

let authToken: string | null = null;
let unauthorizedHandler: (() => void) | null = null;
let baseUrl = API_URL;

export function setAuthToken(token: string | null): void {
  authToken = token;
}

export function getAuthToken(): string | null {
  return authToken;
}

/** Called on any 401: the session is over, the app must forget the token and show sign in. */
export function setUnauthorizedHandler(handler: (() => void) | null): void {
  unauthorizedHandler = handler;
}

/** For tests and diagnostics. */
export function setBaseUrl(url: string): void {
  baseUrl = url.replace(/\/+$/, '');
}

export function getBaseUrl(): string {
  return baseUrl;
}

function handleUnauthorized(): void {
  authToken = null;
  unauthorizedHandler?.();
}

const FRIENDLY_MESSAGES: Record<number, string> = {
  0: "We couldn't reach the server. Check your internet connection and try again.",
  401: 'Your session has ended. Please sign in again.',
  403: "You don't have access to that.",
  404: "We couldn't find that. It may have been deleted.",
  413: 'That file is too large. Please use a file under 10 MB.',
  429: 'Too many attempts. Please wait a minute and try again.',
};

function friendlyMessage(status: number, serverMessage: string | undefined): string {
  if (status === 422) return serverMessage || 'Please check the highlighted fields.';
  if (FRIENDLY_MESSAGES[status]) return FRIENDLY_MESSAGES[status];
  if (status >= 500) return 'Something went wrong on our side. Please try again in a moment.';
  return serverMessage || 'Something went wrong. Please try again.';
}

type RequestOptions = {
  /** JSON body. */
  json?: unknown;
  /** Multipart body. The browser/native layer sets the boundary header. */
  formData?: FormData;
};

export async function request<T>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const sentToken = authToken;
  if (sentToken) headers.Authorization = `Bearer ${sentToken}`;

  let body: string | FormData | undefined;
  if (options.formData) {
    body = options.formData;
  } else if (options.json !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(options.json);
  }

  let response: Response;
  try {
    response = await fetch(`${baseUrl}${path}`, { method, headers, body });
  } catch {
    throw new ApiError(0, FRIENDLY_MESSAGES[0]);
  }

  if (response.status === 204) return undefined as T;

  const text = await response.text();
  let parsed: unknown = undefined;
  if (text) {
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = undefined;
    }
  }

  if (!response.ok) {
    const errorBody = (parsed ?? {}) as Partial<ErrorBody>;
    // Only a rejected token ends the session; a request sent without one can't have expired.
    if (response.status === 401 && sentToken) handleUnauthorized();
    throw new ApiError(
      response.status,
      friendlyMessage(response.status, typeof errorBody.message === 'string' ? errorBody.message : undefined),
      errorBody.errors && typeof errorBody.errors === 'object' ? errorBody.errors : {},
    );
  }

  return parsed as T;
}

/** Downloaded bills and data exports live together so they can all be removed at once. */
function downloadsDirectory(): Directory {
  return new Directory(Paths.cache, 'downloads');
}

/**
 * Downloads an authenticated file (bill original, data export) into the app's cache
 * and returns its local URI. The token goes in the Authorization header.
 */
export async function downloadToCache(path: string, fileName: string): Promise<string> {
  const headers: Record<string, string> = { Accept: '*/*' };
  const sentToken = authToken;
  if (sentToken) headers.Authorization = `Bearer ${sentToken}`;
  let destination: File;
  try {
    const directory = downloadsDirectory();
    directory.create({ intermediates: true, idempotent: true });
    destination = new File(directory, fileName);
  } catch {
    throw new ApiError(0, "We couldn't save the file on this phone. Please check you have space and try again.");
  }
  try {
    const file = await File.downloadFileAsync(`${baseUrl}${path}`, destination, { headers, idempotent: true });
    return file.uri;
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    const status = Number(/(?:status:?|HTTP)\s*(\d{3})/i.exec(message)?.[1] ?? 0);
    if (status === 401 && sentToken) handleUnauthorized();
    throw new ApiError(status, friendlyMessage(status, undefined));
  }
}

/**
 * Deletes every downloaded bill and data export from this phone. Called when the session
 * ends (sign-out, expired session, deleted account) so personal files don't outlive it.
 */
export function clearDownloads(): void {
  try {
    const directory = downloadsDirectory();
    if (directory.exists) directory.delete();
  } catch {
    // Best effort: the cache folder is also cleared by the system over time.
  }
}
