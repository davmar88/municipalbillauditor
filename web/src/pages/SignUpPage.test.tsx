import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { getToken } from '../api'
import { makeDashboard, makeUser } from '../test/fixtures'
import { mockApi, renderApp } from '../test/utils'

async function fillDetails(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText('Your name'), 'Thandi M')
  await user.type(screen.getByLabelText('Email address'), 'thandi@example.com')
  await user.type(screen.getByLabelText('Password', { exact: true }), 'correct horse')
  await user.type(screen.getByLabelText('Confirm password'), 'correct horse')
}

describe('Sign up', () => {
  it('requires POPIA consent before creating the account', async () => {
    const { fetchMock } = mockApi({})
    const user = userEvent.setup()
    renderApp('/sign-up', { signedIn: false })

    await fillDetails(user)
    await user.click(screen.getByRole('button', { name: 'Create account' }))

    const consent = screen.getByRole('checkbox', { name: /may use my personal information/i })
    expect(consent).toHaveAttribute('aria-invalid', 'true')
    expect(consent).toHaveAccessibleDescription(/Please agree to how we use your information/)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('explains what is collected, why, and that data can be exported or deleted', () => {
    mockApi({})
    renderApp('/sign-up', { signedIn: false })

    const notice = screen.getByRole('group', { name: 'Your personal information' })
    expect(within(notice).getByText(/account numbers and property addresses/i)).toBeInTheDocument()
    expect(within(notice).getByText(/check your bills for possible errors/i)).toBeInTheDocument()
    expect(within(notice).getByText(/download or delete everything at any time/i)).toBeInTheDocument()

    const ai = screen.getByRole('checkbox', { name: /Read my bills with AI/i })
    expect(ai).not.toBeChecked()
    expect(ai).toHaveAccessibleDescription(/outside South Africa/)
  })

  it('registers with consent and opens the dashboard', async () => {
    const { find } = mockApi({
      'POST /auth/register': { status: 201, body: { token: 'new-token', user: makeUser() } },
      'GET /dashboard': { body: { data: makeDashboard() } },
      'GET /properties': { body: { data: [] } },
      'GET /metros': { body: { data: [] } },
    })
    const user = userEvent.setup()
    renderApp('/sign-up', { signedIn: false })

    await fillDetails(user)
    await user.click(screen.getByRole('checkbox', { name: /may use my personal information/i }))
    await user.click(screen.getByRole('button', { name: 'Create account' }))

    expect(await screen.findByRole('heading', { name: 'Hello, Thandi' })).toBeInTheDocument()
    expect(await screen.findByText('Add your first property to get started')).toBeInTheDocument()

    const [register] = find('POST', '/auth/register')
    expect(register.json).toMatchObject({
      name: 'Thandi M',
      email: 'thandi@example.com',
      password: 'correct horse',
      password_confirmation: 'correct horse',
      popia_consent: true,
      ai_extraction_consent: false,
    })
    expect(getToken()).toBe('new-token')
  })

  it('shows 422 errors next to the relevant field', async () => {
    mockApi({
      'POST /auth/register': {
        status: 422,
        body: {
          message: 'The email has already been taken.',
          errors: { email: ['The email has already been taken.'] },
        },
      },
    })
    const user = userEvent.setup()
    renderApp('/sign-up', { signedIn: false })

    await fillDetails(user)
    await user.click(screen.getByRole('checkbox', { name: /may use my personal information/i }))
    await user.click(screen.getByRole('button', { name: 'Create account' }))

    const email = screen.getByLabelText('Email address')
    expect(await screen.findByText('The email has already been taken.')).toBeInTheDocument()
    expect(email).toHaveAttribute('aria-invalid', 'true')
    expect(email).toHaveAccessibleDescription('The email has already been taken.')
  })
})
