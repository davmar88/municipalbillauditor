import { useMutation } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { api } from '../api'
import { useAuth } from '../auth/context'
import { Button } from '../components/Button'
import { Alert, FormError } from '../components/Feedback'
import { TextField } from '../components/Field'
import { PageHeader } from '../components/Page'
import { mergeErrors } from '../lib/forms'

export function SignInPage() {
  const { signIn, signedOutReason } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})

  const mutation = useMutation({
    mutationFn: api.login,
    // PublicOnly then moves on: back to the page you were on if your session
    // expired, otherwise the overview.
    onSuccess: (auth) => signIn(auth),
  })

  const errors = mergeErrors(clientErrors, mutation.error)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next: Record<string, string> = {}
    if (!email.trim()) next.email = 'Please enter your email address.'
    if (!password) next.password = 'Please enter your password.'
    setClientErrors(next)
    if (Object.keys(next).length > 0) {
      mutation.reset()
      return
    }
    mutation.mutate({ email: email.trim(), password })
  }

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
      <PageHeader title="Sign in" description="Welcome back. Sign in to check on your bills and disputes." />
      <div className="space-y-5">
        {signedOutReason === 'account_deleted' && (
          <Alert tone="success">Your account and all your data have been deleted.</Alert>
        )}
        {signedOutReason === 'signed_out' && <Alert tone="success">You've signed out.</Alert>}
        {signedOutReason === 'expired' && (
          <Alert tone="info">You were signed out. Please sign in again to continue.</Alert>
        )}
        <form onSubmit={handleSubmit} noValidate className="space-y-5">
          <FormError
            error={mutation.error}
            shownFields={['email', 'password']}
            hasClientErrors={Object.keys(clientErrors).length > 0}
          />
          <TextField
            label="Email address"
            name="email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            error={errors.email}
            required
          />
          <TextField
            label="Password"
            name="password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            error={errors.password}
            required
          />
          <Button type="submit" loading={mutation.isPending} className="w-full">
            Sign in
          </Button>
        </form>
      </div>
      <p className="mt-6 text-center text-sm text-slate-600">
        New here?{' '}
        <Link to="/sign-up" className="font-semibold text-brand-700 underline-offset-2 hover:underline">
          Create an account
        </Link>
      </p>
    </div>
  )
}
