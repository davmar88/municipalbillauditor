import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router'
import { api, errorMessage, type Bill, type Finding } from '../api'
import { formatPercent, formatRand } from '../lib/format'
import { FINDING_RULE_LABELS } from '../lib/labels'
import { invalidateMoneyData, queryKeys } from '../lib/queryKeys'
import { FindingStatusBadge, SeverityBadge } from './Badge'
import { Button } from './Button'

interface FindingCardProps {
  finding: Finding
  /** The dispute this finding is part of, when known. */
  disputeId?: number
}

export function FindingCard({ finding, disputeId }: FindingCardProps) {
  const queryClient = useQueryClient()
  const low = finding.severity === 'low'
  const headingId = `finding-${finding.id}-title`

  const mutation = useMutation({
    mutationFn: (status: 'open' | 'dismissed') => api.updateFinding(finding.id, { status }),
    onSuccess: async (updated) => {
      queryClient.setQueryData<Bill>(queryKeys.bill(updated.bill_id), (bill) =>
        bill
          ? { ...bill, findings: bill.findings.map((f) => (f.id === updated.id ? updated : f)) }
          : bill,
      )
      await invalidateMoneyData(queryClient)
    },
  })

  const border =
    finding.status !== 'open'
      ? 'border-slate-200 bg-slate-50'
      : low
        ? 'border-dashed border-slate-300 bg-slate-50'
        : finding.severity === 'high'
          ? 'border-red-200 border-l-4 border-l-red-600 bg-white'
          : 'border-amber-200 border-l-4 border-l-amber-500 bg-white'

  return (
    <article aria-labelledby={headingId} className={`rounded-xl border p-4 sm:p-5 ${border}`}>
      <div className="flex flex-wrap items-center gap-2">
        <SeverityBadge severity={finding.severity} />
        <span className="text-xs font-medium tracking-wide text-slate-600 uppercase">
          {FINDING_RULE_LABELS[finding.rule]}
        </span>
        <span className="ml-auto">
          <FindingStatusBadge status={finding.status} />
        </span>
      </div>
      <h3
        id={headingId}
        className={`mt-2 font-semibold ${low ? 'text-base text-slate-700' : 'text-lg text-slate-900'} ${
          finding.status === 'dismissed' ? 'line-through decoration-slate-400' : ''
        }`}
      >
        {finding.title}
      </h3>
      <p className={`mt-1 leading-relaxed ${low ? 'text-sm text-slate-600' : 'text-slate-700'}`}>
        {finding.explanation}
      </p>
      <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
        <div>
          <dt className="inline text-slate-600">How sure we are: </dt>
          <dd className="inline font-semibold text-slate-900">{formatPercent(finding.confidence)}</dd>
        </div>
        {finding.estimated_overcharge_cents !== null && (
          <div>
            <dt className="inline text-slate-600">Possible overcharge: </dt>
            <dd className="inline font-semibold text-slate-900 tabular-nums">
              {formatRand(finding.estimated_overcharge_cents)}
            </dd>
          </div>
        )}
      </dl>
      {mutation.isError && (
        <p role="alert" className="mt-3 text-sm font-medium text-red-700">
          {errorMessage(mutation.error)}
        </p>
      )}
      <div className="mt-4 flex flex-wrap items-center gap-2">
        {finding.status === 'open' && (
          <Button
            variant="secondary"
            size="sm"
            loading={mutation.isPending}
            onClick={() => mutation.mutate('dismissed')}
            aria-label={`Dismiss: ${finding.title}`}
          >
            Dismiss
          </Button>
        )}
        {finding.status === 'dismissed' && (
          <Button
            variant="secondary"
            size="sm"
            loading={mutation.isPending}
            onClick={() => mutation.mutate('open')}
            aria-label={`Reopen: ${finding.title}`}
          >
            Reopen
          </Button>
        )}
        {finding.status === 'disputed' &&
          (disputeId !== undefined ? (
            <Link
              to={`/disputes/${disputeId}`}
              className="text-sm font-semibold text-brand-700 underline underline-offset-2"
            >
              View the dispute
            </Link>
          ) : (
            <span className="text-sm text-slate-600">Included in a dispute.</span>
          ))}
        {finding.status === 'dismissed' && (
          <span className="text-sm text-slate-600">You dismissed this. It won't count towards your totals.</span>
        )}
      </div>
    </article>
  )
}
