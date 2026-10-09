import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { api, errorMessage, type User } from '../api'
import { useAuth } from '../auth/context'
import { Button } from '../components/Button'
import { AiConsentHint, PopiaNotice } from '../components/ConsentText'
import { Alert, ErrorState, FormError, LoadingState } from '../components/Feedback'
import { CheckboxField, TextField } from '../components/Field'
import { Detail, PageHeader, Section } from '../components/Page'
import { formatDate } from '../lib/format'
import { fieldErrors, mergeErrors } from '../lib/forms'
import { queryKeys } from '../lib/queryKeys'

function ProfileForm({ user }: { user: User }) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(user.name)
  const [saved, setSaved] = useState(false)
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})
  const mutation = useMutation({
    mutationFn: api.updateMe,
    onSuccess: (updated) => {
      queryClient.setQueryData(queryKeys.me, updated)
      setSaved(true)
    },
  })
  const errors = mergeErrors(clientErrors, mutation.error)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setSaved(false)
    if (!name.trim()) {
      setClientErrors({ name: 'Please enter your name.' })
      return
    }
    setClientErrors({})
    mutation.mutate({ name: name.trim() })
  }

  return (
    <Section id="profile" title="Your details">
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        <FormError error={mutation.error} shownFields={['name']} />
        {saved && <Alert tone="success">Your name is updated.</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField
            label="Name"
            autoComplete="name"
            value={name}
            onChange={(e) => {
              setName(e.target.value)
              setSaved(false)
            }}
            error={errors.name}
          />
          <TextField label="Email address" value={user.email} readOnly disabled hint="Your email can't be changed here." />
        </div>
        <div className="flex justify-end">
          <Button type="submit" loading={mutation.isPending} disabled={name.trim() === user.name}>
            Save name
          </Button>
        </div>
      </form>
    </Section>
  )
}

function AiConsentToggle({ user }: { user: User }) {
  const queryClient = useQueryClient()
  const [message, setMessage] = useState<string | null>(null)
  const mutation = useMutation({
    mutationFn: (consent: boolean) => api.updateMe({ ai_extraction_consent: consent }),
    onSuccess: (updated) => {
      queryClient.setQueryData(queryKeys.me, updated)
      setMessage(
        updated.ai_extraction_consent
          ? 'AI bill reading is on. New bills you upload can be read for you.'
          : "AI bill reading is off. We won't send your bill images to the AI provider.",
      )
    },
  })

  return (
    <Section id="ai" title="AI bill reading">
      <div className="space-y-4">
        <CheckboxField
          label="Read my bills with AI"
          checked={user.ai_extraction_consent}
          disabled={mutation.isPending}
          onChange={(e) => {
            setMessage(null)
            mutation.mutate(e.target.checked)
          }}
          hint={<AiConsentHint inSettings />}
        />
        <div aria-live="polite">
          {mutation.isPending && <p className="text-sm text-slate-600">Saving…</p>}
          {message && !mutation.isPending && <Alert tone="success">{message}</Alert>}
          {mutation.isError && (
            <Alert tone="error">We couldn't save that change. {errorMessage(mutation.error)}</Alert>
          )}
        </div>
      </div>
    </Section>
  )
}

function saveFile(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10_000)
}

function PrivacySection({ user }: { user: User }) {
  const mutation = useMutation({
    mutationFn: api.exportMyData,
    onSuccess: ({ blob, filename }) => saveFile(blob, filename),
  })

  return (
    <Section
      id="privacy"
      title="Your information"
      description="You're in control of your personal information. You can download a copy of everything we hold about you at any time."
    >
      <div className="space-y-5">
        <dl className="grid gap-4 sm:grid-cols-2">
          <Detail label="You agreed to our privacy terms on">{formatDate(user.popia_consented_at)}</Detail>
          <Detail label="Version you agreed to">{user.popia_consent_version}</Detail>
        </dl>
        <details className="rounded-xl border border-slate-200 p-4">
          <summary className="cursor-pointer font-semibold text-slate-900">What we collect and why</summary>
          <div className="mt-3">
            <PopiaNotice />
          </div>
        </details>
        <div className="flex flex-col items-start gap-2">
          <Button variant="secondary" onClick={() => mutation.mutate()} loading={mutation.isPending}>
            Download my data
          </Button>
          <p className="text-sm text-slate-600">
            A JSON file with your account, properties, bills, outages and disputes.
          </p>
          {mutation.isSuccess && <Alert tone="success">Your download has started.</Alert>}
          {mutation.isError && <Alert tone="error">We couldn't prepare your download. {errorMessage(mutation.error)}</Alert>}
        </div>
      </div>
    </Section>
  )
}

function DeleteAccountSection() {
  const { forgetSession } = useAuth()
  const [open, setOpen] = useState(false)
  const [password, setPassword] = useState('')
  const [clientError, setClientError] = useState<string | undefined>()
  const mutation = useMutation({
    mutationFn: api.deleteAccount,
    // RequireAuth then sends the browser to /sign-in, which confirms the deletion.
    onSuccess: () => forgetSession('account_deleted'),
  })
  const passwordError = clientError ?? fieldErrors(mutation.error).password

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!password) {
      setClientError('Please enter your password to confirm.')
      return
    }
    setClientError(undefined)
    mutation.mutate(password)
  }

  return (
    <Section
      id="delete"
      title="Delete my account"
      description="This permanently deletes your account, all your properties, bills, uploaded files, findings and disputes. It can't be undone."
      className="border-red-200"
    >
      {!open ? (
        <Button variant="secondary" onClick={() => setOpen(true)}>
          Delete my account
        </Button>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="space-y-4 rounded-xl border border-red-200 bg-red-50 p-4">
          <p className="text-sm text-red-950">
            To confirm, enter your password. You may want to download your data first.
          </p>
          <FormError error={mutation.error} shownFields={['password']} />
          <TextField
            label="Password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            error={passwordError}
          />
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="secondary"
              onClick={() => {
                setOpen(false)
                setPassword('')
                setClientError(undefined)
                mutation.reset()
              }}
              disabled={mutation.isPending}
            >
              Cancel
            </Button>
            <Button type="submit" variant="danger" loading={mutation.isPending}>
              Permanently delete my account
            </Button>
          </div>
        </form>
      )}
    </Section>
  )
}

export function AccountPage() {
  const me = useQuery({ queryKey: queryKeys.me, queryFn: api.getMe })
  const user = me.data

  return (
    <>
      <PageHeader title="Account" description="Your details, privacy choices and data." />
      {me.isPending ? (
        <LoadingState label="Loading your account…" />
      ) : me.isError || !user ? (
        <ErrorState error={me.error} onRetry={() => me.refetch()} />
      ) : (
        <div className="space-y-6">
          <ProfileForm user={user} />
          <AiConsentToggle user={user} />
          <PrivacySection user={user} />
          <DeleteAccountSection />
        </div>
      )}
    </>
  )
}
