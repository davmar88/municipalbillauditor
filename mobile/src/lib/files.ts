import * as Sharing from 'expo-sharing';

const UTIS: Record<string, string> = {
  'application/pdf': 'com.adobe.pdf',
  'application/json': 'public.json',
  'image/jpeg': 'public.jpeg',
  'image/png': 'public.png',
  'image/heic': 'public.heic',
  'image/webp': 'org.webmproject.webp',
};

/** Opens the system share sheet for a downloaded file, so it can be viewed, saved or sent. */
export async function openLocalFile(uri: string, mimeType: string, dialogTitle: string): Promise<void> {
  if (!(await Sharing.isAvailableAsync())) {
    throw new Error("Your device can't open files from this app. Please try on another device.");
  }
  await Sharing.shareAsync(uri, { mimeType, dialogTitle, UTI: UTIS[mimeType] });
}

/** Bill files may be at most 10 MB (docs/api.md). */
export const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

const IMAGE_TYPES: Record<string, string> = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  heic: 'image/heic',
  heif: 'image/heic',
};

function extensionOf(path: string | null | undefined): string | null {
  const match = /\.([a-z0-9]+)(?:[?#].*)?$/i.exec(path ?? '');
  return match ? match[1].toLowerCase() : null;
}

/**
 * Name and type to upload a picked or taken photo with. The type comes from the file the picker
 * actually wrote: on Android a HEIC photo is saved as JPEG but still reported as image/heic, and
 * the AI bill reading only accepts the formats it is told the file is in.
 */
export function photoUploadDetails(asset: { uri: string; mimeType?: string | null; fileName?: string | null }): {
  name: string;
  type: string;
} {
  const fromUri = extensionOf(asset.uri);
  const type = (fromUri && IMAGE_TYPES[fromUri]) || asset.mimeType || 'image/jpeg';
  const extension = fromUri && IMAGE_TYPES[fromUri] ? fromUri : (extensionOf(asset.fileName) ?? 'jpg');
  const baseName = asset.fileName ? asset.fileName.replace(/\.[^.]*$/, '') : 'bill-photo';
  return { name: `${baseName || 'bill-photo'}.${extension}`, type };
}
