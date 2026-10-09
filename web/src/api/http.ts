/**
 * Low-level HTTP helpers shared by every endpoint function:
 * base URL, bearer-token storage, error types and a typed fetch wrapper.
 */

const DEFAULT_API_URL = 'http://localhost:8000/api/v1'

export const API_URL: string = (
  (import.meta.env.VITE_API_URL as string | undefined) || DEFAULT_API_URL
).replace(/\/+$/, '')

// ---------------------------------------------------------------------------
// Token storage
// ---------------------------------------------------------------------------

/**
 * The Sanctum token lives in localStorage so the user stays signed in across
 * reloads. Trade-off: any script running on this origin (for example through
 * an XSS bug) can read it. See README.md.
 */
const TOKEN_KEY = 'mba.token'

export function getToken(): string | null {
  try {
    return window.localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string): void {
  try {
    window.localStorage.setItem(TOKEN_KEY, token)
  } catch {
    // Storage can be unavailable (private mode, blocked site data).
  }
}

export function clearToken(): void {
  try {
    window.localStorage.removeItem(TOKEN_KEY)
  } catch {
    // ignore
  }
}

// ---------------------------------------------------------------------------
// Sign-out on 401
// ---------------------------------------------------------------------------

type UnauthorizedHandler = () => void
let unauthorizedHandler: UnauthorizedHandler | null = null

/** Registers what happens after any 401: the token is already cleared. */
export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  unauthorizedHandler = handler
}

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

/** A 422 response: `errors` maps field names (e.g. `line_items.0.amount_cents`) to messages. */
export class ValidationError extends ApiError {
  readonly errors: Record<string, string[]>

  constructor(message: string, errors: Record<string, string[]>) {
    super(422, message)
    this.name = 'ValidationError'
    this.errors = errors
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

export function isValidationError(error: unknown): error is ValidationError {
  return error instanceof ValidationError
}

const FRIENDLY_MESSAGES: Record<number, string> = {
  0: "We couldn't reach the server. Check your internet connection and try again.",
  401: 'Please sign in again.',
  403: "You don't have access to this.",
  404: "We couldn't find what you were looking for.",
  413: 'That file is too large. The limit is 10 MB.',
  429: "You've tried a few times in a row. Please wait a minute and try again.",
}

/** A friendly, plain-language sentence for any error thrown by the API layer. */
export function errorMessage(error: unknown): string {
  if (error instanceof ValidationError) {
    return error.message || 'Please check the highlighted fields.'
  }
  if (error instanceof ApiError) {
    if (FRIENDLY_MESSAGES[error.status]) return FRIENDLY_MESSAGES[error.status]
    if (error.status >= 500) {
      return 'Something went wrong on our side. Please try again in a moment.'
    }
    return error.message || 'Something went wrong. Please try again.'
  }
  return 'Something went wrong. Please try again.'
}

// ---------------------------------------------------------------------------
// Request
// ---------------------------------------------------------------------------

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export interface RequestOptions {
  /** JSON body. */
  json?: unknown
  /** multipart/form-data body. The browser sets the boundary header. */
  formData?: FormData
}

async function readJson(response: Response): Promise<unknown> {
  const text = await response.text()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}

function messageFrom(body: unknown): string {
  if (body && typeof body === 'object' && 'message' in body) {
    const message = (body as { message: unknown }).message
    if (typeof message === 'string') return message
  }
  return ''
}

export async function send(
  method: HttpMethod,
  path: string,
  options: RequestOptions = {},
): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`

  let body: BodyInit | undefined
  if (options.formData) {
    body = options.formData
  } else if (options.json !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.json)
  }

  let response: Response
  try {
    response = await fetch(`${API_URL}${path}`, { method, headers, body })
  } catch {
    throw new ApiError(0, FRIENDLY_MESSAGES[0])
  }

  if (response.ok) return response

  const errorBody = await readJson(response)

  if (response.status === 401) {
    clearToken()
    unauthorizedHandler?.()
    throw new ApiError(401, messageFrom(errorBody) || 'Unauthenticated.')
  }

  if (response.status === 422) {
    const errors =
      errorBody && typeof errorBody === 'object' && 'errors' in errorBody
        ? ((errorBody as { errors: Record<string, string[]> }).errors ?? {})
        : {}
    throw new ValidationError(
      messageFrom(errorBody) || 'Please check the highlighted fields.',
      errors,
    )
  }

  throw new ApiError(response.status, messageFrom(errorBody))
}

/** Sends a request and returns the parsed JSON body (or undefined for 204). */
export async function request<T>(
  method: HttpMethod,
  path: string,
  options: RequestOptions = {},
): Promise<T> {
  const response = await send(method, path, options)
  if (response.status === 204) return undefined as T
  return (await readJson(response)) as T
}
