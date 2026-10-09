import { createContext, useContext } from 'react'
import type { AuthResponse, User } from '../api'

/**
 * Why the last session ended:
 * - `expired`: the server answered 401 (the person should come back to where they were);
 * - `signed_out`: the person chose to sign out;
 * - `account_deleted`: the person deleted their account.
 */
export type SignedOutReason = 'expired' | 'signed_out' | 'account_deleted'

export interface AuthContextValue {
  /** The bearer token, or null when signed out. */
  token: string | null
  /** The signed-in user once loaded from GET /me. */
  user: User | undefined
  /** Why the last session ended, or null if none has ended since the page loaded or the last sign-in. */
  signedOutReason: SignedOutReason | null
  /** Store the token and user after register/login. */
  signIn: (auth: AuthResponse) => void
  /** Revoke the token on the server (best effort) and forget it locally. */
  signOut: () => Promise<void>
  /** Forget the session locally without calling the server (e.g. after account deletion). */
  forgetSession: (reason: Exclude<SignedOutReason, 'expired'>) => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext)
  if (!value) throw new Error('useAuth must be used inside <AuthProvider>')
  return value
}
