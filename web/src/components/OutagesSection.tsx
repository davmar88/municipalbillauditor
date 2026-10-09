import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { api, OUTAGE_SERVICES, type Outage, type OutageService } from '../api'
import { formatDateTime, localDateTimeToIso } from '../lib/format'
import { mergeErrors } from '../lib/forms'
import { SERVICE_LABELS } from '../lib/labels'
import { invalidateMoneyData, queryKeys } from '../lib/queryKeys'
import { Badge } from './Badge'
import { Button } from './Button'
import { Alert, ErrorState, FormError, LoadingState } from './Feedback'
import { SelectField, TextAreaField, TextField } from './Field'
import { Section } from './Page'

const FIELDS = ['service', 'starts_at', 'ends_at', 'notes'] as const

interface AddOutageFormProps {
  propertyId: number
  onSaved: () => void
  onCancel: () => void
}

function AddOutageForm({ propertyId, onSaved, onCancel }: AddOutageFormProps) {
  const queryClient = useQueryClient()
  const [service, setService] = useState<OutageService>('water')
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [notes, setNotes] = useState('')
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})

  const mutation = useMutation({
    mutationFn: (input: Parameters<typeof api.createOutage>[1]) => api.createOutage(propertyId, input),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.outages(propertyId) }),
        invalidateMoneyData(queryClient),
      ])
      onSaved()
    },
  })
  const errors = mergeErrors(clientErrors, mutation.error)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next: Record<string, string> = {}
    const startIso = localDateTimeToIso(startsAt)
    const endIso = localDateTimeToIso(endsAt)
    if (!startIso) next.starts_at = 'Please enter when the supply went off.'
    if (!endIso) next.ends_at = 'Please enter when the supply came back.'
    if (startIso && endIso && endIso <= startIso) {
      next.ends_at = 'This needs to be after the start.'
    }
    setClientErrors(next)
    if (Object.keys(next).length > 0 || !startIso || !endIso) return
    mutation.mutate({
      service,
      starts_at: startIso,
      ends_at: endIso,
      notes: notes.trim() || null,
    })
  }

  return (
    <form
      onSubmit={handleSubmit}
      noValidate
      className="mt-4 space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4"
      aria-label="Record an outage"
    >
      <FormError error={mutation.error} shownFields={FIELDS} hasClientErrors={Object.keys(clientErrors).length > 0} />
      <SelectField
        label="What was off?"
        value={service}
        onChange={(e) => setService(e.target.value as OutageService)}
        error={errors.service}
      >
        {OUTAGE_SERVICES.map((s) => (
          <option key={s} value={s}>
            {SERVICE_LABELS[s]}
          </option>
        ))}
      </SelectField>
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label="Went off"
          type="datetime-local"
          value={startsAt}
          onChange={(e) => setStartsAt(e.target.value)}
          error={errors.starts_at}
          required
        />
        <TextField
          label="Came back"
          type="datetime-local"
          value={endsAt}
          onChange={(e) => setEndsAt(e.target.value)}
          error={errors.ends_at}
          required
        />
      </div>
      <TextAreaField
        label="Notes"
        optional
        hint="For example a reference number or a link to the municipality's notice."
        value={notes}
        onChange={(e) => setNotes(e.target.value)}
        error={errors.notes}
        rows={2}
      />
      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button variant="secondary" onClick={onCancel} disabled={mutation.isPending}>
          Cancel
        </Button>
        <Button type="submit" loading={mutation.isPending}>
          Save outage
        </Button>
      </div>
    </form>
  )
}

function OutageRow({ outage, propertyId }: { outage: Outage; propertyId: number }) {
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: () => api.deleteOutage(outage.id),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.outages(propertyId) }),
        invalidateMoneyData(queryClient),
      ]),
  })
  return (
    <li className="flex flex-col gap-3 rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0 space-y-1">
        <Badge tone={outage.service === 'electricity' ? 'warning' : 'info'}>{SERVICE_LABELS[outage.service]}</Badge>
        <p className="text-sm text-slate-900">
          <span className="font-medium">From</span> {formatDateTime(outage.starts_at)}{' '}
          <span className="font-medium">to</span> {formatDateTime(outage.ends_at)}
        </p>
        {outage.notes && <p className="text-sm break-words text-slate-600">{outage.notes}</p>}
        {mutation.isError && <p className="text-sm font-medium text-red-700">We couldn't delete this. Please try again.</p>}
      </div>
      <Button
        variant="secondary"
        size="sm"
        loading={mutation.isPending}
        onClick={() => mutation.mutate()}
        aria-label={`Delete ${SERVICE_LABELS[outage.service].toLowerCase()} outage from ${formatDateTime(outage.starts_at)}`}
      >
        Delete
      </Button>
    </li>
  )
}

export function OutagesSection({ propertyId }: { propertyId: number }) {
  const [adding, setAdding] = useState(false)
  const [saved, setSaved] = useState(false)
  const outages = useQuery({
    queryKey: queryKeys.outages(propertyId),
    queryFn: () => api.listOutages(propertyId),
  })

  return (
    <Section
      id="outages"
      title="Water and electricity outages"
      description="Record times when your water or electricity was off. We'll check whether you were charged for supply you didn't get, and re-check your bills for that period."
      actions={
        !adding ? (
          <Button
            size="sm"
            variant="secondary"
            onClick={() => {
              setSaved(false)
              setAdding(true)
            }}
          >
            Record an outage
          </Button>
        ) : undefined
      }
    >
      {saved && !adding && (
        <Alert tone="success" className="mb-4">
          Outage saved. We've re-checked the bills for that period.
        </Alert>
      )}
      {outages.isPending ? (
        <LoadingState label="Loading outages…" />
      ) : outages.isError ? (
        <ErrorState error={outages.error} onRetry={() => outages.refetch()} />
      ) : outages.data.length === 0 ? (
        !adding && <p className="text-slate-600">No outages recorded.</p>
      ) : (
        <ul className="space-y-3">
          {outages.data.map((outage) => (
            <OutageRow key={outage.id} outage={outage} propertyId={propertyId} />
          ))}
        </ul>
      )}
      {adding && (
        <AddOutageForm
          propertyId={propertyId}
          onCancel={() => setAdding(false)}
          onSaved={() => {
            setAdding(false)
            setSaved(true)
          }}
        />
      )}
    </Section>
  )
}
