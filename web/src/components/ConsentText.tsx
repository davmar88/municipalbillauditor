/** Consent wording shared by sign-up and account settings. */

export function PopiaNotice({ id }: { id?: string }) {
  return (
    <div id={id} className="space-y-2 text-sm leading-relaxed text-slate-700">
      <p>
        <strong className="text-slate-900">What we collect:</strong> your name and email, the municipal bills you
        add (including any photo or PDF you upload), your municipal account numbers and property addresses.
      </p>
      <p>
        <strong className="text-slate-900">Why:</strong> only to check your bills for possible errors and to help
        you prepare and keep track of disputes.
      </p>
      <p>
        <strong className="text-slate-900">Your choice:</strong> you can download or delete everything at any time
        from your Account page. Account numbers and addresses are stored encrypted, and your bill files are kept
        private.
      </p>
    </div>
  )
}

export function AiConsentHint({ inSettings = false }: { inSettings?: boolean }) {
  return (
    <>
      To save you typing, we can send the photo or PDF of your bill to an AI provider{' '}
      <strong>outside South Africa</strong> so it can read the amounts and line items for you. If you leave this
      off, your bill image is never sent there and you type the line items in yourself.{' '}
      {inSettings
        ? 'Turning it off only affects bills you upload from now on.'
        : 'You can change this at any time in your account settings.'}
    </>
  )
}
