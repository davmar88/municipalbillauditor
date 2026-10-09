import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router'
import { api, errorMessage, type Bill, type DisputeSummary, type LineItem, type Property } from '../api'
import { BillStatusBadge, DisputeStatusBadge, OverdueBadge } from '../components/Badge'
import { BillFieldsEditor, LineItemsEditor } from '../components/BillEditor'
import { Button } from '../components/Button'
import { Alert, ErrorState, FormError, LoadingState } from '../components/Feedback'
import { FindingCard } from '../components/FindingCard'
import { Detail, PageHeader, Section } from '../components/Page'
import { StartDisputePanel } from '../components/StartDisputePanel'
import { useMetro } from '../hooks/useMetros'
import { useToday } from '../hooks/useToday'
import {
  billFieldsFromBill,
  billFieldsToInput,
  emptyLineItem,
  lineItemsToInput,
  lineItemToDraft,
  type BillFieldsDraft,
  type LineItemDraft,
} from '../lib/billDraft'
import { daysUntil, formatDate, formatNumber, formatRand, formatRandOrDash } from '../lib/format'
import { mergeErrors } from '../lib/forms'
import { EXTRACTION_SOURCE_LABELS, READING_TYPE_LABELS, SERVICE_LABELS, unitLabel } from '../lib/labels'
import { invalidateMoneyData, queryKeys } from '../lib/queryKeys'

// ---------------------------------------------------------------------------
// Original file
// ---------------------------------------------------------------------------

/** Fetches the upload with the auth header and opens it in a new tab. */
function ViewOriginalButton({ billId }: { billId: number }) {
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fallbackUrl, setFallbackUrl] = useState<string | null>(null)
  const urls = useRef<string[]>([])

  useEffect(() => {
    const created = urls.current
    return () => created.forEach((url) => URL.revokeObjectURL(url))
  }, [])

  async function open() {
    setError(null)
    setLoading(true)
    // Open the tab straight away (while we still have the click) so pop-up blockers allow it.
    const tab = window.open('', '_blank')
    try {
      if (tab) {
        tab.document.title = 'Loading your bill…'
        tab.document.body.textContent = 'Loading your bill…'
      }
    } catch {
      // Some browsers don't let us write to the new tab; that's fine.
    }
    try {
      const blob = await api.getBillFile(billId)
      const url = URL.createObjectURL(blob)
      urls.current.push(url)
      if (tab) {
        tab.location.href = url
      } else {
        setFallbackUrl(url)
      }
    } catch (e) {
      tab?.close()
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex flex-col items-start gap-2">
      <Button variant="secondary" onClick={open} loading={loading}>
        View original
      </Button>
      {fallbackUrl && (
        <a
          href={fallbackUrl}
          target="_blank"
          rel="noreferrer"
          className="text-sm font-semibold text-brand-700 underline underline-offset-2"
        >
          Your browser blocked the new tab. Open your bill
        </a>
      )}
      {error && (
        <p role="alert" className="text-sm font-medium text-red-700">
          {error}
        </p>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Facts
// ---------------------------------------------------------------------------

function BillFacts({ bill, property }: { bill: Bill; property: Property | undefined }) {
  const metro = useMetro(property?.metro)
  const today = useToday()
  const days = bill.dispute_deadline ? daysUntil(bill.dispute_deadline, today) : null
  const deadlinePassed = days !== null && days < 0

  return (
    <Section id="facts" title="About this bill">
      <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Detail label="Bill date">{formatDate(bill.bill_date)}</Detail>
        <Detail label="Billing period">
          {bill.period_start || bill.period_end
            ? `${formatDate(bill.period_start)} to ${formatDate(bill.period_end)}`
            : 'Not known yet'}
        </Detail>
        <Detail label="Payment due">{formatDate(bill.due_date)}</Detail>
        <Detail label="Current charges total">
          <span className="tabular-nums">{formatRandOrDash(bill.total_cents)}</span>
        </Detail>
        <Detail label="Last day to dispute">
          {bill.dispute_deadline ? (
            <span className="flex flex-wrap items-center gap-2">
              <span className={deadlinePassed ? 'text-red-800' : undefined}>{formatDate(bill.dispute_deadline)}</span>
              {deadlinePassed && <OverdueBadge />}
            </span>
          ) : (
            'Add the bill date to see this'
          )}
          {bill.dispute_deadline && metro && !metro.verified && (
            <span className="mt-1 block text-sm font-normal text-amber-800">
              Unverified default for this municipality. We haven't yet confirmed this time limit against{' '}
              {metro.name}'s rules, so dispute as early as you can.
            </span>
          )}
        </Detail>
        <Detail label="Details entered">{EXTRACTION_SOURCE_LABELS[bill.extraction_source]}</Detail>
      </dl>
    </Section>
  )
}

// ---------------------------------------------------------------------------
// Line items
// ---------------------------------------------------------------------------

function LineItemView({ item }: { item: LineItem }) {
  const unit = unitLabel(item.unit)
  const reading =
    item.previous_reading !== null || item.current_reading !== null
      ? `${item.previous_reading !== null ? formatNumber(item.previous_reading) : '?'} → ${
          item.current_reading !== null ? formatNumber(item.current_reading) : '?'
        }`
      : null
  return (
    <li className="rounded-xl border border-slate-200 p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-semibold break-words text-slate-900">{item.description || SERVICE_LABELS[item.service]}</p>
          <p className="text-sm text-slate-600">
            {SERVICE_LABELS[item.service]}
            {item.tariff_category ? ` · ${item.tariff_category} tariff` : ''}
          </p>
        </div>
        <p className="shrink-0 font-semibold text-slate-900 tabular-nums">{formatRand(item.amount_cents)}</p>
      </div>
      <dl className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm">
        <div>
          <dt className="inline text-slate-600">Reading: </dt>
          <dd className={`inline ${item.reading_type === 'estimated' ? 'font-semibold text-amber-800' : 'text-slate-800'}`}>
            {READING_TYPE_LABELS[item.reading_type]}
          </dd>
        </div>
        {reading && (
          <div>
            <dt className="inline text-slate-600">Meter: </dt>
            <dd className="inline text-slate-800 tabular-nums">{reading}</dd>
          </div>
        )}
        {item.consumption !== null && (
          <div>
            <dt className="inline text-slate-600">Usage: </dt>
            <dd className="inline text-slate-800 tabular-nums">
              {formatNumber(item.consumption)}
              {unit ? ` ${unit}` : ''}
            </dd>
          </div>
        )}
      </dl>
    </li>
  )
}

function LineItemsSection({ bill, startEditing }: { bill: Bill; startEditing: boolean }) {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(startEditing)
  const [saved, setSaved] = useState(false)
  const [fields, setFields] = useState<BillFieldsDraft>(() => billFieldsFromBill(bill))
  const [items, setItems] = useState<LineItemDraft[]>(() =>
    bill.line_items.length > 0 ? bill.line_items.map(lineItemToDraft) : [emptyLineItem()],
  )
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})

  const mutation = useMutation({
    mutationFn: (input: Parameters<typeof api.updateBill>[1]) => api.updateBill(bill.id, input),
    onSuccess: async (updated) => {
      queryClient.setQueryData(queryKeys.bill(updated.id), updated)
      setEditing(false)
      setSaved(true)
      await invalidateMoneyData(queryClient)
    },
  })
  const errors = mergeErrors(clientErrors, mutation.error)

  function startEdit() {
    setFields(billFieldsFromBill(bill))
    setItems(bill.line_items.length > 0 ? bill.line_items.map(lineItemToDraft) : [emptyLineItem()])
    setClientErrors({})
    mutation.reset()
    setSaved(false)
    setEditing(true)
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const billFields = billFieldsToInput(fields)
    const lineItems = lineItemsToInput(items)
    const next = { ...billFields.errors, ...lineItems.errors }
    if (items.length === 0) next.line_items = 'Add at least one line item so we can check the bill.'
    setClientErrors(next)
    if (Object.keys(next).length > 0) {
      mutation.reset()
      return
    }
    mutation.mutate({ ...billFields.value, line_items: lineItems.value })
  }

  const shownFields = [
    'bill_date',
    'period_start',
    'period_end',
    'due_date',
    'total_cents',
    ...Object.keys(errors).filter((key) => key.startsWith('line_items.')),
  ]

  return (
    <Section
      id="line-items"
      title="Line items"
      description={
        editing
          ? 'Copy each charge from your bill. Saving replaces the line items and re-checks the bill.'
          : 'The charges on this bill.'
      }
      actions={
        !editing ? (
          <Button variant="secondary" size="sm" onClick={startEdit}>
            {bill.line_items.length > 0 ? 'Edit details' : 'Add line items'}
          </Button>
        ) : undefined
      }
    >
      {saved && !editing && (
        <Alert tone="success" className="mb-4">
          Saved. We've re-checked your bill with the new details.
        </Alert>
      )}
      {editing ? (
        <form onSubmit={handleSubmit} noValidate className="space-y-6">
          <FormError
            error={mutation.error}
            shownFields={shownFields}
            hasClientErrors={Object.keys(clientErrors).length > 0}
          />
          {clientErrors.line_items && <Alert tone="error">{clientErrors.line_items}</Alert>}
          <div>
            <h3 className="mb-3 font-semibold text-slate-900">Bill details</h3>
            <BillFieldsEditor value={fields} onChange={setFields} errors={errors} />
          </div>
          <div>
            <h3 className="mb-3 font-semibold text-slate-900">Charges</h3>
            <LineItemsEditor items={items} onChange={setItems} errors={errors} />
          </div>
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="secondary" onClick={() => setEditing(false)} disabled={mutation.isPending}>
              Cancel
            </Button>
            <Button type="submit" loading={mutation.isPending}>
              Save and re-check
            </Button>
          </div>
        </form>
      ) : bill.line_items.length === 0 ? (
        <p className="text-slate-600">No line items yet.</p>
      ) : (
        <ul className="space-y-3">
          {bill.line_items.map((item) => (
            <LineItemView key={item.id} item={item} />
          ))}
        </ul>
      )}
    </Section>
  )
}

// ---------------------------------------------------------------------------
// Findings
// ---------------------------------------------------------------------------

function disputeForFinding(disputes: DisputeSummary[] | undefined, findingId: number): number | undefined {
  return disputes?.find((d) => d.finding_ids.includes(findingId))?.id
}

function FindingsSection({ bill, disputes }: { bill: Bill; disputes: DisputeSummary[] | undefined }) {
  const queryClient = useQueryClient()
  const [starting, setStarting] = useState(false)
  const startButton = useRef<HTMLButtonElement>(null)
  const returnFocus = useRef(false)
  const openFindings = bill.findings.filter((f) => f.status === 'open')

  // After cancelling, put keyboard focus back on the button that opened the panel.
  useEffect(() => {
    if (!starting && returnFocus.current) {
      returnFocus.current = false
      startButton.current?.focus()
    }
  }, [starting])

  function cancelStarting() {
    returnFocus.current = true
    setStarting(false)
  }
  const audit = useMutation({
    mutationFn: () => api.auditBill(bill.id),
    onSuccess: async (updated) => {
      queryClient.setQueryData(queryKeys.bill(updated.id), updated)
      await invalidateMoneyData(queryClient)
    },
  })

  const important = bill.findings.filter((f) => f.severity !== 'low')
  const info = bill.findings.filter((f) => f.severity === 'low')

  return (
    <>
      <Section
        id="findings"
        title="What we found"
        description="These are possible problems, not certainties. Check each one against your bill and meter before you dispute it."
        actions={
          <>
            <Button variant="secondary" size="sm" onClick={() => audit.mutate()} loading={audit.isPending}>
              Re-check bill
            </Button>
            {openFindings.length > 0 && !starting && (
              <Button ref={startButton} size="sm" onClick={() => setStarting(true)}>
                Start a dispute
              </Button>
            )}
          </>
        }
      >
        {audit.isError && (
          <Alert tone="error" className="mb-4">
            We couldn't re-check the bill. {errorMessage(audit.error)}
          </Alert>
        )}
        {audit.isSuccess && (
          <Alert tone="success" className="mb-4">
            We've re-checked this bill.
          </Alert>
        )}
        {bill.findings.length === 0 ? (
          bill.status === 'audited' ? (
            <p className="text-slate-700">
              We didn't spot any likely problems on this bill. That's good news, but it isn't a guarantee. If
              something still looks wrong to you, compare the readings with your own meter.
            </p>
          ) : (
            <p className="text-slate-700">We'll check this bill as soon as it has line items.</p>
          )
        ) : (
          <div className="space-y-4">
            {important.map((finding) => (
              <FindingCard key={finding.id} finding={finding} disputeId={disputeForFinding(disputes, finding.id)} />
            ))}
            {info.length > 0 && (
              <div className="space-y-3 pt-2">
                <h3 className="text-sm font-semibold tracking-wide text-slate-600 uppercase">For information</h3>
                <p className="text-sm text-slate-600">
                  Things worth knowing that are less likely to be a mistake. They don't count towards your totals.
                </p>
                {info.map((finding) => (
                  <FindingCard
                    key={finding.id}
                    finding={finding}
                    disputeId={disputeForFinding(disputes, finding.id)}
                  />
                ))}
              </div>
            )}
          </div>
        )}
      </Section>
      {starting && openFindings.length > 0 && (
        <StartDisputePanel bill={bill} openFindings={openFindings} onCancel={cancelStarting} />
      )}
    </>
  )
}

function BillDisputes({ disputes }: { disputes: DisputeSummary[] }) {
  if (disputes.length === 0) return null
  return (
    <Section id="bill-disputes" title="Disputes for this bill">
      <ul className="space-y-2">
        {disputes.map((dispute) => (
          <li key={dispute.id}>
            <Link
              to={`/disputes/${dispute.id}`}
              className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 p-3 hover:border-brand-300 hover:bg-brand-50/40"
            >
              <span className="font-medium text-slate-900">{dispute.letter_subject}</span>
              <span className="flex items-center gap-2">
                <span className="text-sm text-slate-700 tabular-nums">{formatRand(dispute.amount_disputed_cents)}</span>
                <DisputeStatusBadge status={dispute.status} />
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </Section>
  )
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export function BillDetailPage() {
  const billId = Number(useParams().billId)

  const bill = useQuery({
    queryKey: queryKeys.bill(billId),
    queryFn: () => api.getBill(billId),
    // Keep checking while the AI is reading the bill.
    refetchInterval: (query) => (query.state.data?.status === 'extracting' ? 4000 : false),
  })
  const propertyId = bill.data?.property_id
  const property = useQuery({
    queryKey: queryKeys.property(propertyId ?? 0),
    queryFn: () => api.getProperty(propertyId ?? 0),
    enabled: propertyId !== undefined,
  })
  const disputes = useQuery({ queryKey: queryKeys.disputes, queryFn: api.listDisputes })

  if (bill.isPending) return <LoadingState label="Loading your bill…" />
  if (bill.isError) {
    return (
      <>
        <PageHeader title="Bill" crumbs={[{ label: 'Overview', to: '/' }, { label: 'Bill' }]} />
        <ErrorState error={bill.error} onRetry={() => bill.refetch()} />
      </>
    )
  }

  const b = bill.data
  const title = b.bill_date ? `Bill dated ${formatDate(b.bill_date)}` : 'Bill'
  const billDisputes = (disputes.data ?? []).filter((d) => d.bill_id === b.id)

  return (
    <>
      <PageHeader
        title={title}
        crumbs={[
          { label: 'Overview', to: '/' },
          { label: property.data?.nickname ?? 'Property', to: `/properties/${b.property_id}` },
          { label: title },
        ]}
        badges={<BillStatusBadge status={b.status} />}
        actions={b.has_file ? <ViewOriginalButton billId={b.id} /> : undefined}
      />
      <div className="space-y-6">
        {b.status === 'needs_review' && (
          <Alert tone="warning" title="Please add the line items">
            We have your bill, but not its charges yet. Type in the line items below so we can check it.
          </Alert>
        )}
        {b.status === 'extracting' && (
          <Alert tone="info" title="We're reading your bill">
            This usually takes less than a minute. This page will update by itself.
          </Alert>
        )}
        {b.status === 'extraction_failed' && (
          <Alert tone="warning" title="We couldn't read this bill automatically">
            Please type in the line items below so we can check it.
          </Alert>
        )}

        <BillFacts bill={b} property={property.data} />
        <BillDisputes disputes={billDisputes} />
        <FindingsSection bill={b} disputes={disputes.data} />
        {b.status !== 'extracting' && (
          <LineItemsSection
            key={b.id}
            bill={b}
            startEditing={b.status === 'needs_review' || b.status === 'extraction_failed'}
          />
        )}
      </div>
    </>
  )
}
