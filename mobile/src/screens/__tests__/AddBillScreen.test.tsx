import { fireEvent, screen, waitFor } from '@testing-library/react-native';
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { router } from 'expo-router';

import * as api from '@/api';
import { makeBill, makeProperty, makeUser } from '@/test/fixtures';
import { renderWithProviders } from '@/test/render';

import { AddBillScreen } from '../AddBillScreen';

jest.mock('expo-router', () => ({
  router: { push: jest.fn(), replace: jest.fn(), back: jest.fn(), canGoBack: jest.fn(() => true) },
  Stack: { Screen: () => null },
}));

jest.mock('expo-image-picker', () => ({
  requestCameraPermissionsAsync: jest.fn(),
  launchCameraAsync: jest.fn(),
  launchImageLibraryAsync: jest.fn(),
  UIImagePickerPreferredAssetRepresentationMode: {
    Automatic: 'automatic',
    Compatible: 'compatible',
    Current: 'current',
  },
}));

jest.mock('expo-document-picker', () => ({ getDocumentAsync: jest.fn() }));

jest.mock('@/api', () => ({
  ...jest.requireActual('@/api'),
  getMe: jest.fn(),
  getProperty: jest.fn(),
  createBill: jest.fn(),
}));

const mocked = api as jest.Mocked<typeof api>;
const picker = ImagePicker as jest.Mocked<typeof ImagePicker>;
const documents = DocumentPicker as jest.Mocked<typeof DocumentPicker>;

beforeEach(() => {
  jest.clearAllMocks();
  mocked.getMe.mockResolvedValue(makeUser({ ai_extraction_consent: false }));
  mocked.getProperty.mockResolvedValue(makeProperty());
});

describe('AddBillScreen', () => {
  it('asks for the camera only on "Take photo" and explains a refusal', async () => {
    picker.requestCameraPermissionsAsync.mockResolvedValue({ granted: false } as ImagePicker.CameraPermissionResponse);
    await renderWithProviders(<AddBillScreen propertyId={3} />);

    expect(picker.requestCameraPermissionsAsync).not.toHaveBeenCalled();
    await fireEvent.press(screen.getByRole('button', { name: 'Take photo' }));

    expect(picker.requestCameraPermissionsAsync).toHaveBeenCalledTimes(1);
    expect(picker.launchCameraAsync).not.toHaveBeenCalled();
    expect(await screen.findByText('Camera access is off')).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Open settings' })).toBeOnTheScreen();
  });

  it('explains that without AI reading you will type line items next', async () => {
    await renderWithProviders(<AddBillScreen propertyId={3} />);
    expect(await screen.findByText(/asked to type in the line items on the next screen/)).toBeOnTheScreen();
  });

  it('needs a file or at least one line item', async () => {
    await renderWithProviders(<AddBillScreen propertyId={3} />);
    await fireEvent.press(screen.getByRole('button', { name: 'Check my bill' }));

    expect(screen.getByText('Add a photo or PDF of your bill, or type in at least one line item.')).toBeOnTheScreen();
    expect(mocked.createBill).not.toHaveBeenCalled();
  });

  it('uploads a chosen PDF and opens the new bill', async () => {
    documents.getDocumentAsync.mockResolvedValue({
      canceled: false,
      assets: [
        { uri: 'file:///cache/bill.pdf', name: 'bill.pdf', mimeType: 'application/pdf', size: 20_000, lastModified: 0 },
      ],
    } as DocumentPicker.DocumentPickerResult);
    mocked.createBill.mockResolvedValue(makeBill({ id: 12, status: 'needs_review', line_items: [], findings: [] }));
    await renderWithProviders(<AddBillScreen propertyId={3} />);

    await fireEvent.press(screen.getByRole('button', { name: 'Choose a PDF' }));
    expect(await screen.findByText('PDF chosen')).toBeOnTheScreen();
    await fireEvent.changeText(screen.getByLabelText('Current charges total (R) (optional)'), '4 123,50');
    await fireEvent.press(screen.getByRole('button', { name: 'Check my bill' }));

    await waitFor(() =>
      expect(mocked.createBill).toHaveBeenCalledWith(3, {
        file: { uri: 'file:///cache/bill.pdf', name: 'bill.pdf', type: 'application/pdf' },
        bill_date: null,
        period_start: null,
        period_end: null,
        due_date: null,
        total_cents: 412350,
        line_items: [],
      }),
    );
    expect(router.replace).toHaveBeenCalledWith({ pathname: '/bill/[id]', params: { id: '12' } });
  });

  it('asks the iPhone photo library for a JPEG, since AI reading cannot open HEIC', async () => {
    picker.launchImageLibraryAsync.mockResolvedValue({ canceled: true, assets: null });
    await renderWithProviders(<AddBillScreen propertyId={3} />);

    await fireEvent.press(screen.getByRole('button', { name: 'Choose from photos' }));

    expect(picker.launchImageLibraryAsync).toHaveBeenCalledWith(
      expect.objectContaining({ mediaTypes: ['images'], preferredAssetRepresentationMode: 'compatible' }),
    );
  });

  it('uploads a photo with the type of the file the picker saved', async () => {
    // Android converts a HEIC photo to JPEG but still reports the original type and name.
    picker.launchImageLibraryAsync.mockResolvedValue({
      canceled: false,
      assets: [
        {
          uri: 'file:///cache/ImagePicker/0b1c.jpeg',
          mimeType: 'image/heic',
          fileName: 'IMG_0042.heic',
          fileSize: 2_000_000,
          width: 3000,
          height: 4000,
        },
      ],
    });
    mocked.createBill.mockResolvedValue(makeBill({ id: 12, status: 'needs_review', line_items: [], findings: [] }));
    await renderWithProviders(<AddBillScreen propertyId={3} />);

    await fireEvent.press(screen.getByRole('button', { name: 'Choose from photos' }));
    expect(await screen.findByText('IMG_0042.jpeg')).toBeOnTheScreen();
    await fireEvent.press(screen.getByRole('button', { name: 'Check my bill' }));

    await waitFor(() =>
      expect(mocked.createBill).toHaveBeenCalledWith(
        3,
        expect.objectContaining({
          file: { uri: 'file:///cache/ImagePicker/0b1c.jpeg', name: 'IMG_0042.jpeg', type: 'image/jpeg' },
        }),
      ),
    );
  });

  it('warns when a photo is still HEIC and AI reading is on', async () => {
    mocked.getMe.mockResolvedValue(makeUser({ ai_extraction_consent: true }));
    picker.launchImageLibraryAsync.mockResolvedValue({
      canceled: false,
      assets: [
        {
          uri: 'file:///cache/ImagePicker/9f.heic',
          mimeType: 'image/heic',
          fileName: 'IMG_7.heic',
          width: 1,
          height: 1,
        },
      ],
    });
    await renderWithProviders(<AddBillScreen propertyId={3} />);
    await screen.findByText(/AI bill reading is on/);

    await fireEvent.press(screen.getByRole('button', { name: 'Choose from photos' }));

    expect(await screen.findByText(/This photo is in HEIC format/)).toBeOnTheScreen();
  });

  it('rejects files over 10 MB', async () => {
    documents.getDocumentAsync.mockResolvedValue({
      canceled: false,
      assets: [
        {
          uri: 'file:///big.pdf',
          name: 'big.pdf',
          mimeType: 'application/pdf',
          size: 11 * 1024 * 1024,
          lastModified: 0,
        },
      ],
    } as DocumentPicker.DocumentPickerResult);
    await renderWithProviders(<AddBillScreen propertyId={3} />);

    await fireEvent.press(screen.getByRole('button', { name: 'Choose a PDF' }));
    expect(await screen.findByText('This file is bigger than 10 MB. Try a smaller photo or PDF.')).toBeOnTheScreen();
    expect(screen.queryByText('PDF chosen')).toBeNull();
  });
});
