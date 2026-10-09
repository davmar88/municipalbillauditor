import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen, waitFor } from '@testing-library/react-native';
import { useEffect } from 'react';
import { Text } from 'react-native';

import * as api from '@/api';
import { makeUser } from '@/test/fixtures';

import { AuthProvider, useAuth } from '../AuthProvider';
import * as tokenStorage from '../tokenStorage';

jest.mock('@/api/client', () => ({
  ...jest.requireActual('@/api/client'),
  clearDownloads: jest.fn(),
}));

jest.mock('../tokenStorage', () => ({
  loadToken: jest.fn(),
  saveToken: jest.fn(() => Promise.resolve()),
  clearToken: jest.fn(() => Promise.resolve()),
}));

const storage = tokenStorage as jest.Mocked<typeof tokenStorage>;
const handle: { current: ReturnType<typeof useAuth> | null } = { current: null };

function Probe() {
  const auth = useAuth();
  useEffect(() => {
    handle.current = auth;
  });
  return <Text>{`status:${auth.status} expired:${String(auth.sessionExpired)}`}</Text>;
}

async function renderProvider() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  await render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <Probe />
      </AuthProvider>
    </QueryClientProvider>,
  );
  return queryClient;
}

function response(status: number, body?: unknown): Response {
  return {
    ok: status < 300,
    status,
    text: () => Promise.resolve(body === undefined ? '' : JSON.stringify(body)),
  } as Response;
}

beforeEach(() => {
  jest.clearAllMocks();
  api.setAuthToken(null);
  api.setBaseUrl('https://api.test/api/v1');
  globalThis.fetch = jest.fn() as unknown as typeof fetch;
});

describe('AuthProvider', () => {
  it('restores a saved session from the secure store', async () => {
    storage.loadToken.mockResolvedValue('saved-token');
    await renderProvider();
    expect(await screen.findByText('status:signedIn expired:false')).toBeOnTheScreen();
    expect(api.getAuthToken()).toBe('saved-token');
  });

  it('starts signed out without a saved token', async () => {
    storage.loadToken.mockResolvedValue(null);
    await renderProvider();
    expect(await screen.findByText('status:signedOut expired:false')).toBeOnTheScreen();
    // Anything left from an interrupted sign-out is removed.
    expect(api.clearDownloads).toHaveBeenCalled();
  });

  it('keeps downloads while a saved session is restored', async () => {
    storage.loadToken.mockResolvedValue('saved-token');
    await renderProvider();
    await screen.findByText('status:signedIn expired:false');
    expect(api.clearDownloads).not.toHaveBeenCalled();
  });

  it('deletes downloaded bills and the data export when you sign out or delete your account', async () => {
    storage.loadToken.mockResolvedValue('token');
    (globalThis.fetch as jest.Mock).mockResolvedValue(response(204));
    await renderProvider();
    await screen.findByText('status:signedIn expired:false');

    await act(() => handle.current!.signOut());
    expect(api.clearDownloads).toHaveBeenCalledTimes(1);

    await act(() => handle.current!.endSession());
    expect(api.clearDownloads).toHaveBeenCalledTimes(2);
  });

  it('saves the token after signing in and seeds the user', async () => {
    storage.loadToken.mockResolvedValue(null);
    (globalThis.fetch as jest.Mock).mockResolvedValue(response(200, { token: 'new-token', user: makeUser() }));
    const queryClient = await renderProvider();
    await screen.findByText('status:signedOut expired:false');

    await act(() => handle.current!.signIn({ email: 'thandi@example.com', password: 'secret123' }));

    expect(storage.saveToken).toHaveBeenCalledWith('new-token');
    expect(api.getAuthToken()).toBe('new-token');
    expect(queryClient.getQueryData(['me'])).toEqual(makeUser());
    expect(screen.getByText('status:signedIn expired:false')).toBeOnTheScreen();
    const body = JSON.parse((globalThis.fetch as jest.Mock).mock.calls[0][1].body);
    expect(body).toMatchObject({ email: 'thandi@example.com', password: 'secret123' });
  });

  it('clears the token and signs out when any request returns 401', async () => {
    storage.loadToken.mockResolvedValue('stale-token');
    const queryClient = await renderProvider();
    await screen.findByText('status:signedIn expired:false');
    queryClient.setQueryData(['dashboard'], { cached: true });
    (globalThis.fetch as jest.Mock).mockResolvedValue(response(401, { message: 'Unauthenticated.' }));

    await act(async () => {
      await api.getDashboard().catch(() => undefined);
    });

    await waitFor(() => expect(screen.getByText('status:signedOut expired:true')).toBeOnTheScreen());
    expect(storage.clearToken).toHaveBeenCalled();
    expect(api.getAuthToken()).toBeNull();
    expect(queryClient.getQueryData(['dashboard'])).toBeUndefined();
    await waitFor(() => expect(api.clearDownloads).toHaveBeenCalled());
  });

  it('signs out locally even if the server cannot be reached', async () => {
    storage.loadToken.mockResolvedValue('token');
    await renderProvider();
    await screen.findByText('status:signedIn expired:false');
    (globalThis.fetch as jest.Mock).mockRejectedValue(new TypeError('Network request failed'));

    await act(() => handle.current!.signOut());

    expect(storage.clearToken).toHaveBeenCalled();
    expect(screen.getByText('status:signedOut expired:false')).toBeOnTheScreen();
  });
});
