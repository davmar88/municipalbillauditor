import { useQueryClient } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import { useState } from 'react';

import * as api from '@/api';
import type { MetroCode, Property, PropertyInput, PropertyType } from '@/api';
import {
  Button,
  CenteredScreen,
  ErrorState,
  FormErrorSummary,
  LoadingState,
  Notice,
  Screen,
  SelectField,
  TextField,
} from '@/components/ui';
import { useMetros, useProperty } from '@/hooks/queries';
import { useRefreshAll } from '@/hooks/useRefreshAll';
import { fieldErrorsFrom, type FieldErrors } from '@/lib/forms';
import { METRO_LABELS, PROPERTY_TYPE_OPTIONS, type Option } from '@/lib/labels';
import { queryKeys } from '@/lib/queryKeys';
import { goBackOr, routes } from '@/lib/routes';

const FIELDS = ['nickname', 'metro', 'account_number', 'address', 'property_type'] as const;

/** Add a property (no id) or edit one (with id). */
export function PropertyFormScreen({ propertyId }: { propertyId?: number }) {
  const property = useProperty(propertyId ?? 0);
  if (!propertyId) return <PropertyForm />;
  if (property.isPending) {
    return (
      <CenteredScreen>
        <LoadingState />
      </CenteredScreen>
    );
  }
  if (property.isError) {
    return (
      <CenteredScreen>
        <ErrorState error={property.error} onRetry={() => property.refetch()} />
      </CenteredScreen>
    );
  }
  return <PropertyForm existing={property.data} />;
}

function PropertyForm({ existing }: { existing?: Property }) {
  const metros = useMetros();
  const queryClient = useQueryClient();
  const refreshAll = useRefreshAll();
  const [nickname, setNickname] = useState(existing?.nickname ?? '');
  const [metro, setMetro] = useState<MetroCode | null>(existing?.metro ?? null);
  const [accountNumber, setAccountNumber] = useState(existing?.account_number ?? '');
  const [address, setAddress] = useState(existing?.address ?? '');
  const [propertyType, setPropertyType] = useState<PropertyType | null>(existing?.property_type ?? null);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);

  // Fall back to our own labels if the metro list can't be loaded, so the form still works.
  const metroOptions: Option<MetroCode>[] = metros.data
    ? metros.data.map((m) => ({ value: m.code, label: m.name }))
    : (Object.keys(METRO_LABELS) as MetroCode[]).map((code) => ({ value: code, label: METRO_LABELS[code] }));

  const submit = async () => {
    const local: FieldErrors = {};
    if (!nickname.trim()) local.nickname = 'Give this property a name you will recognise.';
    if (!metro) local.metro = 'Choose the municipality that sends the bill.';
    if (!accountNumber.trim()) local.account_number = 'Enter the account number from your bill.';
    if (!address.trim()) local.address = 'Enter the property address.';
    if (!propertyType) local.property_type = 'Choose the type of property.';
    setErrors(local);
    setError(null);
    if (Object.keys(local).length > 0 || !metro || !propertyType) return;

    const input: PropertyInput = {
      nickname: nickname.trim(),
      metro,
      account_number: accountNumber.trim(),
      address: address.trim(),
      property_type: propertyType,
    };
    setSaving(true);
    try {
      const saved = existing ? await api.updateProperty(existing.id, input) : await api.createProperty(input);
      queryClient.setQueryData(queryKeys.property(saved.id), saved);
      void refreshAll();
      if (existing) goBackOr(routes.property(saved.id));
      else router.replace(routes.property(saved.id));
    } catch (e) {
      setErrors(fieldErrorsFrom(e));
      setError(e);
      setSaving(false);
    }
  };

  return (
    <Screen>
      <Stack.Screen options={{ title: existing ? 'Edit property' : 'Add a property' }} />
      <TextField
        label="Nickname"
        hint="A name you'll recognise, like Home or Sunset Court."
        value={nickname}
        onChangeText={setNickname}
        error={errors.nickname}
      />
      <SelectField
        label="Municipality"
        value={metro}
        options={metroOptions}
        onChange={setMetro}
        placeholder="Choose your municipality"
        error={errors.metro}
      />
      {metros.isError ? (
        <Notice tone="warning">
          We couldn't load the latest list of municipalities, so we're showing a basic list.
        </Notice>
      ) : null}
      <TextField
        label="Municipal account number"
        hint="Printed near the top of your municipal bill."
        value={accountNumber}
        onChangeText={setAccountNumber}
        autoCapitalize="characters"
        autoCorrect={false}
        error={errors.account_number}
      />
      <TextField
        label="Property address"
        value={address}
        onChangeText={setAddress}
        autoComplete="street-address"
        multiline
        style={{ minHeight: 80 }}
        error={errors.address}
      />
      <SelectField
        label="Type of property"
        hint="This helps us spot a bill on the wrong tariff."
        value={propertyType}
        options={PROPERTY_TYPE_OPTIONS}
        onChange={setPropertyType}
        placeholder="Choose the type"
        error={errors.property_type}
      />
      <Notice tone="neutral">
        Your account number and address are stored encrypted and are only used to check your bills and prepare disputes.
      </Notice>
      <FormErrorSummary error={error} fieldErrors={errors} shownFields={FIELDS} />
      <Button title={existing ? 'Save changes' : 'Add property'} onPress={submit} loading={saving} />
    </Screen>
  );
}
