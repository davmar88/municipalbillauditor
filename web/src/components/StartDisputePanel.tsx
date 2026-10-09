import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { api, type Bill, type Finding } from '../api'
import { formatRand } from '../lib/format'
import { fieldErrors } from '../lib/forms'
import { invalidateMoneyData, queryKeys } from '../lib/queryKeys'
import { SeverityBadge } from './Badge'
import { Button } from './Button'
import { FormError } from './Feedback'
import { FieldError } from './Field'

interface StartDisputePanelProps {
  bill: Bill
  openFindings: Finding[]
  onCancel: () => void
}

export function StartDisputePanel({ bill, openFindings, onCancel }: StartDisputePanelProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<Set<number>>(
    () => new Set(openFindings.filter((f) => f.severity !== 'low').map((f) => f.id)),
  )
  const [clientError, setClientError] = useState<string | undefined>()

  const mutation = useMutation({
    mutationFn: (findingIds: number[]) => api.createDispute(bill.id, { finding_ids: findingIds }),
    onSuccess: async (dispute) => {
      queryClient.setQueryData(queryKeys.dispute(dispute.id), dispute)
      navigate(`/disputes/${dispute.id}`)
      await invalidateMoneyData(queryClient)
    },
  })

  const serverErrors = fieldErrors(mutation.error)
  const selectionError =
    clientError ??
    serverErrors.finding_ids ??
    Object.entries(serverErrors).find(([key]) => key.startsWith('finding_ids.'))?.[1]

  // Move keyboard and screen-reader users to the panel, and bring it into view:
  // it opens below the findings, which can be well off screen.
  const formRef = useRef<HTMLFormElement>(null)
  const headingRef = useRef<HTMLHeadingElement>(null)
  useEffect(() => {
    headingRef.current?.focus({ preventScroll: true })
    formRef.current?.scrollIntoView?.({ block: 'start' })
  }, [])

  const selectedFindings = openFindings.filter((f) => selected.has(f.id))
  const withAmounts = selectedFindings.filter((f) => (f.estimated_overcharge_cents ?? 0) > 0).length
  // Several findings can be about the same charge, and the server counts each
  // charge once. Rather than add amounts up here (and show more than the
  // dispute will), show the server's own total when the selection is exactly
  // the findings it covers: the open medium and high ones.
  const counted = openFindings.filter((f) => f.severity !== 'low')
  const selectionIsSummary =
    selectedFindings.length === counted.length &&
    counted.every((f) => selected.has(f.id)) &&
    bill.findings_summary.open_count === counted.length
  const serverTotal = selectionIsSummary ? bill.findings_summary.potential_overcharge_cents : null

  function toggle(id: number, checked: boolean) {
    setClientError(undefined)
    setSelected((prev) => {
      const next = new Set(prev)
      if (checked) next.add(id)
      else next.delete(id)
      return next
    })
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (selectedFindings.length === 0) {
      setClientError('Choose at least one finding to include in your dispute.')
      return
    }
    mutation.mutate(selectedFindings.map((f) => f.id))
  }

  return (
    <form
      ref={formRef}
      onSubmit={handleSubmit}
      noValidate
      aria-labelledby="start-dispute-heading"
      className="rounded-2xl border-2 border-brand-200 bg-brand-50/50 p-4 sm:p-6"
    >
      <h2 id="start-dispute-heading" ref={headingRef} tabIndex={-1} className="text-lg font-semibold text-slate-900">
        Start a dispute
      </h2>
      <p className="mt-1 text-sm text-slate-700">
        Choose the findings to include. We'll prepare a letter to the municipality that you can read and change
        before you send it. Nothing is sent until you say so.
      </p>
      {mutation.isError && !selectionError && (
        <div className="mt-4">
          <FormError error={mutation.error} shownFields={['finding_ids']} />
        </div>
      )}
      <fieldset className="mt-4" aria-describedby={selectionError ? 'dispute-selection-error' : undefined}>
        <legend className="sr-only">Findings to include</legend>
        <ul className="space-y-2">
          {openFindings.map((finding) => (
            <li key={finding.id}>
              <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 hover:border-brand-300">
                <input
                  type="checkbox"
                  className="mt-1 size-5 shrink-0 accent-brand-700"
                  checked={selected.has(finding.id)}
                  onChange={(e) => toggle(finding.id, e.target.checked)}
                />
                <span className="min-w-0 flex-1">
                  <span className="block font-medium text-slate-900">{finding.title}</span>
                  <span className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                    <SeverityBadge severity={finding.severity} />
                    {finding.estimated_overcharge_cents !== null && (
                      <span>Possible overcharge {formatRand(finding.estimated_overcharge_cents)}</span>
                    )}
                  </span>
                </span>
              </label>
            </li>
          ))}
        </ul>
        <FieldError id="dispute-selection-error" message={selectionError} />
      </fieldset>
      <div className="mt-4 space-y-1 text-sm" aria-live="polite">
        <p className="text-slate-700">
          {selectedFindings.length} selected
          {serverTotal !== null && serverTotal > 0 && (
            <>
              {' '}
              · possible overcharge <span className="font-semibold tabular-nums">{formatRand(serverTotal)}</span>
            </>
          )}
        </p>
        {withAmounts > 0 && (serverTotal === null || withAmounts > 1) && (
          <p className="text-slate-600">
            {[
              serverTotal === null ? "We'll work out the amount to dispute when we prepare your letter." : null,
              withAmounts > 1 ? 'If two findings are about the same charge, we count that charge only once.' : null,
            ]
              .filter(Boolean)
              .join(' ')}
          </p>
        )}
      </div>
      <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button variant="secondary" onClick={onCancel} disabled={mutation.isPending}>
          Cancel
        </Button>
        <Button type="submit" loading={mutation.isPending}>
          Create dispute letter
        </Button>
      </div>
    </form>
  )
}
