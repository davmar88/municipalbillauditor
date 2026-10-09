import { useMutation } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { api } from '../api'
import { useAuth } from '../auth/context'
import { Button } from '../components/Button'
import { FormError } from '../components/Feedback'
import { CheckboxField, TextField } from '../components/Field'
import { AiConsentHint, PopiaNotice } from '../components/ConsentText'
import { PageHeader } from '../components/Page'
import { mergeErrors } from '../lib/forms'

const SHOWN_FIELDS = [
  'name',
  'email',
  'password',
  'password_confirmation',
  'popia_consent',
  'ai_extraction_consent',
] as const

export function SignUpPage() {
  const { signIn } = useAuth()
  const navigate = useNavigate()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [popiaConsent, setPopiaConsent] = useState(false)
  const [aiConsent, setAiConsent] = useState(false)
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})

  const mutation = useMutation({
    mutationFn: api.register,
    onSuccess: (auth) => {
      signIn(auth)
      navigate('/', { replace: true })
    },
  })

  const errors = mergeErrors(clientErrors, mutation.error)

  function validate(): Record<string, string> {
    const next: Record<string, string> = {}
    if (!name.trim()) next.name = 'Please enter your name.'
    if (!email.trim()) next.email = 'Please enter your email address.'
    if (password.length < 8) next.password = 'Your password needs at least 8 characters.'
    if (passwordConfirmation !== password) {
      next.password_confirmation = "The passwords don't match."
    }
    if (!popiaConsent) {
      next.popia_consent = 'Please agree to how we use your information so we can create your account.'
    }
    return next
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next = validate()
    setClientErrors(next)
    if (Object.keys(next).length > 0) {
      mutation.reset()
      return
    }
    mutation.mutate({
      name: name.trim(),
      email: email.trim(),
      password,
      password_confirmation: passwordConfirmation,
      popia_consent: true,
      ai_extraction_consent: aiConsent,
    })
  }

  const hasClientErrors = Object.keys(clientErrors).length > 0

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
      <PageHeader
        title="Create your account"
        description="Check your municipal bill for possible mistakes, and get help disputing them."
      />
      <form onSubmit={handleSubmit} noValidate className="space-y-5">
        <FormError error={mutation.error} shownFields={SHOWN_FIELDS} hasClientErrors={hasClientErrors} />
        <TextField
          label="Your name"
          name="name"
          autoComplete="name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          error={errors.name}
          required
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
          autoComplete="new-password"
          hint="At least 8 characters."
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={errors.password}
          required
        />
        <TextField
          label="Confirm password"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          value={passwordConfirmation}
          onChange={(e) => setPasswordConfirmation(e.target.value)}
          error={errors.password_confirmation}
          required
        />

        <fieldset className="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
          <legend className="px-1 text-sm font-semibold text-slate-900">Your personal information</legend>
          <PopiaNotice id="popia-notice" />
          <CheckboxField
            label="I agree that Bill Auditor may use my personal information as described above."
            name="popia_consent"
            checked={popiaConsent}
            onChange={(e) => setPopiaConsent(e.target.checked)}
            error={errors.popia_consent}
            extraDescribedBy="popia-notice"
            required
          />
        </fieldset>

        <CheckboxField
          label="Read my bills with AI (optional)"
          name="ai_extraction_consent"
          checked={aiConsent}
          onChange={(e) => setAiConsent(e.target.checked)}
          hint={<AiConsentHint />}
          error={errors.ai_extraction_consent}
          className="rounded-xl border border-slate-200 p-4"
        />

        <Button type="submit" loading={mutation.isPending} className="w-full">
          Create account
        </Button>
      </form>
      <p className="mt-6 text-center text-sm text-slate-600">
        Already have an account?{' '}
        <Link to="/sign-in" className="font-semibold text-brand-700 underline-offset-2 hover:underline">
          Sign in
        </Link>
      </p>
    </div>
  )
}
