import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router'
import { api, type BillSummary } from '../api'
import { Badge, BillStatusBadge } from '../components/Badge'
import { ButtonLink } from '../components/Button'
import { ConfirmAction } from '../components/ConfirmAction'
import { Alert, EmptyState, ErrorState, LoadingState } from '../components/Feedback'
import { OutagesSection } from '../components/OutagesSection'
import { Detail, PageHeader, Section } from '../components/Page'
import { useMetroName } from '../hooks/useMetros'
import { formatDate, formatRand, formatRandOrDash } from '../lib/format'
import { PROPERTY_TYPE_LABELS } from '../lib/labels'
import { queryKeys } from '../lib/queryKeys'

function BillRow({ bill }: { bill: BillSummary }) {
  const summary = bill.findings_summary
  return (
    <li>
      <Link
        to={`/bills/${bill.id}`}
        className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 transition-colors hover:border-brand-300 hover:bg-brand-50/40 sm:grid-cols-[1fr_auto] sm:items-center"
      >
        <div className="min-w-0 space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <p className="font-semibold text-slate-900">
              {bill.bill_date ? `Bill dated ${formatDate(bill.bill_date)}` : 'Bill (date not known yet)'}
            </p>
            <BillStatusBadge status={bill.status} />
          </div>
          <p className="text-sm text-slate-600">
            Current charges: <span className="font-medium text-slate-900 tabular-nums">{formatRandOrDash(bill.total_cents)}</span>
          </p>
        </div>
        <dl className="grid grid-cols-3 gap-3 text-sm sm:flex sm:gap-6 sm:text-right">
          <div>
            <dt className="text-slate-600">Open</dt>
            <dd className="font-semibold text-slate-900 tabular-nums">{summary.open_count}</dd>
          </div>
          <div>
            <dt className="text-slate-600">High</dt>
            <dd className={`font-semibold tabular-nums ${summary.high_count > 0 ? 'text-red-700' : 'text-slate-900'}`}>
              {summary.high_count}
            </dd>
          </div>
          <div>
            <dt className="text-slate-600">Possible overcharge</dt>
            <dd className="font-semibold text-slate-900 tabular-nums">{formatRand(summary.potential_overcharge_cents)}</dd>
          </div>
        </dl>
      </Link>
    </li>
  )
}

export function PropertyDetailPage() {
  const propertyId = Number(useParams().propertyId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const property = useQuery({
    queryKey: queryKeys.property(propertyId),
    queryFn: () => api.getProperty(propertyId),
  })
  const bills = useQuery({
    queryKey: queryKeys.bills(propertyId),
    queryFn: () => api.listBills(propertyId),
  })
  const metroName = useMetroName(property.data?.metro)

  const deleteMutation = useMutation({
    mutationFn: () => api.deleteProperty(propertyId),
    onSuccess: () => {
      navigate('/', { replace: true })
      // Leave this page first so nothing refetches the deleted property.
      queryClient.removeQueries({ queryKey: queryKeys.property(propertyId) })
      void queryClient.invalidateQueries({ queryKey: queryKeys.properties, exact: true })
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
      void queryClient.invalidateQueries({ queryKey: queryKeys.disputes })
    },
  })

  if (property.isPending) return <LoadingState label="Loading the property…" />
  if (property.isError) {
    return (
      <>
        <PageHeader title="Property" crumbs={[{ label: 'Overview', to: '/' }, { label: 'Property' }]} />
        <ErrorState error={property.error} onRetry={() => property.refetch()} />
      </>
    )
  }

  const p = property.data

  return (
    <>
      <PageHeader
        title={p.nickname}
        crumbs={[{ label: 'Overview', to: '/' }, { label: p.nickname }]}
        badges={
          p.open_findings_count > 0 ? (
            <Badge tone="warning">
              {p.open_findings_count} open {p.open_findings_count === 1 ? 'finding' : 'findings'}
            </Badge>
          ) : undefined
        }
        actions={
          <>
            <ButtonLink to={`/properties/${propertyId}/bills/new`}>Add a bill</ButtonLink>
            <ButtonLink to={`/properties/${propertyId}/edit`} variant="secondary">
              Edit property
            </ButtonLink>
          </>
        }
      />

      <div className="space-y-6">
        <Section title="Property details" id="details">
          <dl className="grid gap-4 sm:grid-cols-2">
            <Detail label="Municipality">{metroName}</Detail>
            <Detail label="Account number">{p.account_number}</Detail>
            <Detail label="Address">{p.address}</Detail>
            <Detail label="Type of property">{PROPERTY_TYPE_LABELS[p.property_type]}</Detail>
          </dl>
        </Section>

        <Section
          id="bills"
          title="Bills"
          description="Newest first. Open a bill to see what we found."
          actions={
            <ButtonLink to={`/properties/${propertyId}/bills/new`} size="sm">
              Add a bill
            </ButtonLink>
          }
        >
          {bills.isPending ? (
            <LoadingState label="Loading bills…" />
          ) : bills.isError ? (
            <ErrorState error={bills.error} onRetry={() => bills.refetch()} />
          ) : bills.data.length === 0 ? (
            <EmptyState
              title="No bills yet"
              action={<ButtonLink to={`/properties/${propertyId}/bills/new`}>Add your first bill</ButtonLink>}
            >
              Upload a photo or PDF of a recent municipal bill, or type in its line items. Adding a few months of
              bills helps us compare your usage over time.
            </EmptyState>
          ) : (
            <ul className="space-y-3">
              {bills.data.map((bill) => (
                <BillRow key={bill.id} bill={bill} />
              ))}
            </ul>
          )}
        </Section>

        <OutagesSection propertyId={propertyId} />

        <Section
          id="danger"
          title="Delete this property"
          description="This permanently deletes the property with all its bills, uploaded files, findings, outages and disputes."
        >
          {deleteMutation.isError && (
            <Alert tone="error" className="mb-4">
              We couldn't delete the property. Please try again.
            </Alert>
          )}
          <ConfirmAction
            label="Delete property"
            message={
              <>
                Delete <strong>{p.nickname}</strong> and everything linked to it? This can't be undone.
              </>
            }
            confirmLabel="Yes, delete it"
            pending={deleteMutation.isPending}
            onConfirm={() => deleteMutation.mutate()}
          />
        </Section>
      </div>
    </>
  )
}
