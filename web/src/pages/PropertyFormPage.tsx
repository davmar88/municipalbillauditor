import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import {
  api,
  PROPERTY_TYPES,
  type MetroCode,
  type Property,
  type PropertyInput,
  type PropertyType,
} from '../api'
import { Button, ButtonLink } from '../components/Button'
import { ErrorState, FormError, LoadingState } from '../components/Feedback'
import { SelectField, TextAreaField, TextField } from '../components/Field'
import { PageHeader, Section } from '../components/Page'
import { metroOptions, useMetros } from '../hooks/useMetros'
import { mergeErrors } from '../lib/forms'
import { PROPERTY_TYPE_LABELS } from '../lib/labels'
import { queryKeys } from '../lib/queryKeys'

const FIELDS = ['nickname', 'metro', 'account_number', 'address', 'property_type'] as const

interface PropertyFormProps {
  initial?: Property
  submitLabel: string
  pending: boolean
  error: unknown
  onSubmit: (input: PropertyInput) => void
  cancelTo: string
}

function PropertyForm({ initial, submitLabel, pending, error, onSubmit, cancelTo }: PropertyFormProps) {
  const metros = useMetros()
  const [nickname, setNickname] = useState(initial?.nickname ?? '')
  const [metro, setMetro] = useState<MetroCode | ''>(initial?.metro ?? '')
  const [accountNumber, setAccountNumber] = useState(initial?.account_number ?? '')
  const [address, setAddress] = useState(initial?.address ?? '')
  const [propertyType, setPropertyType] = useState<PropertyType | ''>(initial?.property_type ?? '')
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({})

  const errors = mergeErrors(clientErrors, error)
  const options = metroOptions(metros.data)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next: Record<string, string> = {}
    if (!nickname.trim()) next.nickname = 'Give the property a name you will recognise, like "Home".'
    if (!metro) next.metro = 'Please choose the municipality that bills you.'
    if (!accountNumber.trim()) next.account_number = 'Please enter the account number from your bill.'
    if (!address.trim()) next.address = 'Please enter the property address.'
    if (!propertyType) next.property_type = 'Please choose the type of property.'
    setClientErrors(next)
    if (Object.keys(next).length > 0 || !metro || !propertyType) return
    onSubmit({
      nickname: nickname.trim(),
      metro,
      account_number: accountNumber.trim(),
      address: address.trim(),
      property_type: propertyType,
    })
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="space-y-5">
      <FormError error={error} shownFields={FIELDS} hasClientErrors={Object.keys(clientErrors).length > 0} />
      <TextField
        label="Nickname"
        hint='Something short you will recognise, like "Home" or "Flat in Melville".'
        value={nickname}
        onChange={(e) => setNickname(e.target.value)}
        error={errors.nickname}
        maxLength={100}
        required
      />
      <SelectField
        label="Municipality"
        value={metro}
        onChange={(e) => setMetro(e.target.value as MetroCode | '')}
        error={errors.metro}
        hint={metros.isPending ? 'Loading the list of municipalities…' : undefined}
        required
      >
        <option value="">Choose a municipality</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </SelectField>
      <TextField
        label="Municipal account number"
        hint="You'll find it near the top of your municipal bill. We store it encrypted."
        value={accountNumber}
        onChange={(e) => setAccountNumber(e.target.value)}
        error={errors.account_number}
        autoComplete="off"
        inputMode="text"
        spellCheck={false}
        required
      />
      <TextAreaField
        label="Property address"
        value={address}
        onChange={(e) => setAddress(e.target.value)}
        error={errors.address}
        rows={2}
        autoComplete="street-address"
        required
      />
      <SelectField
        label="Type of property"
        hint="This helps us spot bills charged on the wrong tariff."
        value={propertyType}
        onChange={(e) => setPropertyType(e.target.value as PropertyType | '')}
        error={errors.property_type}
        required
      >
        <option value="">Choose a type</option>
        {PROPERTY_TYPES.map((type) => (
          <option key={type} value={type}>
            {PROPERTY_TYPE_LABELS[type]}
          </option>
        ))}
      </SelectField>
      <div className="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:justify-end">
        <ButtonLink to={cancelTo} variant="secondary">
          Cancel
        </ButtonLink>
        <Button type="submit" loading={pending}>
          {submitLabel}
        </Button>
      </div>
    </form>
  )
}

export function NewPropertyPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: api.createProperty,
    onSuccess: async (property) => {
      queryClient.setQueryData(queryKeys.property(property.id), property)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.properties, exact: true }),
        queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
      ])
      navigate(`/properties/${property.id}`)
    },
  })

  return (
    <>
      <PageHeader
        title="Add a property"
        crumbs={[{ label: 'Overview', to: '/' }, { label: 'Add a property' }]}
        description="Tell us about the property the municipal bill is for."
      />
      <Section title="Property details">
        <PropertyForm
          submitLabel="Add property"
          pending={mutation.isPending}
          error={mutation.error}
          onSubmit={(input) => mutation.mutate(input)}
          cancelTo="/"
        />
      </Section>
    </>
  )
}

export function EditPropertyPage() {
  const propertyId = Number(useParams().propertyId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const property = useQuery({
    queryKey: queryKeys.property(propertyId),
    queryFn: () => api.getProperty(propertyId),
  })
  const mutation = useMutation({
    mutationFn: (input: PropertyInput) => api.updateProperty(propertyId, input),
    onSuccess: async (updated) => {
      queryClient.setQueryData(queryKeys.property(propertyId), updated)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.properties }),
        queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
        queryClient.invalidateQueries({ queryKey: ['bills'] }),
      ])
      navigate(`/properties/${propertyId}`)
    },
  })

  return (
    <>
      <PageHeader
        title="Edit property"
        crumbs={[
          { label: 'Overview', to: '/' },
          { label: property.data?.nickname ?? 'Property', to: `/properties/${propertyId}` },
          { label: 'Edit' },
        ]}
      />
      {property.isPending ? (
        <LoadingState label="Loading the property…" />
      ) : property.isError ? (
        <ErrorState error={property.error} onRetry={() => property.refetch()} />
      ) : (
        <Section title="Property details">
          <PropertyForm
            initial={property.data}
            submitLabel="Save changes"
            pending={mutation.isPending}
            error={mutation.error}
            onSubmit={(input) => mutation.mutate(input)}
            cancelTo={`/properties/${propertyId}`}
          />
        </Section>
      )}
    </>
  )
}
