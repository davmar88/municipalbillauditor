import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Bill } from '../api'
import { makeBill, makeDispute, makeFinding, makeLineItem, makeMetro, makeProperty } from '../test/fixtures'
import { mockApi, renderApp, type MockResponse } from '../test/utils'

function billRoutes(bill: Bill = makeBill(), extra: Record<string, MockResponse> = {}) {
  return {
    'GET /bills/5': { body: { data: bill } },
    'GET /properties/3': { body: { data: makeProperty() } },
    'GET /metros': { body: { data: [makeMetro({ verified: false })] } },
    'GET /disputes': { body: { data: [] } },
    ...extra,
  }
}

function findingCard(title: string): HTMLElement {
  return screen.getByRole('article', { name: title })
}

describe('Bill detail findings', () => {
  it('shows findings with severity, confidence and possible overcharge', async () => {
    mockApi(billRoutes())
    renderApp('/bills/5')

    const card = await screen.findByRole('article', { name: 'Water use is 3.1 times your usual' })
    expect(within(card).getByText('Medium concern')).toBeInTheDocument()
    expect(within(card).getByText('80%')).toBeInTheDocument()
    expect(within(card).getByText('R980.00')).toBeInTheDocument()

    // No overcharge line when the rule couldn't estimate one.
    const high = findingCard('Your water has been estimated three months in a row')
    expect(within(high).getByText('High concern')).toBeInTheDocument()
    expect(within(high).queryByText(/Possible overcharge/)).not.toBeInTheDocument()
  })

  it('labels low-severity findings "For information"', async () => {
    mockApi(billRoutes())
    renderApp('/bills/5')

    const low = await screen.findByRole('article', { name: "The total doesn't match the line items" })
    expect(within(low).getByText('For information')).toBeInTheDocument()
    expect(within(low).queryByText(/concern/)).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'For information', level: 3 })).toBeInTheDocument()
  })

  it('dismisses a finding through the API', async () => {
    // A tiny stateful server: after the PATCH, the bill comes back with the finding dismissed.
    let bill = makeBill()
    const { find } = mockApi({
      ...billRoutes(bill),
      'GET /bills/5': () => ({ body: { data: bill } }),
      'PATCH /findings/7': () => {
        const dismissed = { ...bill.findings[1], status: 'dismissed' as const }
        bill = { ...bill, findings: bill.findings.map((f) => (f.id === 7 ? dismissed : f)) }
        return { body: { data: dismissed } }
      },
    })
    const user = userEvent.setup()
    renderApp('/bills/5')

    const card = await screen.findByRole('article', { name: 'Water use is 3.1 times your usual' })
    await user.click(within(card).getByRole('button', { name: /Dismiss/ }))

    const [patch] = find('PATCH', '/findings/7')
    expect(patch.json).toEqual({ status: 'dismissed' })
    expect(await within(card).findByRole('button', { name: /Reopen/ })).toBeInTheDocument()
  })

  it('shows the unverified hint next to the dispute deadline', async () => {
    mockApi(billRoutes())
    renderApp('/bills/5')

    expect(await screen.findByText('25 Oct 2026')).toBeInTheDocument()
    expect(await screen.findByText(/Unverified default for this municipality/)).toBeInTheDocument()
  })

  it('shows the bill total and line items', async () => {
    mockApi(billRoutes())
    renderApp('/bills/5')

    expect(await screen.findByText('R4 123.50')).toBeInTheDocument()
    const lineItems = screen.getByRole('region', { name: 'Line items' })
    expect(within(lineItems).getByText('R1 523.40')).toBeInTheDocument()
    expect(within(lineItems).getByText('Estimated reading')).toBeInTheDocument()
  })
})

describe('Starting a dispute', () => {
  it('preselects medium and high findings, creates the dispute and opens it', async () => {
    const dispute = makeDispute({ finding_ids: [8, 7] })
    const { find } = mockApi(
      billRoutes(makeBill(), {
        'POST /bills/5/disputes': { status: 201, body: { data: dispute } },
        'GET /disputes/4': { body: { data: dispute } },
      }),
    )
    const user = userEvent.setup()
    renderApp('/bills/5')

    await user.click(await screen.findByRole('button', { name: 'Start a dispute' }))

    const panel = screen.getByRole('form', { name: 'Start a dispute' })
    const high = within(panel).getByRole('checkbox', { name: /estimated three months in a row/ })
    const medium = within(panel).getByRole('checkbox', { name: /Water use is 3.1 times/ })
    const low = within(panel).getByRole('checkbox', { name: /total doesn't match/ })
    expect(high).toBeChecked()
    expect(medium).toBeChecked()
    expect(low).not.toBeChecked()

    await user.click(within(panel).getByRole('button', { name: 'Create dispute letter' }))

    expect(await screen.findByRole('heading', { name: 'Dispute: Sunset Court', level: 1 })).toBeInTheDocument()
    const [post] = find('POST', '/bills/5/disputes')
    expect(post.json).toEqual({ finding_ids: [8, 7] })
    expect(screen.getByLabelText('Subject')).toHaveValue(dispute.letter_subject)
  })

  it('lets you include a low finding and requires at least one', async () => {
    const dispute = makeDispute({ finding_ids: [9] })
    const { find } = mockApi(
      billRoutes(makeBill(), {
        'POST /bills/5/disputes': { status: 201, body: { data: dispute } },
        'GET /disputes/4': { body: { data: dispute } },
      }),
    )
    const user = userEvent.setup()
    renderApp('/bills/5')

    await user.click(await screen.findByRole('button', { name: 'Start a dispute' }))
    const panel = screen.getByRole('form', { name: 'Start a dispute' })
    await user.click(within(panel).getByRole('checkbox', { name: /estimated three months in a row/ }))
    await user.click(within(panel).getByRole('checkbox', { name: /Water use is 3.1 times/ }))
    await user.click(within(panel).getByRole('button', { name: 'Create dispute letter' }))

    expect(within(panel).getByText('Choose at least one finding to include in your dispute.')).toBeInTheDocument()
    expect(find('POST', '/bills/5/disputes')).toHaveLength(0)

    await user.click(within(panel).getByRole('checkbox', { name: /total doesn't match/ }))
    await user.click(within(panel).getByRole('button', { name: 'Create dispute letter' }))

    await screen.findByRole('heading', { name: 'Dispute: Sunset Court', level: 1 })
    expect(find('POST', '/bills/5/disputes')[0].json).toEqual({ finding_ids: [9] })
  })

  it("shows the server's possible overcharge, which counts a charge only once", async () => {
    // Seeded Johannesburg bill: two findings are about the same water charge,
    // so the server's total (R4 234.71) is less than the sum of the three (R5 144.71).
    const bill = makeBill({
      findings_summary: { open_count: 3, high_count: 1, potential_overcharge_cents: 423471 },
      findings: [
        makeFinding({ id: 4, severity: 'high', title: 'Electricity charged during a full outage', estimated_overcharge_cents: 319600 }),
        makeFinding({ id: 1, rule: 'estimated_reading', title: 'Your water reading was estimated', estimated_overcharge_cents: 91000 }),
        makeFinding({ id: 3, rule: 'outage_charge', title: 'Water charged during an outage', estimated_overcharge_cents: 103871 }),
      ],
    })
    mockApi(billRoutes(bill))
    const user = userEvent.setup()
    renderApp('/bills/5')

    await user.click(await screen.findByRole('button', { name: 'Start a dispute' }))
    const panel = screen.getByRole('form', { name: 'Start a dispute' })
    expect(panel).toHaveTextContent('3 selected · possible overcharge R4 234.71')
    expect(panel).not.toHaveTextContent('R5 144.71')
    expect(panel).toHaveTextContent('we count that charge only once')

    // With a different selection we don't add amounts up ourselves.
    await user.click(within(panel).getByRole('checkbox', { name: /full outage/ }))
    expect(panel).toHaveTextContent('2 selected')
    expect(panel).not.toHaveTextContent(/possible overcharge R/)
    expect(panel).not.toHaveTextContent('R1 948.71')
    expect(panel).toHaveTextContent("We'll work out the amount to dispute when we prepare your letter.")
  })

  it('moves focus to the panel when it opens and back to the button when cancelled', async () => {
    // jsdom has no scrolling; record what would be scrolled into view.
    const scrolled: Element[] = []
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolled.push(this)
    }
    mockApi(billRoutes())
    const user = userEvent.setup()
    renderApp('/bills/5')

    await user.click(await screen.findByRole('button', { name: 'Start a dispute' }))
    expect(screen.getByRole('heading', { name: 'Start a dispute', level: 2 })).toHaveFocus()

    const panel = screen.getByRole('form', { name: 'Start a dispute' })
    expect(scrolled).toEqual([panel])
    delete (Element.prototype as Partial<Element>).scrollIntoView

    await user.click(within(panel).getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('form', { name: 'Start a dispute' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Start a dispute' })).toHaveFocus()
  })

  it('only offers open findings', async () => {
    const bill = makeBill({
      findings: [
        makeFinding({ id: 7, status: 'open' }),
        makeFinding({ id: 11, status: 'dismissed', title: 'A dismissed finding' }),
      ],
    })
    mockApi(billRoutes(bill))
    const user = userEvent.setup()
    renderApp('/bills/5')

    await user.click(await screen.findByRole('button', { name: 'Start a dispute' }))
    const panel = screen.getByRole('form', { name: 'Start a dispute' })
    expect(within(panel).getAllByRole('checkbox')).toHaveLength(1)
    expect(within(panel).queryByText('A dismissed finding')).not.toBeInTheDocument()
  })
})

describe('Editing line items', () => {
  it('re-works out usage from corrected readings instead of keeping the old usage', async () => {
    // Saved as 32270 -> 33620 with the server's worked-out 1350 kWh; the real current reading is 32680.
    const bill = makeBill({
      line_items: [
        makeLineItem({
          service: 'electricity',
          description: 'Electricity',
          reading_type: 'actual',
          previous_reading: 32270,
          current_reading: 33620,
          consumption: 1350,
          unit: 'kwh',
        }),
      ],
    })
    const { find } = mockApi(billRoutes(bill, { 'PUT /bills/5': { body: { data: bill } } }))
    const user = userEvent.setup()
    renderApp('/bills/5')

    await user.click(await screen.findByRole('button', { name: 'Edit details' }))
    const usage = screen.getByLabelText(/Usage/)
    expect(usage).toHaveValue('')
    expect(usage).toHaveAccessibleDescription(/work it out from the readings: 1 350 kWh/)

    const current = screen.getByLabelText(/Current reading/)
    await user.clear(current)
    await user.type(current, '32680')
    expect(usage).toHaveAccessibleDescription(/work it out from the readings: 410 kWh/)
    await user.click(screen.getByRole('button', { name: 'Save and re-check' }))

    expect(await screen.findByText(/Saved. We've re-checked your bill/)).toBeInTheDocument()
    const [put] = find('PUT', '/bills/5')
    expect((put.json as { line_items: unknown[] }).line_items[0]).toMatchObject({
      previous_reading: 32270,
      current_reading: 32680,
      consumption: null,
    })
  })

  it('points out a typed usage that no longer matches the readings', async () => {
    mockApi(billRoutes(makeBill({ line_items: [makeLineItem({ consumption: 380 })] })))
    const user = userEvent.setup()
    renderApp('/bills/5')

    await user.click(await screen.findByRole('button', { name: 'Edit details' }))
    expect(screen.getByLabelText(/Usage/)).toHaveValue('380')
    expect(screen.getByLabelText(/Usage/)).toHaveAccessibleDescription(/doesn't match the readings, which show 38 kl/)
  })

  it('asks which number you meant when a reading has a comma before three digits', async () => {
    const { find } = mockApi(billRoutes())
    const user = userEvent.setup()
    renderApp('/bills/5')

    await user.click(await screen.findByRole('button', { name: 'Edit details' }))
    const previous = screen.getByLabelText(/Previous reading/)
    await user.clear(previous)
    await user.type(previous, '19,330')
    await user.click(screen.getByRole('button', { name: 'Save and re-check' }))

    expect(previous).toHaveAccessibleDescription(/Did you mean 19330 or 19.33\?/)
    expect(find('PUT', '/bills/5')).toHaveLength(0)
  })
})
