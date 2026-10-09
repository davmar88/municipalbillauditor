import { Navigate, Outlet, useLocation } from 'react-router'
import { useAuth } from './context'

/**
 * Only signed-in people can see these routes; everyone else goes to sign in.
 * Sign in brings people back to the page they asked for, except after they
 * signed out or deleted their account on purpose: the next person to sign in
 * on this browser should start at the overview.
 */
export function RequireAuth() {
  const { token, signedOutReason } = useAuth()
  const location = useLocation()
  if (!token) {
    const deliberate = signedOutReason === 'signed_out' || signedOutReason === 'account_deleted'
    return <Navigate to="/sign-in" replace state={deliberate ? null : { from: location.pathname }} />
  }
  return <Outlet />
}

/** The in-app page to return to after signing in, from RequireAuth's redirect state. */
function returnPath(state: unknown): string {
  const from = state && typeof state === 'object' && 'from' in state ? state.from : undefined
  if (typeof from !== 'string' || !from.startsWith('/') || from.startsWith('//')) return '/'
  if (from === '/sign-in' || from === '/sign-up') return '/'
  return from
}

/**
 * Sign in / sign up are pointless once signed in. This is also what moves
 * people on after they sign in: back to the page RequireAuth sent them away
 * from (only set when a session expired), otherwise the overview.
 */
export function PublicOnly() {
  const { token } = useAuth()
  const location = useLocation()
  if (token) return <Navigate to={returnPath(location.state)} replace />
  return <Outlet />
}
