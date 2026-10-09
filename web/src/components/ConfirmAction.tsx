import { useId, useState, type ReactNode } from 'react'
import { Button } from './Button'

interface ConfirmActionProps {
  /** Label of the button that starts the action. */
  label: string
  /** What will happen, shown before the person confirms. */
  message: ReactNode
  confirmLabel: string
  onConfirm: () => void
  pending?: boolean
  variant?: 'danger' | 'secondary'
  size?: 'sm' | 'md'
}

/** A button that asks "are you sure?" inline before doing something that can't be undone. */
export function ConfirmAction({
  label,
  message,
  confirmLabel,
  onConfirm,
  pending = false,
  variant = 'danger',
  size = 'md',
}: ConfirmActionProps) {
  const [asking, setAsking] = useState(false)
  const id = useId()

  if (!asking) {
    return (
      <Button variant={variant === 'danger' ? 'secondary' : variant} size={size} onClick={() => setAsking(true)}>
        {label}
      </Button>
    )
  }

  return (
    <div
      role="group"
      aria-labelledby={id}
      className="w-full rounded-xl border border-red-200 bg-red-50 p-4 text-red-950"
    >
      <p id={id} className="text-sm leading-relaxed">
        {message}
      </p>
      <div className="mt-3 flex flex-wrap gap-2">
        <Button variant="danger" size={size} loading={pending} onClick={onConfirm} autoFocus>
          {confirmLabel}
        </Button>
        <Button variant="secondary" size={size} onClick={() => setAsking(false)} disabled={pending}>
          Cancel
        </Button>
      </div>
    </div>
  )
}
