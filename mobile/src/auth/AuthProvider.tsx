import { useQueryClient } from '@tanstack/react-query';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Platform } from 'react-native';

import * as api from '@/api';
import { queryKeys } from '@/lib/queryKeys';

import { clearToken, loadToken, saveToken } from './tokenStorage';

export type AuthStatus = 'loading' | 'signedOut' | 'signedIn';

export type SignUpInput = Omit<api.RegisterInput, 'device_name'>;

export interface AuthContextValue {
  status: AuthStatus;
  /** True after the server rejected our token (401), so sign-in can explain why. */
  sessionExpired: boolean;
  signIn: (input: { email: string; password: string }) => Promise<void>;
  signUp: (input: SignUpInput) => Promise<void>;
  /** Revokes the token on the server (best effort) and forgets it locally. */
  signOut: () => Promise<void>;
  /** Forgets the session locally only, e.g. after the account was deleted. */
  endSession: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

const DEVICE_NAME = `Bill Auditor mobile (${Platform.OS})`;

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<AuthStatus>('loading');
  const [sessionExpired, setSessionExpired] = useState(false);
  const mounted = useRef(true);

  const forget = useCallback(
    async (expired: boolean) => {
      api.setAuthToken(null);
      // Leave the signed-in screens first, then drop their cached data, so nothing refetches in between.
      if (mounted.current) {
        setSessionExpired(expired);
        setStatus('signedOut');
      }
      await clearToken();
      queryClient.clear();
      // Downloaded bills and the data export hold account numbers and addresses.
      api.clearDownloads();
    },
    [queryClient],
  );

  useEffect(() => {
    mounted.current = true;
    api.setUnauthorizedHandler(() => {
      void forget(true);
    });
    (async () => {
      const token = await loadToken();
      if (!mounted.current) return;
      // Catches files left behind if the app was closed part-way through signing out.
      if (!token) api.clearDownloads();
      api.setAuthToken(token);
      setStatus(token ? 'signedIn' : 'signedOut');
    })();
    return () => {
      mounted.current = false;
      api.setUnauthorizedHandler(null);
    };
  }, [forget]);

  const startSession = useCallback(
    async (response: api.AuthResponse) => {
      await saveToken(response.token);
      api.setAuthToken(response.token);
      queryClient.clear();
      queryClient.setQueryData(queryKeys.me, response.user);
      setSessionExpired(false);
      setStatus('signedIn');
    },
    [queryClient],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      status,
      sessionExpired,
      signIn: async ({ email, password }) => {
        await startSession(await api.login({ email, password, device_name: DEVICE_NAME }));
      },
      signUp: async (input) => {
        await startSession(await api.register({ ...input, device_name: DEVICE_NAME }));
      },
      signOut: async () => {
        try {
          await api.logout();
        } catch {
          // Even if the server can't be reached, the token is removed from this device.
        }
        await forget(false);
      },
      endSession: () => forget(false),
    }),
    [status, sessionExpired, startSession, forget],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>.');
  return context;
}

/** Lets tests render screens with a hand-made auth value. */
export const AuthContextForTests = AuthContext;
