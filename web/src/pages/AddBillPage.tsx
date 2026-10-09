import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { api, type CreateBillInput } from '../api'
import { useAuth } from '../auth/context'
import { BillFieldsEditor, LineItemsEditor } from '../components/BillEditor'
import { Button, ButtonLink } from '../components/Button'
import { Alert, ErrorState, FormError, LoadingState } from '../components/Feedback'
import { FieldError } from '../components/Field'
import { PageHeader, Section } from '../components/Page'
import {
  billFieldsToInput,
  emptyBillFields,
  lineItemsToInput,
  type BillFieldsDraft,
  type LineItemDraft,
} from '../lib/billDraft'
import { formatFileSize } from '../lib/format'
import { mergeErrors } from '../lib/forms'
import { invalidateMoneyData, queryKeys } from '../lib/queryKeys'

const MAX_FILE_BYTES = 10 * 1024 * 1024
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf']
const ACCEPT_ATTR = 'image/jpeg,image/png,image/webp,image/heic,.heic,application/pdf,.pdf'

const SHOWN = ['file', 'bill_date', 'period_start', 'period_end', 'due_date', 'total_cents']

function isAcceptedFile(file: File): boolean {
  if (ACCEPTED_TYPES.includes(file.type)) return true
  // Some browsers leave HEIC photos without a MIME type.
  return /\.(jpe?g|png|webp|heic|pdf)$/i.test(file.name)
}

function fileError(file: File | null): string | undefined {
  if (!file) return undefined
  if (!isAcceptedFile(file)) return 'Please choose a photo (JPEG, PNG, WebP or HEIC) or a PDF.'
  if (file.size > MAX_FILE_BYTES) return 'That file is larger than 10 MB. Please choose a smaller one.'
  return undefined
}

export function AddBillPage() {
  const propertyId = Number(useParams().propertyId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const fileId = useId()

  const property = useQuery({
    queryKey: queryKeys.property(propertyId),
    queryFn: () => api.getProperty(propertyId),
  })

  const [file, setFile] = useState<File | null>(null)
  const [fields, setFields] = useState<BillFieldsDraft>(emptyBillFields)
  const [items, setItems] = useState<LineItemDraft[]>([])
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})
  const [attempted, setAttempted] = useState(false)

  const mutation = useMutation({
    mutationFn: (input: CreateBillInput) => api.createBill(propertyId, input),
    onSuccess: async (bill) => {
      queryClient.setQueryData(queryKeys.bill(bill.id), bill)
      navigate(`/bills/${bill.id}`)
      await invalidateMoneyData(queryClient)
    },
  })

  const errors = mergeErrors(clientErrors, mutation.error)
  const shownFields = [...SHOWN, ...Object.keys(errors).filter((key) => key.startsWith('line_items.'))]

  function handleFile(next: File | null) {
    setFile(next)
    const message = fileError(next)
    setClientErrors((prev) => {
      const copy = { ...prev }
      delete copy.file
      if (message) copy.file = message
      return copy
    })
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setAttempted(true)
    const billFields = billFieldsToInput(fields)
    const lineItems = lineItemsToInput(items)
    const next: Record<string, string> = { ...billFields.errors, ...lineItems.errors }
    const problem = fileError(file)
    if (problem) next.file = problem
    if (!file && items.length === 0) {
      next.file = 'Please upload your bill, or add at least one line item below.'
    }
    setClientErrors(next)
    if (Object.keys(next).length > 0) {
      mutation.reset()
      return
    }
    mutation.mutate({
      file,
      ...billFields.value,
      line_items: items.length > 0 ? lineItems.value : null,
    })
  }

  if (property.isPending) return <LoadingState label="Loading…" />
  if (property.isError) return <ErrorState error={property.error} onRetry={() => property.refetch()} />

  const aiOn = user?.ai_extraction_consent === true

  return (
    <>
      <PageHeader
        title="Add a bill"
        crumbs={[
          { label: 'Overview', to: '/' },
          { label: property.data.nickname, to: `/properties/${propertyId}` },
          { label: 'Add a bill' },
        ]}
        description="Upload your bill, type in its details, or both. The more you add, the better we can check it."
      />
      <form onSubmit={handleSubmit} noValidate className="space-y-6">
        <FormError
          error={mutation.error}
          shownFields={shownFields}
          hasClientErrors={attempted && Object.keys(clientErrors).length > 0}
        />

        <Section title="Upload your bill" description="A clear photo or the PDF from the municipality. Up to 10 MB.">
          <div className="space-y-4">
            <div>
              <label htmlFor={fileId} className="mb-1 block text-sm font-semibold text-slate-800">
                Bill photo or PDF <span className="font-normal text-slate-500">(optional)</span>
              </label>
              <input
                id={fileId}
                type="file"
                accept={ACCEPT_ATTR}
                onChange={(e) => handleFile(e.target.files?.[0] ?? null)}
                aria-invalid={errors.file ? true : undefined}
                aria-describedby={errors.file ? `${fileId}-error` : undefined}
                className="block w-full cursor-pointer rounded-lg border border-slate-300 bg-white text-sm text-slate-700 file:mr-4 file:cursor-pointer file:border-0 file:bg-brand-50 file:px-4 file:py-3 file:font-semibold file:text-brand-800 hover:file:bg-brand-100"
              />
              <FieldError id={`${fileId}-error`} message={errors.file} />
              {file && !errors.file && (
                <p className="mt-2 text-sm text-slate-600">
                  Selected: <span className="font-medium text-slate-900">{file.name}</span> ({formatFileSize(file.size)})
                </p>
              )}
            </div>
            {aiOn ? (
              <Alert tone="info">
                You've turned on AI bill reading. If you only upload a file, we'll try to read the line items for
                you. If that doesn't work, we'll ask you to type them in.
              </Alert>
            ) : (
              <Alert tone="info">
                If you only upload a file, you'll be asked to type in the line items next, because AI bill reading
                is off. You can turn it on in{' '}
                <Link to="/account" className="font-semibold underline underline-offset-2">
                  your account settings
                </Link>
                .
              </Alert>
            )}
          </div>
        </Section>

        <Section
          title="Bill details"
          description="Copy these from your bill if you have it handy. You can also add or change them later."
        >
          <BillFieldsEditor value={fields} onChange={setFields} errors={errors} />
        </Section>

        <Section
          title="Line items"
          description="Each charge on the bill, such as water, electricity, refuse and rates. Meter readings help us spot estimates and unusual jumps."
        >
          <LineItemsEditor items={items} onChange={setItems} errors={errors} />
        </Section>

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <ButtonLink to={`/properties/${propertyId}`} variant="secondary">
            Cancel
          </ButtonLink>
          <Button type="submit" loading={mutation.isPending}>
            {mutation.isPending ? 'Adding your bill…' : 'Add bill and check it'}
          </Button>
        </div>
      </form>
    </>
  )
}
