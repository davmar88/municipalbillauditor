import { useQueryClient } from '@tanstack/react-query';
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { router } from 'expo-router';
import { useState } from 'react';
import { Linking, StyleSheet, View } from 'react-native';

import * as api from '@/api';
import type { UploadFile } from '@/api';
import { BILL_SHOWN_FIELDS, BillDetailsFields } from '@/components/BillDetailsFields';
import { AppText, Button, Card, FieldError, FormErrorSummary, Notice, Screen, Section } from '@/components/ui';
import { useMe, useProperty } from '@/hooks/queries';
import { useRefreshAll } from '@/hooks/useRefreshAll';
import {
  emptyBillDraft,
  lineItemErrorsFromServer,
  validateBillDraft,
  type BillDraft,
  type BillDraftErrors,
} from '@/lib/billDraft';
import { MAX_UPLOAD_BYTES, photoUploadDetails } from '@/lib/files';
import { errorMessage, fieldErrorsFrom } from '@/lib/forms';
import { queryKeys } from '@/lib/queryKeys';
import { routes } from '@/lib/routes';
import { spacing } from '@/theme';

type PickedFile = UploadFile & { size?: number; kind: 'photo' | 'pdf' };

const NO_ERRORS: BillDraftErrors = { fields: {}, lineItems: [] };

export function AddBillScreen({ propertyId }: { propertyId: number }) {
  const me = useMe();
  const property = useProperty(propertyId);
  const queryClient = useQueryClient();
  const refreshAll = useRefreshAll();
  const [file, setFile] = useState<PickedFile | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [cameraDenied, setCameraDenied] = useState(false);
  const [draft, setDraft] = useState<BillDraft>(emptyBillDraft);
  const [errors, setErrors] = useState<BillDraftErrors>(NO_ERRORS);
  const [error, setError] = useState<unknown>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const aiEnabled = me.data?.ai_extraction_consent === true;

  const choose = (picked: PickedFile) => {
    if (picked.size !== undefined && picked.size > MAX_UPLOAD_BYTES) {
      setFileError('This file is bigger than 10 MB. Try a smaller photo or PDF.');
      return;
    }
    setFileError(null);
    setFile(picked);
  };

  const fromImageAsset = (asset: ImagePicker.ImagePickerAsset) => {
    choose({ uri: asset.uri, ...photoUploadDetails(asset), size: asset.fileSize ?? undefined, kind: 'photo' });
  };

  const takePhoto = async () => {
    try {
      // Only ask for the camera when the person actually wants to use it.
      const permission = await ImagePicker.requestCameraPermissionsAsync();
      if (!permission.granted) {
        setCameraDenied(true);
        return;
      }
      setCameraDenied(false);
      const result = await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 0.7 });
      if (!result.canceled && result.assets[0]) fromImageAsset(result.assets[0]);
    } catch (e) {
      setFileError(`We couldn't open the camera. ${errorMessage(e)}`);
    }
  };

  const pickPhoto = async () => {
    try {
      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ['images'],
        quality: 0.8,
        // iPhones store photos as HEIC, which the AI bill reading can't open. "Compatible" makes
        // iOS hand over a JPEG instead. (Android already converts when quality is below 1.)
        preferredAssetRepresentationMode: ImagePicker.UIImagePickerPreferredAssetRepresentationMode.Compatible,
      });
      if (!result.canceled && result.assets[0]) fromImageAsset(result.assets[0]);
    } catch (e) {
      setFileError(`We couldn't open your photos. ${errorMessage(e)}`);
    }
  };

  const pickPdf = async () => {
    try {
      const result = await DocumentPicker.getDocumentAsync({ type: 'application/pdf', copyToCacheDirectory: true });
      if (!result.canceled && result.assets[0]) {
        const asset = result.assets[0];
        choose({
          uri: asset.uri,
          name: asset.name || 'bill.pdf',
          type: asset.mimeType ?? 'application/pdf',
          size: asset.size,
          kind: 'pdf',
        });
      }
    } catch (e) {
      setFileError(`We couldn't open your files. ${errorMessage(e)}`);
    }
  };

  const submit = async () => {
    setError(null);
    setMessage(null);
    const result = validateBillDraft(draft, { requireLineItems: false });
    setErrors(result.errors);
    if (!result.valid) {
      setMessage('Please check the highlighted fields.');
      return;
    }
    if (!file && result.values.line_items.length === 0) {
      setMessage('Add a photo or PDF of your bill, or type in at least one line item.');
      return;
    }

    setSaving(true);
    try {
      const upload = file ? { uri: file.uri, name: file.name, type: file.type } : null;
      const bill = await api.createBill(propertyId, { file: upload, ...result.values });
      queryClient.setQueryData(queryKeys.bill(bill.id), bill);
      void refreshAll();
      router.replace(routes.bill(bill.id));
    } catch (e) {
      const fields = fieldErrorsFrom(e);
      setErrors({ fields, lineItems: lineItemErrorsFromServer(fields, draft.line_items.length) });
      if (fields.file) setFileError(fields.file);
      setError(e);
      setSaving(false);
    }
  };

  return (
    <Screen>
      {property.data ? <AppText variant="muted">{`For ${property.data.nickname}`}</AppText> : null}

      <Section
        title="Photo or PDF of your bill"
        description="Optional, but it helps if you need to refer back to it later.">
        {file ? (
          <Card>
            <AppText variant="strong">{file.kind === 'pdf' ? 'PDF chosen' : 'Photo chosen'}</AppText>
            <AppText variant="small" numberOfLines={2}>
              {file.name}
            </AppText>
            {aiEnabled && file.type === 'image/heic' ? (
              <AppText variant="small">
                This photo is in HEIC format, which AI bill reading can't read yet. We'll still keep it with your bill
                and you can type in the charges yourself, or use Take photo instead.
              </AppText>
            ) : null}
            <Button
              title="Remove file"
              variant="ghost"
              icon="close"
              onPress={() => setFile(null)}
              style={styles.inlineAction}
            />
          </Card>
        ) : null}
        <View style={styles.pickers}>
          <Button
            title="Take photo"
            variant="secondary"
            icon="camera-outline"
            onPress={takePhoto}
            accessibilityHint="Asks for camera access the first time"
          />
          <Button title="Choose from photos" variant="secondary" icon="images-outline" onPress={pickPhoto} />
          <Button title="Choose a PDF" variant="secondary" icon="document-outline" onPress={pickPdf} />
        </View>
        {cameraDenied ? (
          <Notice tone="warning" title="Camera access is off">
            <AppText>
              We need your permission to use the camera to photograph your bill. You can allow it in your phone's
              settings, or choose a photo or PDF instead.
            </AppText>
            <Button title="Open settings" variant="secondary" onPress={() => void Linking.openSettings()} />
          </Notice>
        ) : null}
        <FieldError message={fileError} />
        <Notice tone="info">
          {aiEnabled
            ? "AI bill reading is on, so if you only add a file we'll try to read the dates and charges for you. If that doesn't work, you can type them in yourself."
            : "AI bill reading is off. If you only add a file, you'll be asked to type in the line items on the next screen. You can turn AI reading on in Account."}
        </Notice>
      </Section>

      <BillDetailsFields draft={draft} onChange={setDraft} errors={errors} />

      <FormErrorSummary
        message={message}
        error={message ? undefined : error}
        fieldErrors={errors.fields}
        shownFields={BILL_SHOWN_FIELDS}
      />
      <Button title="Check my bill" icon="search" onPress={submit} loading={saving} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  pickers: { gap: spacing.sm },
  inlineAction: { alignSelf: 'flex-start', marginLeft: -spacing.lg },
});
