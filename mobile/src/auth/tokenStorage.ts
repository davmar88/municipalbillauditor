import * as SecureStore from 'expo-secure-store';

/** The API token lives in the platform keychain/keystore, never in plain storage. */
const TOKEN_KEY = 'mba.auth_token';

export async function loadToken(): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(TOKEN_KEY);
  } catch {
    return null;
  }
}

export async function saveToken(token: string): Promise<void> {
  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

export async function clearToken(): Promise<void> {
  try {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
  } catch {
    // Nothing stored, or the keystore is unavailable: either way there is no token left to use.
  }
}
