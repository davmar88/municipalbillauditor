import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { api, type PropertySummary, type UpcomingDeadline } from '../api'
import { useAuth } from '../auth/context'
import { Badge, OverdueBadge } from '../components/Badge'
import { ButtonLink } from '../components/Button'
import { EmptyState, ErrorState, LoadingState } from '../components/Feedback'
import { PageHeader, Section } from '../components/Page'
import { useMetroName } from '../hooks/useMetros'
import { useToday } from '../hooks/useToday'
import { daysUntil, describeDue, formatDate, formatRand } from '../lib/format'
import { DEADLINE_TYPE_LABELS, PROPERTY_TYPE_LABELS } from '../lib/labels'
import { queryKeys } from '../lib/queryKeys'

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="min-w-0 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-sm sm:p-5">
      <dt className="text-sm font-medium text-slate-600">{label}</dt>
      <dd className="mt-1 text-xl font-bold tracking-tight break-words text-slate-900 tabular-nums sm:text-3xl">{value}</dd>
      {hint && <dd className="mt-1 text-xs text-slate-500">{hint}</dd>}
    </div>
  )
}

function DeadlineItem({ deadline, today }: { deadline: UpcomingDeadline; today: Date }) {
  const days = daysUntil(deadline.due_on, today)
  const overdue = days !== null && days < 0
  const to =
    deadline.type === 'escalate' && deadline.dispute_id !== null
      ? `/disputes/${deadline.dispute_id}`
      : `/bills/${deadline.bill_id}`
  return (
    <li>
      <Link
        to={to}
        className={`flex flex-col gap-2 rounded-xl border p-4 transition-colors hover:border-brand-300 hover:bg-brand-50/40 sm:flex-row sm:items-center sm:justify-between ${
          overdue ? 'border-red-300 bg-red-50/60' : 'border-slate-200 bg-white'
        }`}
      >
        <div className="min-w-0">
          <p className="font-semibold text-slate-900">{deadline.label}</p>
          <p className="text-sm text-slate-600">
            {DEADLINE_TYPE_LABELS[deadline.type]} · {deadline.property_nickname}
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-end">
          <span className={`text-sm font-semibold ${overdue ? 'text-red-800' : 'text-slate-900'}`}>
            {formatDate(deadline.due_on)}
          </span>
          {overdue ? (
            <span className="flex items-center gap-2">
              <OverdueBadge />
              <span className="text-xs text-red-800">{describeDue(deadline.due_on, today)}</span>
            </span>
          ) : (
            <span className="text-xs text-slate-600">{describeDue(deadline.due_on, today)}</span>
          )}
        </div>
      </Link>
    </li>
  )
}

function PropertyItem({ property }: { property: PropertySummary }) {
  const metroName = useMetroName(property.metro)
  return (
    <li>
      <Link
        to={`/properties/${property.id}`}
        className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 transition-colors hover:border-brand-300 hover:bg-brand-50/40 sm:flex-row sm:items-center sm:justify-between"
      >
        <div className="min-w-0">
          <p className="font-semibold text-slate-900">{property.nickname}</p>
          <p className="text-sm break-words text-slate-600">
            {metroName} · Account {property.account_number_masked}
          </p>
          <p className="text-sm text-slate-500">{PROPERTY_TYPE_LABELS[property.property_type]}</p>
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2">
          <Badge tone="neutral">
            {property.bills_count} {property.bills_count === 1 ? 'bill' : 'bills'}
          </Badge>
          {property.open_findings_count > 0 ? (
            <Badge tone="warning">
              {property.open_findings_count} open {property.open_findings_count === 1 ? 'finding' : 'findings'}
            </Badge>
          ) : (
            <Badge tone="success">No open findings</Badge>
          )}
        </div>
      </Link>
    </li>
  )
}

export function DashboardPage() {
  const { user } = useAuth()
  const today = useToday()
  const dashboard = useQuery({ queryKey: queryKeys.dashboard, queryFn: api.getDashboard })
  const properties = useQuery({ queryKey: queryKeys.properties, queryFn: api.listProperties })

  const firstName = user?.name.split(' ')[0]

  return (
    <>
      <PageHeader
        title={firstName ? `Hello, ${firstName}` : 'Overview'}
        documentTitle="Overview"
        description="Here's what we found on your municipal bills, and what needs your attention."
        actions={<ButtonLink to="/properties/new">Add a property</ButtonLink>}
      />

      <div className="space-y-6">
        {dashboard.isPending ? (
          <LoadingState label="Loading your overview…" />
        ) : dashboard.isError ? (
          <ErrorState error={dashboard.error} onRetry={() => dashboard.refetch()} />
        ) : (
          <>
            <section aria-labelledby="summary-heading">
              <h2 id="summary-heading" className="sr-only">
                Summary
              </h2>
              <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat
                  label="Possible overcharge"
                  value={formatRand(dashboard.data.potential_overcharge_cents)}
                  hint="From open findings we're more confident about"
                />
                <Stat label="Recovered so far" value={formatRand(dashboard.data.recovered_cents)} />
                <Stat label="Open findings" value={String(dashboard.data.open_findings_count)} />
                <Stat label="Active disputes" value={String(dashboard.data.active_disputes_count)} />
              </dl>
            </section>

            <Section
              id="deadlines"
              title="Upcoming deadlines"
              description="Municipalities set time limits for disputes. Acting before these dates keeps your options open."
            >
              {dashboard.data.upcoming_deadlines.length === 0 ? (
                <p className="text-slate-600">Nothing due right now. We'll list deadlines here when there are any.</p>
              ) : (
                <ul className="space-y-3">
                  {dashboard.data.upcoming_deadlines.map((deadline) => (
                    <DeadlineItem
                      key={`${deadline.type}-${deadline.bill_id}-${deadline.dispute_id ?? 'none'}`}
                      deadline={deadline}
                      today={today}
                    />
                  ))}
                </ul>
              )}
            </Section>
          </>
        )}

        <Section id="properties" title="Your properties">
          {properties.isPending ? (
            <LoadingState label="Loading your properties…" />
          ) : properties.isError ? (
            <ErrorState error={properties.error} onRetry={() => properties.refetch()} />
          ) : properties.data.length === 0 ? (
            <EmptyState
              title="Add your first property to get started"
              action={<ButtonLink to="/properties/new">Add a property</ButtonLink>}
            >
              <ol className="mx-auto mt-3 max-w-md list-decimal space-y-1 pl-5 text-left">
                <li>Add the property and its municipal account number.</li>
                <li>Upload or type in a recent municipal bill.</li>
                <li>We'll point out anything that looks like a possible mistake, and help you dispute it.</li>
              </ol>
            </EmptyState>
          ) : (
            <ul className="space-y-3">
              {properties.data.map((property) => (
                <PropertyItem key={property.id} property={property} />
              ))}
            </ul>
          )}
        </Section>
      </div>
    </>
  )
}
