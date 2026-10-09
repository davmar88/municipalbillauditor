import { photoUploadDetails } from '../files';

describe('photoUploadDetails', () => {
  it('uses the type of the file the picker wrote, not the original photo', () => {
    expect(
      photoUploadDetails({ uri: 'file:///cache/ImagePicker/a1.jpeg', mimeType: 'image/heic', fileName: 'IMG_1.heic' }),
    ).toEqual({ name: 'IMG_1.jpeg', type: 'image/jpeg' });
  });

  it('keeps PNG, WebP and HEIC files as they are', () => {
    expect(photoUploadDetails({ uri: 'file:///x/b.png', mimeType: 'image/png', fileName: 'Scan.png' })).toEqual({
      name: 'Scan.png',
      type: 'image/png',
    });
    expect(photoUploadDetails({ uri: 'file:///x/c.heic', mimeType: 'image/heic', fileName: null })).toEqual({
      name: 'bill-photo.heic',
      type: 'image/heic',
    });
  });

  it('falls back to the reported type when the file has no known extension', () => {
    expect(photoUploadDetails({ uri: 'content://media/42', mimeType: 'image/webp', fileName: 'shot.webp' })).toEqual({
      name: 'shot.webp',
      type: 'image/webp',
    });
    expect(photoUploadDetails({ uri: 'content://media/43' })).toEqual({ name: 'bill-photo.jpg', type: 'image/jpeg' });
  });
});
