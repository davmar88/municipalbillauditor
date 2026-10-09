import {
  useId,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react'

const controlBase =
  'block w-full rounded-lg border bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm ' +
  'placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus:ring-3 focus:ring-brand-200 ' +
  'disabled:bg-slate-100 disabled:text-slate-500'

function controlClasses(hasError: boolean): string {
  return `${controlBase} ${hasError ? 'border-red-600' : 'border-slate-300'}`
}

interface FieldShellProps {
  id: string
  label: ReactNode
  hint?: ReactNode
  error?: string
  optional?: boolean
  className?: string
  children: ReactNode
}

function describedBy(id: string, hint?: ReactNode, error?: string): string | undefined {
  const ids = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean)
  return ids.length > 0 ? ids.join(' ') : undefined
}

function FieldShell({ id, label, hint, error, optional, className = '', children }: FieldShellProps) {
  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1 block text-sm font-semibold text-slate-800">
        {label}
        {optional && <span className="ml-1 font-normal text-slate-500">(optional)</span>}
      </label>
      {hint && (
        <p id={`${id}-hint`} className="mb-1.5 text-sm text-slate-600">
          {hint}
        </p>
      )}
      {children}
      <FieldError id={`${id}-error`} message={error} />
    </div>
  )
}

export function FieldError({ id, message }: { id?: string; message?: string }) {
  if (!message) return null
  return (
    <p id={id} className="mt-1.5 flex items-start gap-1.5 text-sm font-medium text-red-700">
      <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="mt-0.5 size-4 shrink-0">
        <path
          fillRule="evenodd"
          d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM9 6a1 1 0 1 1 2 0v4a1 1 0 1 1-2 0V6Zm1 9a1.25 1.25 0 1 0 0-2.5A1.25 1.25 0 0 0 10 15Z"
          clipRule="evenodd"
        />
      </svg>
      <span>{message}</span>
    </p>
  )
}

type CommonProps = {
  label: ReactNode
  hint?: ReactNode
  error?: string
  optional?: boolean
  className?: string
}

export function TextField({
  label,
  hint,
  error,
  optional,
  className,
  id: idProp,
  ...rest
}: CommonProps & InputHTMLAttributes<HTMLInputElement>) {
  const autoId = useId()
  const id = idProp ?? autoId
  return (
    <FieldShell id={id} label={label} hint={hint} error={error} optional={optional} className={className}>
      <input
        id={id}
        className={controlClasses(Boolean(error))}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        {...rest}
      />
    </FieldShell>
  )
}

/** A rand amount: text input with an "R" prefix and a decimal keyboard on phones. */
export function MoneyField({
  label,
  hint,
  error,
  optional,
  className,
  id: idProp,
  ...rest
}: CommonProps & Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
  const autoId = useId()
  const id = idProp ?? autoId
  return (
    <FieldShell id={id} label={label} hint={hint} error={error} optional={optional} className={className}>
      <div className="relative">
        <span
          className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500"
          aria-hidden="true"
        >
          R
        </span>
        <input
          id={id}
          type="text"
          inputMode="decimal"
          autoComplete="off"
          className={`${controlClasses(Boolean(error))} pl-7`}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy(id, hint, error)}
          {...rest}
        />
      </div>
    </FieldShell>
  )
}

export function TextAreaField({
  label,
  hint,
  error,
  optional,
  className,
  id: idProp,
  ...rest
}: CommonProps & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const autoId = useId()
  const id = idProp ?? autoId
  return (
    <FieldShell id={id} label={label} hint={hint} error={error} optional={optional} className={className}>
      <textarea
        id={id}
        className={controlClasses(Boolean(error))}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        {...rest}
      />
    </FieldShell>
  )
}

export function SelectField({
  label,
  hint,
  error,
  optional,
  className,
  id: idProp,
  children,
  ...rest
}: CommonProps & SelectHTMLAttributes<HTMLSelectElement>) {
  const autoId = useId()
  const id = idProp ?? autoId
  return (
    <FieldShell id={id} label={label} hint={hint} error={error} optional={optional} className={className}>
      <select
        id={id}
        className={`${controlClasses(Boolean(error))} pr-8`}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        {...rest}
      >
        {children}
      </select>
    </FieldShell>
  )
}

export function CheckboxField({
  label,
  hint,
  error,
  className = '',
  id: idProp,
  extraDescribedBy,
  ...rest
}: Omit<CommonProps, 'optional'> &
  Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'aria-describedby'> & {
    /** id of other text that describes this checkbox (e.g. a notice above it). */
    extraDescribedBy?: string
  }) {
  const autoId = useId()
  const id = idProp ?? autoId
  const describedIds = [extraDescribedBy, describedBy(id, hint, error)].filter(Boolean).join(' ')
  return (
    <div className={className}>
      <div className="flex items-start gap-3">
        <input
          id={id}
          type="checkbox"
          className="mt-0.5 size-5 shrink-0 rounded border-slate-400 accent-brand-700"
          aria-invalid={error ? true : undefined}
          aria-describedby={describedIds || undefined}
          {...rest}
        />
        <div className="min-w-0">
          <label htmlFor={id} className="block font-medium text-slate-900">
            {label}
          </label>
          {hint && (
            <div id={`${id}-hint`} className="mt-1 text-sm text-slate-600">
              {hint}
            </div>
          )}
        </div>
      </div>
      <FieldError id={`${id}-error`} message={error} />
    </div>
  )
}
