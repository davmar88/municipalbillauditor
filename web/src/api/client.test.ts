import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeBill, makeUser } from '../test/fixtures'
import { mockApi } from '../test/utils'
import {
  api,
  API_URL,
  ApiError,
  getToken,
  setToken,
  setUnauthorizedHandler,
  ValidationError,
} from './index'

afterEach(() => {
  setUnauthorizedHandler(null)
})

describe('API client', () => {
  it('uses VITE_API_URL, defaulting to the local API, without a trailing slash', () => {
    const configured = import.meta.env.VITE_API_URL as string | undefined
    expect(API_URL).toBe((configured || 'http://localhost:8000/api/v1').replace(/\/+$/, ''))
    expect(API_URL.endsWith('/')).toBe(false)
  })

  it('sends the bearer token and unwraps { data }', async () => {
    setToken('secret-token')
    const user = makeUser()
    const { requests, fetchMock } = mockApi({ 'GET /me': { body: { data: user } } })

    await expect(api.getMe()).resolves.toEqual(user)

    expect(fetchMock).toHaveBeenCalledWith(`${API_URL}/me`, expect.objectContaining({ method: 'GET' }))
    expect(requests[0].headers.get('Authorization')).toBe('Bearer secret-token')
    expect(requests[0].headers.get('Accept')).toBe('application/json')
  })

  it('sends no Authorization header when signed out', async () => {
    const { requests } = mockApi({ 'GET /metros': { body: { data: [] } } })
    await api.listMetros()
    expect(requests[0].headers.has('Authorization')).toBe(false)
  })

  it('sends JSON bodies with a JSON content type', async () => {
    setToken('t')
    const { requests } = mockApi({
      'PATCH /findings/7': { body: { data: { id: 7, status: 'dismissed' } } },
    })
    await api.updateFinding(7, { status: 'dismissed' })
    expect(requests[0].headers.get('Content-Type')).toBe('application/json')
    expect(requests[0].json).toEqual({ status: 'dismissed' })
  })

  it('parses 422 validation errors', async () => {
    mockApi({
      'POST /auth/register': {
        status: 422,
        body: {
          message: 'The email has already been taken.',
          errors: { email: ['The email has already been taken.'], password: ['Too short.', 'Too simple.'] },
        },
      },
    })

    const error = await api
      .register({
        name: 'A',
        email: 'a@example.com',
        password: 'x',
        password_confirmation: 'x',
        popia_consent: true,
      })
      .catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ValidationError)
    const validation = error as ValidationError
    expect(validation.status).toBe(422)
    expect(validation.message).toBe('The email has already been taken.')
    expect(validation.errors).toEqual({
      email: ['The email has already been taken.'],
      password: ['Too short.', 'Too simple.'],
    })
  })

  it('clears the token and signs out on 401', async () => {
    setToken('expired-token')
    const onUnauthorized = vi.fn()
    setUnauthorizedHandler(onUnauthorized)
    mockApi({ 'GET /dashboard': { status: 401, body: { message: 'Unauthenticated.' } } })

    const error = await api.getDashboard().catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(401)
    expect(getToken()).toBeNull()
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })

  it('does not sign out on other errors', async () => {
    setToken('token')
    const onUnauthorized = vi.fn()
    setUnauthorizedHandler(onUnauthorized)
    mockApi({ 'GET /bills/99': { status: 404, body: { message: 'Not found.' } } })

    const error = await api.getBill(99).catch((e: unknown) => e)

    expect((error as ApiError).status).toBe(404)
    expect(getToken()).toBe('token')
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('turns network failures into a friendly ApiError', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    const error = await api.getDashboard().catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(0)
  })

  it('resolves 204 responses to undefined', async () => {
    setToken('t')
    mockApi({ 'POST /auth/logout': { status: 204 } })
    await expect(api.logout()).resolves.toBeUndefined()
  })

  it('uploads bills as multipart with line_items JSON-encoded', async () => {
    setToken('t')
    const bill = makeBill()
    const { requests } = mockApi({ 'POST /properties/3/bills': { status: 201, body: { data: bill } } })
    const file = new File(['%PDF-1.4'], 'bill.pdf', { type: 'application/pdf' })
    const lineItems = [
      {
        service: 'water' as const,
        description: 'Water consumption',
        tariff_category: null,
        reading_type: 'estimated' as const,
        previous_reading: 1203,
        current_reading: 1241,
        consumption: null,
        unit: 'kl' as const,
        amount_cents: 152340,
      },
    ]

    await expect(
      api.createBill(3, {
        file,
        bill_date: '2026-09-25',
        period_start: null,
        total_cents: 412350,
        line_items: lineItems,
      }),
    ).resolves.toEqual(bill)

    const request = requests[0]
    expect(request.method).toBe('POST')
    expect(request.body).toBeInstanceOf(FormData)
    // The browser must set the multipart boundary itself.
    expect(request.headers.has('Content-Type')).toBe(false)
    expect(request.headers.get('Authorization')).toBe('Bearer t')

    const form = request.body as FormData
    const sentFile = form.get('file')
    expect(sentFile).toBeInstanceOf(File)
    expect((sentFile as File).name).toBe('bill.pdf')
    expect(form.get('bill_date')).toBe('2026-09-25')
    expect(form.has('period_start')).toBe(false)
    expect(form.get('total_cents')).toBe('412350')
    expect(typeof form.get('line_items')).toBe('string')
    expect(JSON.parse(form.get('line_items') as string)).toEqual(lineItems)
  })

  it('uploads a file on its own without line_items', async () => {
    setToken('t')
    const { requests } = mockApi({ 'POST /properties/3/bills': { status: 201, body: { data: makeBill() } } })
    await api.createBill(3, { file: new File(['x'], 'bill.jpg', { type: 'image/jpeg' }) })
    const form = requests[0].body as FormData
    expect(form.has('file')).toBe(true)
    expect(form.has('line_items')).toBe(false)
  })

  it('downloads the data export as a file', async () => {
    setToken('t')
    mockApi({
      'GET /me/export': {
        body: { exported_at: '2026-10-09T05:17:14.000000Z', user: makeUser(), properties: [], disputes: [] },
        headers: { 'Content-Disposition': 'attachment; filename="my-data.json"' },
      },
    })
    const { blob, filename } = await api.exportMyData()
    expect(filename).toBe('my-data.json')
    expect(JSON.parse(await blob.text()).user.email).toBe('thandi@example.com')
  })

  it('sends the password when deleting the account', async () => {
    setToken('t')
    const { requests } = mockApi({ 'DELETE /me': { status: 204 } })
    await api.deleteAccount('my password')
    expect(requests[0].json).toEqual({ password: 'my password' })
  })
})
