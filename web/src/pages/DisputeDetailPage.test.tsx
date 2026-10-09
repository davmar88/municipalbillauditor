import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Dispute } from '../api'
import { makeDispute, makeMetro } from '../test/fixtures'
import { mockApi, renderApp, type MockResponse, type RecordedRequest } from '../test/utils'

/** A stateful fake: every POST/PATCH returns the dispute changed by `apply`. */
function disputeServer(initial: Dispute, apply: Record<string, (d: Dispute, r: RecordedRequest) => Dispute>) {
  let dispute = initial
  const routes: Record<string, MockResponse | ((r: RecordedRequest) => MockResponse)> = {
    'GET /disputes/4': () => ({ body: { data: dispute } }),
    'GET /metros': { body: { data: [makeMetro()] } },
    'GET /disputes': () => ({ body: { data: [dispute] } }),
  }
  for (const [key, fn] of Object.entries(apply)) {
    routes[key] = (request) => {
      dispute = fn(dispute, request)
      return { body: { data: dispute } }
    }
  }
  return mockApi(routes)
}

describe('Dispute detail', () => {
  it('saves letter edits and marks the dispute as submitted', async () => {
    const { find } = disputeServer(makeDispute(), {
      'PATCH /disputes/4': (d, r) => ({ ...d, ...(r.json as Partial<Dispute>) }),
      'POST /disputes/4/submit': (d, r) => ({
        ...d,
        status: 'submitted',
        channel: (r.json as { channel: Dispute['channel'] }).channel,
        submitted_at: '2026-10-09T08:00:00.000000Z',
        response_due_at: '2026-11-08T08:00:00.000000Z',
        next_step: { name: 'Senior revenue official', description: 'Ask a senior official.', due_at: '2026-11-08T08:00:00.000000Z' },
      }),
    })
    const user = userEvent.setup()
    renderApp('/disputes/4')

    const body = await screen.findByLabelText('Letter')
    expect(screen.getByRole('region', { name: 'Where to send it' })).toHaveTextContent('Billing query channel')
    await user.type(body, ' Please confirm receipt.')
    await user.click(screen.getByRole('button', { name: 'Save letter' }))
    expect(await screen.findByText('Your changes are saved.')).toBeInTheDocument()
    expect(find('PATCH', '/disputes/4')[0].json).toMatchObject({
      letter_body: 'Dear Sir or Madam,\n\nI dispute the following charges... Please confirm receipt.',
    })

    const submit = screen.getByRole('region', { name: 'Sent it? Mark it as submitted' })
    await user.selectOptions(within(submit).getByLabelText('How did you send it?'), 'email')
    await user.type(within(submit).getByLabelText(/Reference number/), 'QRY-1')
    await user.click(within(submit).getByRole('button', { name: 'Mark as submitted' }))

    expect(await screen.findByRole('button', { name: 'Escalate to Senior revenue official' })).toBeInTheDocument()
    expect(find('POST', '/disputes/4/submit')[0].json).toEqual({ channel: 'email', municipality_reference: 'QRY-1' })
    expect(screen.getByText('8 Nov 2026')).toBeInTheDocument()
  })

  it('saves unsaved letter changes before marking the dispute as submitted', async () => {
    const { requests, find } = disputeServer(makeDispute(), {
      'PATCH /disputes/4': (d, r) => ({ ...d, ...(r.json as Partial<Dispute>) }),
      'POST /disputes/4/submit': (d, r) => ({
        ...d,
        status: 'submitted',
        channel: (r.json as { channel: Dispute['channel'] }).channel,
        submitted_at: '2026-10-09T08:00:00.000000Z',
        response_due_at: '2026-11-08T08:00:00.000000Z',
      }),
    })
    const user = userEvent.setup()
    renderApp('/disputes/4')

    await user.type(await screen.findByLabelText('Letter'), ' Please read my meter.')
    expect(screen.getByText('You have unsaved changes.')).toBeInTheDocument()

    // Straight to "Mark as submitted" without pressing "Save letter".
    const submit = screen.getByRole('region', { name: 'Sent it? Mark it as submitted' })
    expect(submit).toHaveTextContent("Your letter has unsaved changes. We'll save them first")
    await user.selectOptions(within(submit).getByLabelText('How did you send it?'), 'email')
    await user.click(within(submit).getByRole('button', { name: 'Mark as submitted' }))

    // The sent letter on record is the edited one.
    await waitFor(() => expect(screen.queryByLabelText('Letter')).not.toBeInTheDocument())
    expect(screen.getByRole('region', { name: 'Your letter' })).toHaveTextContent('Please read my meter.')

    const writes = requests.filter((r) => r.method !== 'GET').map((r) => `${r.method} ${r.path}`)
    expect(writes).toEqual(['PATCH /disputes/4', 'POST /disputes/4/submit'])
    expect(find('PATCH', '/disputes/4')[0].json).toEqual({
      letter_subject: makeDispute().letter_subject,
      letter_body: 'Dear Sir or Madam,\n\nI dispute the following charges... Please read my meter.',
    })
    expect(find('POST', '/disputes/4/submit')[0].json).toEqual({ channel: 'email', municipality_reference: null })
  })

  it("doesn't submit when the unsaved letter can't be saved", async () => {
    const { find } = mockApi({
      'GET /disputes/4': { body: { data: makeDispute() } },
      'GET /metros': { body: { data: [makeMetro()] } },
      'GET /disputes': { body: { data: [makeDispute()] } },
      'PATCH /disputes/4': {
        status: 422,
        body: { message: 'The letter is too long.', errors: { letter_body: ['The letter is too long.'] } },
      },
    })
    const user = userEvent.setup()
    renderApp('/disputes/4')

    await user.type(await screen.findByLabelText('Letter'), ' More.')
    const submit = screen.getByRole('region', { name: 'Sent it? Mark it as submitted' })
    await user.selectOptions(within(submit).getByLabelText('How did you send it?'), 'portal')
    await user.click(within(submit).getByRole('button', { name: 'Mark as submitted' }))

    expect(await within(submit).findByText("We couldn't save your letter. The letter is too long.")).toBeInTheDocument()
    expect(find('PATCH', '/disputes/4')).toHaveLength(1)
    expect(find('POST', '/disputes/4/submit')).toHaveLength(0)
  })

  it('asks you to fix an emptied letter before marking it as submitted', async () => {
    const { requests } = disputeServer(makeDispute(), {})
    const user = userEvent.setup()
    renderApp('/disputes/4')

    await user.clear(await screen.findByLabelText('Subject'))
    const submit = screen.getByRole('region', { name: 'Sent it? Mark it as submitted' })
    await user.selectOptions(within(submit).getByLabelText('How did you send it?'), 'email')
    await user.click(within(submit).getByRole('button', { name: 'Mark as submitted' }))

    expect(within(submit).getByText(/Your letter needs a subject and some text/)).toBeInTheDocument()
    expect(requests.filter((r) => r.method !== 'GET')).toHaveLength(0)
  })

  it('escalates to the next step and closes as resolved with the recovered amount in cents', async () => {
    const submitted = makeDispute({
      status: 'acknowledged',
      channel: 'email',
      submitted_at: '2026-09-01T08:00:00.000000Z',
      response_due_at: '2026-10-01T08:00:00.000000Z',
      next_step: { name: 'Senior revenue official', description: 'Ask a senior official.', due_at: '2026-10-01T08:00:00.000000Z' },
    })
    const { find } = disputeServer(submitted, {
      'POST /disputes/4/escalate': (d) => ({ ...d, status: 'escalated', escalation_level: 1, next_step: null }),
      'POST /disputes/4/resolve': (d, r) => ({
        ...d,
        status: 'resolved',
        outcome_amount_cents: (r.json as { outcome_amount_cents: number }).outcome_amount_cents,
        resolved_at: '2026-10-09T08:00:00.000000Z',
        next_step: null,
      }),
    })
    const user = userEvent.setup()
    renderApp('/disputes/4')

    const escalate = await screen.findByRole('region', { name: "If the municipality doesn't respond" })
    expect(escalate).toHaveTextContent('Ask a senior official.')
    await user.click(within(escalate).getByRole('button', { name: 'Escalate to Senior revenue official' }))
    await screen.findByText('Escalated', { selector: 'span' })
    expect(find('POST', '/disputes/4/escalate')).toHaveLength(1)
    expect(screen.queryByRole('region', { name: "If the municipality doesn't respond" })).not.toBeInTheDocument()

    const close = screen.getByRole('region', { name: 'Close the dispute' })
    await user.click(within(close).getByRole('radio', { name: /Resolved/ }))
    await user.type(within(close).getByLabelText(/Amount recovered/), '1 523.40')
    await user.click(within(close).getByRole('button', { name: 'Close dispute' }))

    expect(await screen.findByText('This dispute is resolved')).toBeInTheDocument()
    expect(find('POST', '/disputes/4/resolve')[0].json).toEqual({
      outcome: 'resolved',
      outcome_amount_cents: 152340,
      note: null,
    })
  })
})
