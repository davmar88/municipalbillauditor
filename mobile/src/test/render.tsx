import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react-native';
import type { ReactElement } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AuthContextForTests, type AuthContextValue } from '@/auth/AuthProvider';

export function makeAuth(overrides: Partial<AuthContextValue> = {}): AuthContextValue {
  return {
    status: 'signedIn',
    sessionExpired: false,
    signIn: jest.fn(() => Promise.resolve()),
    signUp: jest.fn(() => Promise.resolve()),
    signOut: jest.fn(() => Promise.resolve()),
    endSession: jest.fn(() => Promise.resolve()),
    ...overrides,
  };
}

const METRICS = { frame: { x: 0, y: 0, width: 360, height: 640 }, insets: { top: 0, left: 0, right: 0, bottom: 0 } };

/** Renders with the same providers as the app, minus navigation. */
export async function renderWithProviders(
  ui: ReactElement,
  options: { auth?: AuthContextValue; queryClient?: QueryClient } = {},
) {
  const queryClient =
    options.queryClient ??
    new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } });
  const auth = options.auth ?? makeAuth();
  const result = await render(
    <SafeAreaProvider initialMetrics={METRICS}>
      <QueryClientProvider client={queryClient}>
        <AuthContextForTests.Provider value={auth}>{ui}</AuthContextForTests.Provider>
      </QueryClientProvider>
    </SafeAreaProvider>,
  );
  return { ...result, queryClient, auth };
}
