import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import {
  api,
  DISPUTE_CHANNELS,
  LOGGABLE_EVENT_TYPES,
  type Dispute,
  type DisputeChannel,
  type DisputeOutcome,
  type LoggableEventType,
  type Metro,
  type MetroCode,
} from '../api'
import { DisputeStatusBadge, OverdueBadge } from '../components/Badge'
import { Button, ButtonLink } from '../components/Button'
import { ConfirmAction } from '../components/ConfirmAction'
import { Alert, ErrorState, FormError, LoadingState } from '../components/Feedback'
import { MoneyField, SelectField, TextAreaField, TextField } from '../components/Field'
import { Detail, PageHeader, Section } from '../components/Page'
import { useMetro } from '../hooks/useMetros'
import { useToday } from '../hooks/useToday'
import { daysUntil, describeDue, formatDate, formatDateTime, formatRand, parseRandToCents } from '../lib/format'
import { mergeErrors } from '../lib/forms'
import {
  DISPUTE_CHANNEL_LABELS,
  DISPUTE_EVENT_LABELS,
  isActiveDispute,
  LOGGABLE_EVENT_LABELS,
  METRO_LABELS,
} from '../lib/labels'
import { invalidateMoneyData, queryKeys } from '../lib/queryKeys'

/** Every dispute mutation returns the updated dispute. */
function useDisputeMutation<TInput>(disputeId: number, fn: (input: TInput) => Promise<Dispute>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: async (dispute) => {
      queryClient.setQueryData(queryKeys.dispute(disputeId), dispute)
      await refreshAfterDisputeChange(queryClient)
    },
  })
}

function refreshAfterDisputeChange(queryClient: QueryClient) {
  return invalidateMoneyData(queryClient)
}

// ---------------------------------------------------------------------------
// Facts
// ---------------------------------------------------------------------------

function DisputeFacts({ dispute, metro }: { dispute: Dispute; metro: Metro | undefined }) {
  const today = useToday()
  const lodgeDays = dispute.lodge_deadline ? daysUntil(dispute.lodge_deadline, today) : null
  const responseDays = dispute.response_due_at ? daysUntil(dispute.response_due_at, today) : null
  const lodgeOverdue = dispute.status === 'draft' && lodgeDays !== null && lodgeDays < 0
  const responseOverdue = isActiveDispute(dispute.status) && responseDays !== null && responseDays < 0
  const stage = metro?.escalation_steps.find((step) => step.level === dispute.escalation_level)

  return (
    <Section id="facts" title="Where things stand">
      <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Detail label="Amount disputed">
          <span className="tabular-nums">{formatRand(dispute.amount_disputed_cents)}</span>
        </Detail>
        <Detail label="Lodge by">
          {dispute.lodge_deadline ? (
            <span className="flex flex-wrap items-center gap-2">
              {formatDate(dispute.lodge_deadline)}
              {lodgeOverdue && <OverdueBadge />}
              {dispute.status === 'draft' && !lodgeOverdue && (
                <span className="text-sm font-normal text-slate-600">{describeDue(dispute.lodge_deadline, today)}</span>
              )}
            </span>
          ) : (
            'Not known (the bill has no date)'
          )}
          {dispute.lodge_deadline && metro && !metro.verified && (
            <span className="mt-1 block text-sm font-normal text-amber-800">
              Unverified default for this municipality.
            </span>
          )}
        </Detail>
        <Detail label="Response due">
          {dispute.response_due_at ? (
            <span className="flex flex-wrap items-center gap-2">
              {formatDate(dispute.response_due_at)}
              {responseOverdue && <OverdueBadge />}
            </span>
          ) : (
            'After you send the dispute'
          )}
        </Detail>
        <Detail label="Sent">
          {dispute.submitted_at
            ? `${formatDate(dispute.submitted_at)}${dispute.channel ? ` by ${DISPUTE_CHANNEL_LABELS[dispute.channel].toLowerCase()}` : ''}`
            : 'Not yet'}
        </Detail>
        <Detail label="Municipality's reference">{dispute.municipality_reference || 'None yet'}</Detail>
        <Detail label="Current stage">{stage ? stage.name : `Level ${dispute.escalation_level}`}</Detail>
        {dispute.status === 'resolved' && (
          <Detail label="Amount recovered">
            {dispute.outcome_amount_cents !== null ? formatRand(dispute.outcome_amount_cents) : 'Not recorded'}
          </Detail>
        )}
        {dispute.resolved_at && <Detail label="Closed">{formatDate(dispute.resolved_at)}</Detail>}
      </dl>
    </Section>
  )
}

// ---------------------------------------------------------------------------
// Letter
// ---------------------------------------------------------------------------

function CopyLetterButton({ subject, body }: { subject: string; body: string }) {
  const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle')
  async function copy() {
    try {
      await navigator.clipboard.writeText(`${subject}\n\n${body}`)
      setState('copied')
    } catch {
      setState('failed')
    }
  }
  return (
    <div className="flex flex-col items-start gap-1">
      <Button variant="secondary" size="sm" onClick={copy}>
        Copy letter
      </Button>
      <span aria-live="polite" className="text-sm text-slate-600">
        {state === 'copied' && 'Copied. You can paste it into an email or the municipal portal.'}
        {state === 'failed' && "We couldn't copy automatically. Please select the text and copy it yourself."}
      </span>
    </div>
  )
}

/** The letter as it is in the form, which may differ from what's saved. */
interface LetterDraft {
  subject: string
  body: string
}

function letterChanged(letter: LetterDraft, dispute: Dispute): boolean {
  return letter.subject !== dispute.letter_subject || letter.body !== dispute.letter_body
}

function letterErrors(letter: LetterDraft): Record<string, string> {
  const errors: Record<string, string> = {}
  if (!letter.subject.trim()) errors.letter_subject = 'The letter needs a subject.'
  if (!letter.body.trim()) errors.letter_body = 'The letter needs some text.'
  return errors
}

function DraftLetter({
  dispute,
  letter,
  onLetterChange,
}: {
  dispute: Dispute
  letter: LetterDraft
  onLetterChange: (letter: LetterDraft) => void
}) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { subject, body } = letter
  const setSubject = (next: string) => onLetterChange({ ...letter, subject: next })
  const setBody = (next: string) => onLetterChange({ ...letter, body: next })
  const [saved, setSaved] = useState(false)
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})

  const save = useDisputeMutation(dispute.id, (input: { letter_subject: string; letter_body: string }) =>
    api.updateDispute(dispute.id, input),
  )
  const remove = useMutation({
    mutationFn: () => api.deleteDispute(dispute.id),
    onSuccess: () => {
      navigate(`/bills/${dispute.bill_id}`, { replace: true })
      queryClient.removeQueries({ queryKey: queryKeys.dispute(dispute.id) })
      void refreshAfterDisputeChange(queryClient)
    },
  })

  const errors = mergeErrors(clientErrors, save.error)
  const changed = letterChanged(letter, dispute)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next = letterErrors(letter)
    setClientErrors(next)
    if (Object.keys(next).length > 0) return
    setSaved(false)
    save.mutate(
      { letter_subject: subject, letter_body: body },
      { onSuccess: () => setSaved(true) },
    )
  }

  return (
    <Section
      id="letter"
      title="Your letter"
      description="We drafted this from your findings. Read it carefully and change anything that isn't right before you send it. It isn't legal advice."
    >
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        <FormError
          error={save.error}
          shownFields={['letter_subject', 'letter_body']}
          hasClientErrors={Object.keys(clientErrors).length > 0}
        />
        {saved && !changed && <Alert tone="success">Your changes are saved.</Alert>}
        <TextField
          label="Subject"
          value={subject}
          onChange={(e) => setSubject(e.target.value)}
          error={errors.letter_subject}
        />
        <TextAreaField
          label="Letter"
          value={body}
          onChange={(e) => setBody(e.target.value)}
          error={errors.letter_body}
          rows={18}
          className="[&_textarea]:leading-relaxed"
        />
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <CopyLetterButton subject={subject} body={body} />
          <div className="flex flex-wrap items-center gap-3">
            {changed && <span className="text-sm text-amber-800">You have unsaved changes.</span>}
            <Button type="submit" loading={save.isPending} disabled={!changed}>
              Save letter
            </Button>
          </div>
        </div>
      </form>
      <div className="mt-6 border-t border-slate-200 pt-4">
        {remove.isError && (
          <Alert tone="error" className="mb-3">
            We couldn't delete the draft. Please try again.
          </Alert>
        )}
        <ConfirmAction
          label="Delete draft"
          message="Delete this draft dispute? Its findings will go back to open so you can dispute them later."
          confirmLabel="Yes, delete the draft"
          pending={remove.isPending}
          onConfirm={() => remove.mutate()}
          size="sm"
        />
      </div>
    </Section>
  )
}

function SentLetter({ dispute }: { dispute: Dispute }) {
  return (
    <Section
      id="letter"
      title="Your letter"
      actions={<CopyLetterButton subject={dispute.letter_subject} body={dispute.letter_body} />}
    >
      <p className="font-semibold text-slate-900">{dispute.letter_subject}</p>
      <div className="mt-3 max-h-[28rem] overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-relaxed whitespace-pre-wrap text-slate-800">
        {dispute.letter_body}
      </div>
    </Section>
  )
}

// ---------------------------------------------------------------------------
// Sending
// ---------------------------------------------------------------------------

function DisputeChannels({ metro, metroCode }: { metro: Metro | undefined; metroCode: MetroCode }) {
  return (
    <Section id="channels" title="Where to send it" description={metro ? metro.name : METRO_LABELS[metroCode]}>
      {metro && !metro.verified && (
        <Alert tone="warning" className="mb-4">
          Unverified default for this municipality. These details haven't been confirmed against the
          municipality's own rules yet. Check your bill for the official query contact details.
        </Alert>
      )}
      {!metro || metro.dispute_channels.length === 0 ? (
        <p className="text-slate-700">
          Use the query email address, online portal or walk-in centre printed on your municipal bill.
        </p>
      ) : (
        <ul className="space-y-3">
          {metro.dispute_channels.map((channel, index) => (
            <li key={index} className="rounded-xl border border-slate-200 p-4">
              <p className="font-semibold text-slate-900">{channel.label}</p>
              <p className="mt-1 text-sm break-words text-slate-700">{channel.value}</p>
            </li>
          ))}
        </ul>
      )}
    </Section>
  )
}

function SubmitForm({ dispute, letter }: { dispute: Dispute; letter: LetterDraft }) {
  const queryClient = useQueryClient()
  const [channel, setChannel] = useState<DisputeChannel | ''>('')
  const [reference, setReference] = useState('')
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})
  const unsavedLetter = letterChanged(letter, dispute)
  // A letter can only be changed while the dispute is a draft, so unsaved
  // changes are saved before submitting. Otherwise the letter you copied and
  // sent would differ from the one we keep on record, for good.
  const mutation = useDisputeMutation(
    dispute.id,
    async (input: { channel: DisputeChannel; municipality_reference: string | null; letter: LetterDraft | null }) => {
      if (input.letter) {
        const saved = await api.updateDispute(dispute.id, {
          letter_subject: input.letter.subject,
          letter_body: input.letter.body,
        })
        queryClient.setQueryData(queryKeys.dispute(dispute.id), saved)
      }
      return api.submitDispute(dispute.id, {
        channel: input.channel,
        municipality_reference: input.municipality_reference,
      })
    },
  )
  const errors = mergeErrors(clientErrors, mutation.error)
  const serverLetterError = errors.letter_subject ?? errors.letter_body
  const letterProblem =
    errors.letter ?? (serverLetterError ? `We couldn't save your letter. ${serverLetterError}` : undefined)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next: Record<string, string> = {}
    if (!channel) next.channel = 'Please tell us how you sent it.'
    if (unsavedLetter && Object.keys(letterErrors(letter)).length > 0) {
      next.letter = 'Your letter needs a subject and some text. Please fix it above before you mark it as submitted.'
    }
    setClientErrors(next)
    if (Object.keys(next).length > 0 || !channel) {
      mutation.reset()
      return
    }
    mutation.mutate({
      channel,
      municipality_reference: reference.trim() || null,
      letter: unsavedLetter ? letter : null,
    })
  }

  return (
    <Section
      id="submit"
      title="Sent it? Mark it as submitted"
      description="Once you've sent the letter to the municipality, let us know so we can track when they should reply."
    >
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        <FormError
          error={mutation.error}
          shownFields={['channel', 'municipality_reference', 'letter_subject', 'letter_body']}
          hasClientErrors={Object.keys(clientErrors).some((key) => key !== 'letter')}
        />
        {letterProblem && <Alert tone="error">{letterProblem}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField
            label="How did you send it?"
            value={channel}
            onChange={(e) => setChannel(e.target.value as DisputeChannel | '')}
            error={errors.channel}
            required
          >
            <option value="">Choose</option>
            {DISPUTE_CHANNELS.map((c) => (
              <option key={c} value={c}>
                {DISPUTE_CHANNEL_LABELS[c]}
              </option>
            ))}
          </SelectField>
          <TextField
            label="Reference number"
            optional
            hint="If the municipality gave you a query or reference number."
            value={reference}
            onChange={(e) => setReference(e.target.value)}
            error={errors.municipality_reference}
          />
        </div>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
          {unsavedLetter && (
            <p className="text-sm text-amber-800">
              Your letter has unsaved changes. We'll save them first, so our copy matches the letter you sent.
            </p>
          )}
          <Button type="submit" loading={mutation.isPending} className="shrink-0">
            Mark as submitted
          </Button>
        </div>
      </form>
    </Section>
  )
}

// ---------------------------------------------------------------------------
// After sending
// ---------------------------------------------------------------------------

function EscalatePanel({ dispute }: { dispute: Dispute }) {
  const today = useToday()
  const [note, setNote] = useState('')
  const mutation = useDisputeMutation(dispute.id, (input: { note: string | null }) =>
    api.escalateDispute(dispute.id, input),
  )
  const step = dispute.next_step
  if (!step) return null
  const days = daysUntil(step.due_at, today)
  const due = days !== null && days <= 0

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    mutation.mutate({ note: note.trim() || null })
  }

  return (
    <Section
      id="escalate"
      title="If the municipality doesn't respond"
      description={
        due
          ? 'The response time has passed. You can take this to the next step.'
          : `If you haven't had a proper response by ${formatDate(step.due_at)}, you can take this to the next step.`
      }
    >
      <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
        <p className="text-sm text-slate-600">Next step</p>
        <p className="font-semibold text-slate-900">{step.name}</p>
        <p className="mt-1 text-sm leading-relaxed text-slate-700">{step.description}</p>
      </div>
      <form onSubmit={handleSubmit} noValidate className="mt-4 space-y-4">
        <FormError error={mutation.error} shownFields={['note']} />
        <TextAreaField
          label="Note for your records"
          optional
          value={note}
          onChange={(e) => setNote(e.target.value)}
          rows={2}
        />
        <div className="flex justify-end">
          <Button type="submit" variant={due ? 'primary' : 'secondary'} loading={mutation.isPending}>
            Escalate to {step.name}
          </Button>
        </div>
      </form>
    </Section>
  )
}

function LogEventForm({ dispute }: { dispute: Dispute }) {
  const types = LOGGABLE_EVENT_TYPES.filter((t) => {
    // Once a dispute is closed, only notes make sense.
    if (!isActiveDispute(dispute.status)) return t === 'note'
    // "Acknowledged" moves a dispute on from "sent", so only offer it then.
    return t !== 'acknowledged' || dispute.status === 'submitted'
  })
  const [type, setType] = useState<LoggableEventType>(types[0])
  const [note, setNote] = useState('')
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})
  const [logged, setLogged] = useState(false)
  const mutation = useDisputeMutation(dispute.id, (input: { type: LoggableEventType; note: string | null }) =>
    api.addDisputeEvent(dispute.id, input),
  )
  const errors = mergeErrors(clientErrors, mutation.error)
  const selectedType = types.includes(type) ? type : types[0]

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setLogged(false)
    if (selectedType === 'note' && !note.trim()) {
      setClientErrors({ note: 'Please write your note.' })
      return
    }
    setClientErrors({})
    mutation.mutate(
      { type: selectedType, note: note.trim() || null },
      {
        onSuccess: () => {
          setNote('')
          setLogged(true)
        },
      },
    )
  }

  return (
    <Section id="log" title="Log an update" description="Keep a record of what the municipality tells you.">
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        <FormError error={mutation.error} shownFields={['type', 'note']} hasClientErrors={Object.keys(clientErrors).length > 0} />
        {logged && <Alert tone="success">Added to the timeline.</Alert>}
        <SelectField
          label="What happened?"
          value={selectedType}
          onChange={(e) => setType(e.target.value as LoggableEventType)}
          error={errors.type}
        >
          {types.map((t) => (
            <option key={t} value={t}>
              {LOGGABLE_EVENT_LABELS[t]}
            </option>
          ))}
        </SelectField>
        <TextAreaField
          label="Details"
          optional={selectedType !== 'note'}
          hint="For example who you spoke to, or what they said."
          value={note}
          onChange={(e) => setNote(e.target.value)}
          error={errors.note}
          rows={3}
        />
        <div className="flex justify-end">
          <Button type="submit" variant="secondary" loading={mutation.isPending}>
            Add to timeline
          </Button>
        </div>
      </form>
    </Section>
  )
}

function CloseDisputeForm({ dispute }: { dispute: Dispute }) {
  const [outcome, setOutcome] = useState<DisputeOutcome | ''>('')
  const [amount, setAmount] = useState('')
  const [note, setNote] = useState('')
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})
  const mutation = useDisputeMutation(
    dispute.id,
    (input: { outcome: DisputeOutcome; outcome_amount_cents: number | null; note: string | null }) =>
      api.resolveDispute(dispute.id, input),
  )
  const errors = mergeErrors(clientErrors, mutation.error)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next: Record<string, string> = {}
    if (!outcome) next.outcome = 'Please choose how the dispute ended.'
    let cents: number | null = null
    if (outcome === 'resolved' && amount.trim() !== '') {
      cents = parseRandToCents(amount)
      if (cents === null) next.outcome_amount_cents = 'Please enter an amount in rand, for example 980.00.'
    }
    setClientErrors(next)
    if (Object.keys(next).length > 0 || !outcome) return
    mutation.mutate({
      outcome,
      outcome_amount_cents: outcome === 'resolved' ? cents : null,
      note: note.trim() || null,
    })
  }

  return (
    <Section id="close" title="Close the dispute" description="When the municipality has made its final decision.">
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        <FormError
          error={mutation.error}
          shownFields={['outcome', 'outcome_amount_cents', 'note']}
          hasClientErrors={Object.keys(clientErrors).length > 0}
        />
        <fieldset aria-describedby={errors.outcome ? 'outcome-error' : undefined}>
          <legend className="mb-2 text-sm font-semibold text-slate-800">How did it end?</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {(
              [
                ['resolved', 'Resolved', 'The municipality corrected the bill or credited my account.'],
                ['rejected', 'Rejected', 'The municipality turned down my dispute.'],
              ] as const
            ).map(([value, label, help]) => (
              <label
                key={value}
                className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 ${
                  outcome === value ? 'border-brand-500 bg-brand-50' : 'border-slate-200'
                }`}
              >
                <input
                  type="radio"
                  name="outcome"
                  value={value}
                  checked={outcome === value}
                  onChange={() => setOutcome(value)}
                  className="mt-1 size-4 accent-brand-700"
                />
                <span>
                  <span className="block font-medium text-slate-900">{label}</span>
                  <span className="block text-sm text-slate-600">{help}</span>
                </span>
              </label>
            ))}
          </div>
          {errors.outcome && (
            <p id="outcome-error" className="mt-1.5 text-sm font-medium text-red-700">
              {errors.outcome}
            </p>
          )}
        </fieldset>
        {outcome === 'resolved' && (
          <MoneyField
            label="Amount recovered"
            optional
            hint="The credit or refund you got, in rand."
            placeholder="0.00"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            error={errors.outcome_amount_cents}
          />
        )}
        <TextAreaField
          label="Note"
          optional
          value={note}
          onChange={(e) => setNote(e.target.value)}
          error={errors.note}
          rows={2}
        />
        <div className="flex justify-end">
          <Button type="submit" loading={mutation.isPending}>
            Close dispute
          </Button>
        </div>
      </form>
    </Section>
  )
}

/** A draft: the letter form and the submit form share the letter being edited. */
function DraftDispute({ dispute, metro }: { dispute: Dispute; metro: Metro | undefined }) {
  const [letter, setLetter] = useState<LetterDraft>({
    subject: dispute.letter_subject,
    body: dispute.letter_body,
  })
  return (
    <>
      <DraftLetter dispute={dispute} letter={letter} onLetterChange={setLetter} />
      <DisputeChannels metro={metro} metroCode={dispute.property.metro} />
      <SubmitForm dispute={dispute} letter={letter} />
    </>
  )
}

function Timeline({ dispute }: { dispute: Dispute }) {
  return (
    <Section id="timeline" title="Timeline">
      {dispute.events.length === 0 ? (
        <p className="text-slate-600">Nothing has happened yet.</p>
      ) : (
        <ol className="relative space-y-5 border-l-2 border-slate-200 pl-5">
          {dispute.events.map((event) => (
            <li key={event.id} className="relative">
              <span
                className="absolute top-1.5 -left-[1.6rem] size-3 rounded-full border-2 border-white bg-brand-600 ring-2 ring-brand-200"
                aria-hidden="true"
              />
              <p className="font-semibold text-slate-900">{DISPUTE_EVENT_LABELS[event.type]}</p>
              <p className="text-sm text-slate-600">
                <time dateTime={event.occurred_at}>{formatDateTime(event.occurred_at)}</time>
              </p>
              {event.note && <p className="mt-1 text-sm whitespace-pre-wrap text-slate-800">{event.note}</p>}
            </li>
          ))}
        </ol>
      )}
    </Section>
  )
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export function DisputeDetailPage() {
  const disputeId = Number(useParams().disputeId)
  const dispute = useQuery({
    queryKey: queryKeys.dispute(disputeId),
    queryFn: () => api.getDispute(disputeId),
  })
  const metro = useMetro(dispute.data?.property.metro)

  if (dispute.isPending) return <LoadingState label="Loading your dispute…" />
  if (dispute.isError) {
    return (
      <>
        <PageHeader title="Dispute" crumbs={[{ label: 'Disputes', to: '/disputes' }, { label: 'Dispute' }]} />
        <ErrorState error={dispute.error} onRetry={() => dispute.refetch()} />
      </>
    )
  }

  const d = dispute.data
  const billLabel = d.bill.bill_date ? `${formatDate(d.bill.bill_date)} bill` : 'bill'
  const active = isActiveDispute(d.status)

  return (
    <>
      <PageHeader
        title={`Dispute: ${d.property.nickname}`}
        description={`About the ${billLabel}.`}
        crumbs={[
          { label: 'Disputes', to: '/disputes' },
          { label: `${d.property.nickname}, ${billLabel}` },
        ]}
        badges={<DisputeStatusBadge status={d.status} />}
        actions={
          <ButtonLink to={`/bills/${d.bill_id}`} variant="secondary">
            View the bill
          </ButtonLink>
        }
      />
      <div className="space-y-6">
        {d.status === 'draft' && (
          <Alert tone="info" title="Next: check and send your letter">
            Read the letter below and make any changes. Then copy it and send it to the municipality using one of
            the channels listed. When you've sent it, mark it as submitted.
          </Alert>
        )}
        {d.status === 'resolved' && (
          <Alert tone="success" title="This dispute is resolved">
            {d.outcome_amount_cents
              ? `You recorded ${formatRand(d.outcome_amount_cents)} recovered. Well done for following it up.`
              : 'Well done for following it up.'}
          </Alert>
        )}
        {d.status === 'rejected' && (
          <Alert tone="warning" title="The municipality rejected this dispute">
            If you still believe the bill is wrong, you can ask your ward councillor or the municipal ombudsman for
            help.
          </Alert>
        )}

        <DisputeFacts dispute={d} metro={metro} />

        {d.status === 'draft' ? (
          <DraftDispute key={d.id} dispute={d} metro={metro} />
        ) : (
          <>
            {active && d.next_step && <EscalatePanel dispute={d} />}
            {active && <LogEventForm dispute={d} />}
            {active && <CloseDisputeForm dispute={d} />}
            <SentLetter dispute={d} />
            {active && <DisputeChannels metro={metro} metroCode={d.property.metro} />}
            {!active && <LogEventForm dispute={d} />}
          </>
        )}

        <Timeline dispute={d} />
      </div>
    </>
  )
}
