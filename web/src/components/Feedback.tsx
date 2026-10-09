import type { ReactNode } from 'react'
import { errorMessage, isApiError, isValidationError } from '../api'
import { Button } from './Button'
import { Spinner } from './Spinner'

type Tone = 'info' | 'success' | 'warning' | 'error'

const toneClasses: Record<Tone, string> = {
  info: 'border-sky-200 bg-sky-50 text-sky-900',
  success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
  warning: 'border-amber-300 bg-amber-50 text-amber-900',
  error: 'border-red-200 bg-red-50 text-red-900',
}

const icons: Record<Tone, ReactNode> = {
  info: <path d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11a.75.75 0 1 1 1.5 0 .75.75 0 0 1-1.5 0ZM9 9.25a.75.75 0 0 1 .75-.75h.5a.75.75 0 0 1 .75.75v4a.75.75 0 0 1-1.5 0V10h-.25A.75.75 0 0 1 9 9.25Z" />,
  success: <path d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.86-9.86a.75.75 0 0 0-1.22-.88l-3.24 4.5-1.6-1.6a.75.75 0 1 0-1.06 1.06l2.22 2.22a.75.75 0 0 0 1.14-.09l3.76-5.21Z" />,
  warning: <path d="M8.48 2.95a1.75 1.75 0 0 1 3.04 0l6.26 11.1A1.75 1.75 0 0 1 16.26 16.7H3.74a1.75 1.75 0 0 1-1.52-2.65l6.26-11.1ZM10 6.5a.75.75 0 0 0-.75.75v3.5a.75.75 0 0 0 1.5 0v-3.5A.75.75 0 0 0 10 6.5Zm0 7.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" />,
  error: <path d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM9 6a1 1 0 1 1 2 0v4a1 1 0 1 1-2 0V6Zm1 9a1.25 1.25 0 1 0 0-2.5A1.25 1.25 0 0 0 10 15Z" />,
}

interface AlertProps {
  tone?: Tone
  title?: ReactNode
  children?: ReactNode
  /** Use role="alert" so screen readers announce it immediately. */
  urgent?: boolean
  className?: string
  action?: ReactNode
}

export function Alert({ tone = 'info', title, children, urgent, className = '', action }: AlertProps) {
  return (
    <div
      role={urgent ? 'alert' : tone === 'error' ? 'alert' : 'status'}
      className={`flex gap-3 rounded-xl border p-4 ${toneClasses[tone]} ${className}`}
    >
      <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="mt-0.5 size-5 shrink-0">
        {icons[tone]}
      </svg>
      <div className="min-w-0 flex-1 space-y-1">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className="text-sm leading-relaxed">{children}</div>}
        {action && <div className="pt-2">{action}</div>}
      </div>
    </div>
  )
}

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return (
    <div role="status" className="flex items-center justify-center gap-3 py-16 text-slate-600">
      <Spinner className="size-6 text-brand-700" />
      <span>{label}</span>
    </div>
  )
}

interface ErrorStateProps {
  error: unknown
  onRetry?: () => void
  title?: string
}

export function ErrorState({ error, onRetry, title }: ErrorStateProps) {
  const notFound = isApiError(error) && error.status === 404
  return (
    <Alert
      tone="error"
      title={title ?? (notFound ? "We couldn't find that" : "Sorry, that didn't load")}
      action={
        onRetry && !notFound ? (
          <Button variant="secondary" size="sm" onClick={onRetry}>
            Try again
          </Button>
        ) : undefined
      }
    >
      {notFound
        ? 'It may have been deleted, or the link may be wrong.'
        : errorMessage(error)}
    </Alert>
  )
}

interface EmptyStateProps {
  title: string
  children?: ReactNode
  action?: ReactNode
}

export function EmptyState({ title, children, action }: EmptyStateProps) {
  return (
    <div className="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-10 text-center">
      <p className="text-lg font-semibold text-slate-900">{title}</p>
      {children && <div className="mx-auto mt-2 max-w-prose text-slate-600">{children}</div>}
      {action && <div className="mt-5 flex justify-center">{action}</div>}
    </div>
  )
}

/**
 * Summary shown above a form after a failed submit. For 422s it lists only
 * the messages that aren't already shown next to a visible field.
 */
export function FormError({
  error,
  shownFields = [],
  hasClientErrors = false,
}: {
  error: unknown
  shownFields?: readonly string[]
  /** The form found problems before sending; they are shown next to the fields. */
  hasClientErrors?: boolean
}) {
  if (hasClientErrors) {
    return (
      <Alert tone="error" title="Please check the details below">
        Some of the fields need your attention.
      </Alert>
    )
  }
  if (!error) return null
  if (isValidationError(error)) {
    const extra = Object.entries(error.errors)
      .filter(([field]) => !shownFields.includes(field))
      .flatMap(([, messages]) => messages)
    return (
      <Alert tone="error" title="Please check the details below">
        {extra.length > 0 ? (
          <ul className="list-disc space-y-1 pl-5">
            {extra.map((message, index) => (
              <li key={index}>{message}</li>
            ))}
          </ul>
        ) : (
          'Some of the fields need your attention.'
        )}
      </Alert>
    )
  }
  return <Alert tone="error" title="That didn't work">{errorMessage(error)}</Alert>
}
