import { Platform } from 'react-native';

/**
 * The Android emulator reaches the host machine on 10.0.2.2; everything else
 * (iOS simulator, web) can use localhost. On a physical phone, set
 * EXPO_PUBLIC_API_URL to your computer's LAN address.
 */
export function defaultApiUrl(os: string = Platform.OS): string {
  return os === 'android' ? 'http://10.0.2.2:8000/api/v1' : 'http://localhost:8000/api/v1';
}

export function resolveApiUrl(fromEnv: string | undefined, os: string = Platform.OS): string {
  const value = fromEnv?.trim() || defaultApiUrl(os);
  return value.replace(/\/+$/, '');
}

// EXPO_PUBLIC_* variables are inlined at build time, so this must be a direct property access.
export const API_URL = resolveApiUrl(process.env.EXPO_PUBLIC_API_URL);
