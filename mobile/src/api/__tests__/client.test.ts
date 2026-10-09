import { File } from 'expo-file-system';

import * as api from '@/api';
import { ApiError, setAuthToken, setBaseUrl, setUnauthorizedHandler } from '@/api';

jest.mock('expo-file-system', () => {
  // One shared fake "downloads" folder, so tests can see it being created and deleted.
  const folder = { exists: false, create: jest.fn(), delete: jest.fn() };
  folder.create.mockImplementation(() => {
    folder.exists = true;
  });
  folder.delete.mockImplementation(() => {
    folder.exists = false;
  });
  const join = (parts: unknown[]) =>
    parts.map((p) => (typeof p === 'string' ? p : (p as { uri: string }).uri)).join('/');
  class MockDirectory {
    uri: string;
    constructor(...parts: unknown[]) {
      this.uri = join(parts);
    }
    get exists() {
      return folder.exists;
    }
    create(options: unknown) {
      folder.create(this.uri, options);
    }
    delete() {
      folder.delete(this.uri);
    }
  }
  class MockFile {
    uri: string;
    constructor(...parts: unknown[]) {
      this.uri = join(parts);
    }
    static downloadFileAsync = jest.fn();
  }
  return { File: MockFile, Directory: MockDirectory, Paths: { cache: { uri: 'file:///cache' } }, mockFolder: folder };
});

const mockFolder = (
  jest.requireMock('expo-file-system') as {
    mockFolder: { exists: boolean; create: jest.Mock; delete: jest.Mock };
  }
).mockFolder;

type FetchMock = jest.Mock<Promise<Response>, [string, RequestInit]>;

function jsonResponse(status: number, body?: unknown): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: () => Promise.resolve(body === undefined ? '' : JSON.stringify(body)),
  } as Response;
}

let fetchMock: FetchMock;

beforeEach(() => {
  fetchMock = jest.fn();
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  setBaseUrl('https://api.test/api/v1');
  setAuthToken(null);
  setUnauthorizedHandler(null);
});

function lastCall() {
  const [url, init] = fetchMock.mock.calls[fetchMock.mock.calls.length - 1];
  return { url, init, headers: init.headers as Record<string, string> };
}

describe('API client', () => {
  it('sends the bearer token and JSON headers', async () => {
    setAuthToken('secret-token');
    fetchMock.mockResolvedValue(jsonResponse(200, { data: { id: 1, name: 'Thandi M' } }));

    const user = await api.getMe();

    const { url, init, headers } = lastCall();
    expect(url).toBe('https://api.test/api/v1/me');
    expect(init.method).toBe('GET');
    expect(headers.Authorization).toBe('Bearer secret-token');
    expect(headers.Accept).toBe('application/json');
    expect(user).toEqual({ id: 1, name: 'Thandi M' });
  });

  it('does not send an Authorization header when signed out', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [] }));
    await api.listMetros();
    expect(lastCall().headers.Authorization).toBeUndefined();
  });

  it('sends JSON bodies with a content type', async () => {
    setAuthToken('t');
    fetchMock.mockResolvedValue(jsonResponse(200, { data: { id: 7, status: 'dismissed' } }));

    await api.updateFinding(7, { status: 'dismissed' });

    const { url, init, headers } = lastCall();
    expect(url).toBe('https://api.test/api/v1/findings/7');
    expect(init.method).toBe('PATCH');
    expect(headers['Content-Type']).toBe('application/json');
    expect(JSON.parse(init.body as string)).toEqual({ status: 'dismissed' });
  });

  it('returns undefined for 204 responses', async () => {
    setAuthToken('t');
    fetchMock.mockResolvedValue(jsonResponse(204));
    await expect(api.deleteProperty(3)).resolves.toBeUndefined();
    expect(lastCall().init.method).toBe('DELETE');
  });

  it('sends the password in the body of DELETE /me', async () => {
    setAuthToken('t');
    fetchMock.mockResolvedValue(jsonResponse(204));
    await api.deleteMe({ password: 'hunter22' });
    const { init, url } = lastCall();
    expect(url).toBe('https://api.test/api/v1/me');
    expect(JSON.parse(init.body as string)).toEqual({ password: 'hunter22' });
  });

  it('parses 422 validation errors into field errors', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'The email has already been taken. (and 1 more error)',
        errors: {
          email: ['The email has already been taken.'],
          password: ['The password must be at least 8 characters.'],
        },
      }),
    );

    const error = await api
      .register({ name: 'A', email: 'a@b.co', password: 'x', password_confirmation: 'x', popia_consent: true })
      .catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    const apiError = error as ApiError;
    expect(apiError.status).toBe(422);
    expect(apiError.isValidation).toBe(true);
    expect(apiError.errors.email).toEqual(['The email has already been taken.']);
    expect(apiError.errors.password).toEqual(['The password must be at least 8 characters.']);
  });

  it('clears the token and signals sign-out on any 401', async () => {
    const onUnauthorized = jest.fn();
    setUnauthorizedHandler(onUnauthorized);
    setAuthToken('expired-token');
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }));

    await expect(api.getDashboard()).rejects.toMatchObject({ status: 401 });

    expect(onUnauthorized).toHaveBeenCalledTimes(1);
    expect(api.getAuthToken()).toBeNull();
  });

  it('ignores a 401 for a request that carried no token', async () => {
    const onUnauthorized = jest.fn();
    setUnauthorizedHandler(onUnauthorized);
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }));

    await expect(api.getDashboard()).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('does not sign out on other errors', async () => {
    const onUnauthorized = jest.fn();
    setUnauthorizedHandler(onUnauthorized);
    setAuthToken('t');
    fetchMock.mockResolvedValue(jsonResponse(404, { message: 'Not found.' }));

    await expect(api.getBill(99)).rejects.toMatchObject({ status: 404 });
    expect(onUnauthorized).not.toHaveBeenCalled();
    expect(api.getAuthToken()).toBe('t');
  });

  it('turns network failures into a friendly error', async () => {
    fetchMock.mockRejectedValue(new TypeError('Network request failed'));
    await expect(api.listMetros()).rejects.toMatchObject({
      status: 0,
      message: expect.stringContaining("couldn't reach"),
    });
  });

  it('copes with non-JSON error bodies', async () => {
    fetchMock.mockResolvedValue({
      ok: false,
      status: 500,
      text: () => Promise.resolve('<html>oops</html>'),
    } as Response);
    await expect(api.listMetros()).rejects.toMatchObject({
      status: 500,
      message: expect.stringContaining('Something went wrong'),
    });
  });

  it('uploads bills as multipart with the file and JSON-encoded line items', async () => {
    setAuthToken('t');
    fetchMock.mockResolvedValue(jsonResponse(201, { data: { id: 5, status: 'audited' } }));
    const lineItems: api.LineItemInput[] = [
      { service: 'water', reading_type: 'estimated', consumption: 38, unit: 'kl', amount_cents: 152340 },
    ];

    await api.createBill(3, {
      file: { uri: 'file:///photos/bill.jpg', name: 'bill.jpg', type: 'image/jpeg' },
      bill_date: '2026-09-25',
      period_start: null,
      total_cents: 412350,
      line_items: lineItems,
    });

    const { url, init, headers } = lastCall();
    expect(url).toBe('https://api.test/api/v1/properties/3/bills');
    expect(init.method).toBe('POST');
    expect(headers.Authorization).toBe('Bearer t');
    // The boundary is set by the networking layer, so the client must not set Content-Type itself.
    expect(headers['Content-Type']).toBeUndefined();
    expect(init.body).toBeInstanceOf(FormData);

    const parts = (
      init.body as unknown as {
        getParts(): { fieldName: string; string?: string; uri?: string; name?: string; type?: string }[];
      }
    ).getParts();
    const byName = Object.fromEntries(parts.map((part) => [part.fieldName, part]));
    expect(byName.file).toMatchObject({ uri: 'file:///photos/bill.jpg', name: 'bill.jpg', type: 'image/jpeg' });
    expect(byName.bill_date.string).toBe('2026-09-25');
    expect(byName.total_cents.string).toBe('412350');
    expect(JSON.parse(byName.line_items.string!)).toEqual(lineItems);
    // Empty values are left out rather than sent as "null".
    expect(byName.period_start).toBeUndefined();
    expect(byName.due_date).toBeUndefined();
  });

  it('can upload a file without line items', () => {
    const form = api.buildBillFormData({ file: { uri: 'file:///b.pdf', name: 'b.pdf', type: 'application/pdf' } });
    const names = (form as unknown as { getParts(): { fieldName: string }[] }).getParts().map((p) => p.fieldName);
    expect(names).toEqual(['file']);
  });

  it('uses the right paths and methods for dispute actions', async () => {
    setAuthToken('t');
    fetchMock.mockResolvedValue(jsonResponse(200, { data: { id: 4 } }));

    await api.submitDispute(4, { channel: 'email', municipality_reference: 'QRY-1' });
    expect(lastCall().url).toBe('https://api.test/api/v1/disputes/4/submit');
    await api.addDisputeEvent(4, { type: 'acknowledged' });
    expect(lastCall().url).toBe('https://api.test/api/v1/disputes/4/events');
    await api.escalateDispute(4);
    expect(lastCall().url).toBe('https://api.test/api/v1/disputes/4/escalate');
    await api.resolveDispute(4, { outcome: 'resolved', outcome_amount_cents: 98000 });
    expect(lastCall().url).toBe('https://api.test/api/v1/disputes/4/resolve');
    expect(JSON.parse(lastCall().init.body as string)).toEqual({ outcome: 'resolved', outcome_amount_cents: 98000 });
    await api.createDispute(5, { finding_ids: [7, 8] });
    expect(lastCall().url).toBe('https://api.test/api/v1/bills/5/disputes');
  });
});

describe('authenticated downloads', () => {
  const download = File.downloadFileAsync as unknown as jest.Mock;

  beforeEach(() => download.mockReset());

  it('downloads the original bill to the cache with the auth header', async () => {
    setAuthToken('file-token');
    download.mockImplementation((_url: string, destination: { uri: string }) => Promise.resolve(destination));

    const uri = await api.downloadBillFile(5, 'application/pdf');

    expect(uri).toBe('file:///cache/downloads/bill-5.pdf');
    expect(mockFolder.create).toHaveBeenCalledWith('file:///cache/downloads', {
      intermediates: true,
      idempotent: true,
    });
    const [url, , options] = download.mock.calls[0];
    expect(url).toBe('https://api.test/api/v1/bills/5/file');
    expect(options.headers.Authorization).toBe('Bearer file-token');
    expect(options.idempotent).toBe(true);
  });

  it('saves the data export as my-data.json', async () => {
    setAuthToken('t');
    download.mockImplementation((_url: string, destination: { uri: string }) => Promise.resolve(destination));
    await expect(api.exportMyData()).resolves.toBe('file:///cache/downloads/my-data.json');
    expect(download.mock.calls[0][0]).toBe('https://api.test/api/v1/me/export');
  });

  it('signs out when a download is rejected with 401', async () => {
    const onUnauthorized = jest.fn();
    setUnauthorizedHandler(onUnauthorized);
    setAuthToken('t');
    download.mockRejectedValue(new Error('Unable to download a file: response has status: 401'));

    await expect(api.exportMyData()).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).toHaveBeenCalled();
    expect(api.getAuthToken()).toBeNull();
  });

  it('reports a missing file clearly', async () => {
    setAuthToken('t');
    download.mockRejectedValue(new Error('Unable to download a file: server returned HTTP 404'));
    await expect(api.downloadBillFile(5, null)).rejects.toMatchObject({ status: 404 });
  });

  it('deletes every downloaded bill and export when asked', async () => {
    setAuthToken('t');
    download.mockImplementation((_url: string, destination: { uri: string }) => Promise.resolve(destination));
    await api.exportMyData();
    expect(mockFolder.exists).toBe(true);

    api.clearDownloads();

    expect(mockFolder.delete).toHaveBeenCalledWith('file:///cache/downloads');
    expect(mockFolder.exists).toBe(false);
  });

  it('clears downloads quietly when there is nothing to delete or deleting fails', () => {
    mockFolder.exists = false;
    mockFolder.delete.mockClear();
    api.clearDownloads();
    expect(mockFolder.delete).not.toHaveBeenCalled();

    mockFolder.exists = true;
    mockFolder.delete.mockImplementationOnce(() => {
      throw new Error('busy');
    });
    expect(() => api.clearDownloads()).not.toThrow();
  });
});

describe('API URL', () => {
  it('defaults to the Android emulator host on Android and localhost elsewhere', () => {
    expect(api.defaultApiUrl('android')).toBe('http://10.0.2.2:8000/api/v1');
    expect(api.defaultApiUrl('ios')).toBe('http://localhost:8000/api/v1');
  });

  it('prefers EXPO_PUBLIC_API_URL and trims trailing slashes', () => {
    expect(api.resolveApiUrl('https://bills.example.org/api/v1/', 'ios')).toBe('https://bills.example.org/api/v1');
    expect(api.resolveApiUrl('  ', 'android')).toBe('http://10.0.2.2:8000/api/v1');
    expect(api.resolveApiUrl(undefined, 'web')).toBe('http://localhost:8000/api/v1');
  });
});
