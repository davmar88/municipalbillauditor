import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import {
  api,
  clearToken,
  getToken,
  setToken,
  setUnauthorizedHandler,
  type AuthResponse,
} from '../api'
import { queryKeys } from '../lib/queryKeys'
import { AuthContext, type AuthContextValue, type SignedOutReason } from './context'

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [token, setTokenState] = useState<string | null>(() => getToken())
  const [signedOutReason, setSignedOutReason] = useState<SignedOutReason | null>(null)
  /** Set while signing out on purpose, so a 401 that races with it isn't treated as an expired session. */
  const signingOut = useRef(false)

  // Any 401 from the API: the client has already cleared the stored token.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      setTokenState(null)
      // A late 401 after a deliberate sign-out or deletion keeps that reason.
      setSignedOutReason((previous) => previous ?? (signingOut.current ? 'signed_out' : 'expired'))
      queryClient.clear()
    })
    return () => setUnauthorizedHandler(null)
  }, [queryClient])

  const meQuery = useQuery({
    queryKey: queryKeys.me,
    queryFn: api.getMe,
    enabled: token !== null,
    staleTime: 5 * 60 * 1000,
  })

  const signIn = useCallback(
    (auth: AuthResponse) => {
      queryClient.clear()
      setToken(auth.token)
      queryClient.setQueryData(queryKeys.me, auth.user)
      setSignedOutReason(null)
      setTokenState(auth.token)
    },
    [queryClient],
  )

  // Signed-in pages redirect to /sign-in by themselves once the token is gone
  // (see RequireAuth), and the sign-in page reads `signedOutReason`.
  const forgetSession = useCallback(
    (reason: Exclude<SignedOutReason, 'expired'>) => {
      clearToken()
      setTokenState(null)
      setSignedOutReason(reason)
      queryClient.clear()
    },
    [queryClient],
  )

  const signOut = useCallback(async () => {
    signingOut.current = true
    try {
      await api.logout()
    } catch {
      // Signing out locally is what matters; the token may already be invalid.
    } finally {
      signingOut.current = false
    }
    forgetSession('signed_out')
  }, [forgetSession])

  const value = useMemo<AuthContextValue>(
    () => ({
      token,
      user: token ? meQuery.data : undefined,
      signedOutReason,
      signIn,
      signOut,
      forgetSession,
    }),
    [token, meQuery.data, signedOutReason, signIn, signOut, forgetSession],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
