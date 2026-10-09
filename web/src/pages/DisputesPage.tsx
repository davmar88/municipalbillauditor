import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { api, type DisputeSummary } from '../api'
import { DisputeStatusBadge, OverdueBadge } from '../components/Badge'
import { EmptyState, ErrorState, LoadingState } from '../components/Feedback'
import { PageHeader } from '../components/Page'
import { useToday } from '../hooks/useToday'
import { daysUntil, formatDate, formatRand } from '../lib/format'
import { isActiveDispute } from '../lib/labels'
import { queryKeys } from '../lib/queryKeys'

function DisputeRow({ dispute, today }: { dispute: DisputeSummary; today: Date }) {
  const days = dispute.response_due_at ? daysUntil(dispute.response_due_at, today) : null
  const overdue = isActiveDispute(dispute.status) && days !== null && days < 0
  return (
    <li>
      <Link
        to={`/disputes/${dispute.id}`}
        className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-colors hover:border-brand-300 hover:bg-brand-50/40 sm:grid-cols-[1fr_auto] sm:items-center"
      >
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <p className="font-semibold text-slate-900">{dispute.property.nickname}</p>
            <DisputeStatusBadge status={dispute.status} />
          </div>
          <p className="text-sm text-slate-600">
            {dispute.bill.bill_date ? `Bill dated ${formatDate(dispute.bill.bill_date)}` : 'Bill date not known'}
          </p>
        </div>
        <dl className="grid grid-cols-2 gap-3 text-sm sm:flex sm:gap-8 sm:text-right">
          <div>
            <dt className="text-slate-600">Amount disputed</dt>
            <dd className="font-semibold text-slate-900 tabular-nums">{formatRand(dispute.amount_disputed_cents)}</dd>
          </div>
          <div>
            <dt className="text-slate-600">Response due</dt>
            <dd className={`flex flex-wrap items-center gap-2 font-semibold sm:justify-end ${overdue ? 'text-red-800' : 'text-slate-900'}`}>
              {dispute.response_due_at ? formatDate(dispute.response_due_at) : dispute.status === 'draft' ? 'Not sent yet' : '—'}
              {overdue && <OverdueBadge />}
            </dd>
          </div>
        </dl>
      </Link>
    </li>
  )
}

export function DisputesPage() {
  const today = useToday()
  const disputes = useQuery({ queryKey: queryKeys.disputes, queryFn: api.listDisputes })

  return (
    <>
      <PageHeader
        title="Disputes"
        description="Every dispute you've started, newest first. Open one to send it, log a reply or escalate it."
      />
      {disputes.isPending ? (
        <LoadingState label="Loading your disputes…" />
      ) : disputes.isError ? (
        <ErrorState error={disputes.error} onRetry={() => disputes.refetch()} />
      ) : disputes.data.length === 0 ? (
        <EmptyState title="No disputes yet">
          When we find a possible problem on one of your bills, you can start a dispute from that bill. We'll draft
          the letter for you.
        </EmptyState>
      ) : (
        <ul className="space-y-3">
          {disputes.data.map((dispute) => (
            <DisputeRow key={dispute.id} dispute={dispute} today={today} />
          ))}
        </ul>
      )}
    </>
  )
}
