import type { ReactNode } from 'react'
import type { BillStatus, DisputeStatus, FindingStatus, Severity } from '../api'
import {
  BILL_STATUS_LABELS,
  DISPUTE_STATUS_LABELS,
  FINDING_STATUS_LABELS,
  SEVERITY_LABELS,
} from '../lib/labels'

export type BadgeTone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info'

const tones: Record<BadgeTone, string> = {
  neutral: 'bg-slate-100 text-slate-700 ring-slate-300',
  brand: 'bg-brand-50 text-brand-800 ring-brand-200',
  success: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
  warning: 'bg-amber-50 text-amber-900 ring-amber-300',
  danger: 'bg-red-50 text-red-800 ring-red-200',
  info: 'bg-sky-50 text-sky-800 ring-sky-200',
}

export function Badge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
  return (
    <span
      className={`inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ${tones[tone]}`}
    >
      {children}
    </span>
  )
}

const severityTone: Record<Severity, BadgeTone> = {
  high: 'danger',
  medium: 'warning',
  low: 'neutral',
}

export function SeverityBadge({ severity }: { severity: Severity }) {
  return (
    <Badge tone={severityTone[severity]}>
      {severity === 'low' ? SEVERITY_LABELS.low : `${SEVERITY_LABELS[severity]} concern`}
    </Badge>
  )
}

const billTone: Record<BillStatus, BadgeTone> = {
  needs_review: 'warning',
  extracting: 'info',
  extraction_failed: 'danger',
  audited: 'success',
}

export function BillStatusBadge({ status }: { status: BillStatus }) {
  return <Badge tone={billTone[status]}>{BILL_STATUS_LABELS[status]}</Badge>
}

const disputeTone: Record<DisputeStatus, BadgeTone> = {
  draft: 'neutral',
  submitted: 'info',
  acknowledged: 'info',
  escalated: 'warning',
  resolved: 'success',
  rejected: 'danger',
}

export function DisputeStatusBadge({ status }: { status: DisputeStatus }) {
  return <Badge tone={disputeTone[status]}>{DISPUTE_STATUS_LABELS[status]}</Badge>
}

const findingTone: Record<FindingStatus, BadgeTone> = {
  open: 'brand',
  dismissed: 'neutral',
  disputed: 'info',
}

export function FindingStatusBadge({ status }: { status: FindingStatus }) {
  return <Badge tone={findingTone[status]}>{FINDING_STATUS_LABELS[status]}</Badge>
}

export function OverdueBadge() {
  return <Badge tone="danger">Overdue</Badge>
}
