import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { vi } from 'vitest'
import { AppRoutes } from '../App'
import { API_URL, setToken } from '../api'
import { AuthProvider } from '../auth/AuthProvider'
import { createQueryClient } from '../lib/queryClient'

export interface RecordedRequest {
  method: string
  /** Path relative to the API base URL, e.g. `/bills/5`. */
  path: string
  headers: Headers
  body: BodyInit | null | undefined
  /** Parsed JSON body when the request sent JSON. */
  json: unknown
}

export interface MockResponse {
  status?: number
  body?: unknown
  headers?: Record<string, string>
}

type Handler = (request: RecordedRequest) => MockResponse

/**
 * A fake `fetch` that answers by "METHOD /path" (relative to the API base URL)
 * and records every request. Unknown routes answer 404 so tests fail loudly.
 */
export function mockApi(routes: Record<string, MockResponse | Handler>) {
  const requests: RecordedRequest[] = []
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
    const path = url.startsWith(API_URL) ? url.slice(API_URL.length) : url
    const method = (init.method ?? 'GET').toUpperCase()
    const headers = new Headers(init.headers)
    let json: unknown = undefined
    if (typeof init.body === 'string') {
      try {
        json = JSON.parse(init.body)
      } catch {
        json = undefined
      }
    }
    const request: RecordedRequest = { method, path, headers, body: init.body, json }
    requests.push(request)

    const route = routes[`${method} ${path}`]
    const result: MockResponse =
      route === undefined
        ? { status: 404, body: { message: `No mock for ${method} ${path}` } }
        : typeof route === 'function'
          ? route(request)
          : route
    const status = result.status ?? 200
    const body = status === 204 || result.body === undefined ? null : JSON.stringify(result.body)
    return new Response(body, {
      status,
      headers: { 'Content-Type': 'application/json', ...result.headers },
    })
  })
  vi.stubGlobal('fetch', fetchMock)
  return {
    fetchMock,
    requests,
    /** Requests matching a method and path. */
    find: (method: string, path: string) => requests.filter((r) => r.method === method && r.path === path),
  }
}

/** Renders the whole app at a URL, optionally signed in. */
export function renderApp(url: string, { signedIn = true }: { signedIn?: boolean } = {}) {
  if (signedIn) setToken('test-token')
  const queryClient = createQueryClient()
  queryClient.setDefaultOptions({
    queries: { retry: false, staleTime: Infinity, refetchOnWindowFocus: false },
    mutations: { retry: false },
  })
  const utils = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[url]}>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { ...utils, queryClient }
}
