import { useEffect, type ReactNode } from 'react'
import { Link } from 'react-router'

export interface Crumb {
  label: string
  to?: string
}

interface PageHeaderProps {
  title: ReactNode
  /** Plain text for the browser tab. */
  documentTitle?: string
  description?: ReactNode
  crumbs?: Crumb[]
  actions?: ReactNode
  badges?: ReactNode
}

export function PageHeader({ title, documentTitle, description, crumbs, actions, badges }: PageHeaderProps) {
  useEffect(() => {
    const text = documentTitle ?? (typeof title === 'string' ? title : undefined)
    if (text) document.title = `${text} · Bill Auditor`
  }, [documentTitle, title])

  return (
    <div className="mb-6 sm:mb-8">
      {crumbs && crumbs.length > 0 && (
        <nav aria-label="Breadcrumb" className="mb-3">
          <ol className="flex flex-wrap items-center gap-1.5 text-sm text-slate-600">
            {crumbs.map((crumb, index) => (
              <li key={index} className="flex items-center gap-1.5">
                {index > 0 && <span aria-hidden="true">/</span>}
                {crumb.to ? (
                  <Link to={crumb.to} className="rounded font-medium text-brand-700 underline-offset-2 hover:underline">
                    {crumb.label}
                  </Link>
                ) : (
                  <span aria-current="page">{crumb.label}</span>
                )}
              </li>
            ))}
          </ol>
        </nav>
      )}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{title}</h1>
          {badges && <div className="mt-2 flex flex-wrap gap-2">{badges}</div>}
          {description && <div className="mt-2 max-w-prose text-slate-600">{description}</div>}
        </div>
        {actions && <div className="flex shrink-0 flex-wrap gap-2">{actions}</div>}
      </div>
    </div>
  )
}

interface SectionProps {
  title: ReactNode
  description?: ReactNode
  actions?: ReactNode
  children: ReactNode
  className?: string
  /** id for the heading so the section can be labelled. */
  id?: string
}

/** A titled white card. */
export function Section({ title, description, actions, children, className = '', id }: SectionProps) {
  const headingId = id ? `${id}-heading` : undefined
  return (
    <section
      aria-labelledby={headingId}
      className={`rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 ${className}`}
    >
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h2 id={headingId} className="text-lg font-semibold text-slate-900">
            {title}
          </h2>
          {description && <div className="mt-1 text-sm text-slate-600">{description}</div>}
        </div>
        {actions && <div className="flex shrink-0 flex-wrap gap-2">{actions}</div>}
      </div>
      {children}
    </section>
  )
}

/** A label/value pair for definition lists. */
export function Detail({ label, children }: { label: ReactNode; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-sm text-slate-600">{label}</dt>
      <dd className="mt-0.5 font-medium break-words text-slate-900">{children}</dd>
    </div>
  )
}
