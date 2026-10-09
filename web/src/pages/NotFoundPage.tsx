import { ButtonLink } from '../components/Button'
import { PageHeader } from '../components/Page'

export function NotFoundPage() {
  return (
    <div className="mx-auto max-w-lg py-12 text-center">
      <PageHeader
        title="We couldn't find that page"
        description="The link may be wrong, or the page may have moved."
      />
      <ButtonLink to="/">Go to your overview</ButtonLink>
    </div>
  )
}
