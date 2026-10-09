import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { getToken } from './api'
import { makeDashboard, makeUser } from './test/fixtures'
import { mockApi, renderApp, type MockResponse } from './test/utils'

type Route = MockResponse | (() => MockResponse)

/** Routes for a signed-in person on the account page who can sign in again afterwards. */
function accountRoutes(extra: Record<string, Route> = {}): Record<string, Route> {
  return {
    'GET /me': { body: { data: makeUser() } },
    'GET /dashboard': { body: { data: makeDashboard() } },
    'GET /properties': { body: { data: [] } },
    'GET /metros': { body: { data: [] } },
    'POST /auth/login': { body: { token: 'new-token', user: makeUser({ name: 'Sipho K' }) } },
    ...extra,
  }
}

async function signInAgain(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText('Email address'), 'sipho@example.com')
  await user.type(screen.getByLabelText('Password'), 'another password')
  await user.click(screen.getByRole('button', { name: 'Sign in' }))
}

describe('App', () => {
  it('sends signed-out visitors to sign in', async () => {
    mockApi({})
    renderApp('/disputes', { signedIn: false })
    expect(await screen.findByRole('heading', { name: 'Sign in', level: 1 })).toBeInTheDocument()
  })

  it('signs out and goes to sign in when the API answers 401', async () => {
    mockApi({
      'GET /me': { status: 401, body: { message: 'Unauthenticated.' } },
      'GET /dashboard': { status: 401, body: { message: 'Unauthenticated.' } },
      'GET /properties': { status: 401, body: { message: 'Unauthenticated.' } },
      'GET /metros': { body: { data: [] } },
    })
    renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Sign in', level: 1 })).toBeInTheDocument()
    expect(screen.getByText(/You were signed out/)).toBeInTheDocument()
    expect(getToken()).toBeNull()
  })

  it('signs out to the sign-in page, and the next sign-in starts at the overview', async () => {
    const { find } = mockApi(accountRoutes({ 'POST /auth/logout': { status: 204 } }))
    const user = userEvent.setup()
    renderApp('/account')

    await screen.findByRole('heading', { name: 'Account', level: 1 })
    await user.click(screen.getByRole('button', { name: 'Sign out' }))

    expect(await screen.findByRole('heading', { name: 'Sign in', level: 1 })).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent("You've signed out.")
    expect(find('POST', '/auth/logout')).toHaveLength(1)
    expect(getToken()).toBeNull()

    // Not sent back to /account, which belonged to the person who signed out.
    await signInAgain(user)
    expect(await screen.findByRole('heading', { name: 'Hello, Sipho', level: 1 })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Account', level: 1 })).not.toBeInTheDocument()
  })

  it('confirms an account deletion on the sign-in page', async () => {
    const { find } = mockApi(accountRoutes({ 'DELETE /me': { status: 204 } }))
    const user = userEvent.setup()
    renderApp('/account')

    await user.click(await screen.findByRole('button', { name: 'Delete my account' }))
    await user.type(screen.getByLabelText('Password'), 'correct horse')
    await user.click(screen.getByRole('button', { name: 'Permanently delete my account' }))

    expect(await screen.findByRole('heading', { name: 'Sign in', level: 1 })).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Your account and all your data have been deleted.')
    expect(screen.queryByText(/You were signed out/)).not.toBeInTheDocument()
    expect(find('DELETE', '/me')[0].json).toEqual({ password: 'correct horse' })
    expect(getToken()).toBeNull()

    await signInAgain(user)
    expect(await screen.findByRole('heading', { name: 'Hello, Sipho', level: 1 })).toBeInTheDocument()
  })

  it('brings you back to the page you were on after your session expires', async () => {
    let expired = false
    mockApi(
      accountRoutes({
        'GET /me': () => (expired ? { status: 401, body: { message: 'Unauthenticated.' } } : { body: { data: makeUser() } }),
        'PATCH /me': () => {
          expired = true
          return { status: 401, body: { message: 'Unauthenticated.' } }
        },
      }),
    )
    const user = userEvent.setup()
    renderApp('/account')

    const name = await screen.findByLabelText('Name')
    await user.clear(name)
    await user.type(name, 'Thandi Mokoena')
    await user.click(screen.getByRole('button', { name: 'Save name' }))

    expect(await screen.findByText(/You were signed out/)).toBeInTheDocument()
    expired = false
    await signInAgain(user)
    expect(await screen.findByRole('heading', { name: 'Account', level: 1 })).toBeInTheDocument()
  })

  it('shows a guiding empty state when there is no data yet', async () => {
    mockApi({
      'GET /me': { body: { data: makeUser() } },
      'GET /dashboard': { body: { data: makeDashboard() } },
      'GET /properties': { body: { data: [] } },
      'GET /metros': { body: { data: [] } },
    })
    renderApp('/')

    expect(await screen.findByText('Add your first property to get started')).toBeInTheDocument()
    expect(screen.getAllByText('R0.00', { selector: 'dd' })).toHaveLength(2)
    expect(screen.getByText(/Nothing due right now/)).toBeInTheDocument()
  })
})
