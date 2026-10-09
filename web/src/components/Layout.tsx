import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router'
import { useAuth } from '../auth/context'

function Logo() {
  return (
    <Link to="/" className="flex items-center gap-2.5 rounded-md text-slate-900">
      <svg viewBox="0 0 32 32" className="size-8" aria-hidden="true">
        <rect width="32" height="32" rx="7" fill="#0f5e57" />
        <path d="M10 7h9l5 5v13a1 1 0 0 1-1 1H10a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1z" fill="#fff" />
        <path
          d="M12.5 18.5l2.5 2.5 5-5"
          fill="none"
          stroke="#0f5e57"
          strokeWidth="2.2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </svg>
      <span className="text-lg font-bold tracking-tight">Bill Auditor</span>
    </Link>
  )
}

const navLinkClass = ({ isActive }: { isActive: boolean }) =>
  `rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${
    isActive ? 'bg-brand-50 text-brand-800' : 'text-slate-700 hover:bg-slate-100'
  }`

function SkipLink() {
  return (
    <a
      href="#main"
      className="sr-only rounded-lg bg-white px-4 py-2 font-semibold text-brand-800 shadow focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50"
    >
      Skip to main content
    </a>
  )
}

/** Moves focus to the main region after client-side navigation (not on first load). */
function useFocusMainOnNavigate() {
  const location = useLocation()
  const mainRef = useRef<HTMLElement>(null)
  const firstRender = useRef(true)
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false
      return
    }
    mainRef.current?.focus()
    window.scrollTo?.(0, 0)
  }, [location.pathname])
  return mainRef
}

export function AppLayout() {
  const { user, signOut } = useAuth()
  const [signingOut, setSigningOut] = useState(false)
  const mainRef = useFocusMainOnNavigate()

  // Once signed out, RequireAuth sends the browser to /sign-in (without a
  // "come back to" page) and the sign-in page says you've signed out.
  function handleSignOut() {
    setSigningOut(true)
    void signOut()
  }

  return (
    <div className="flex min-h-screen flex-col">
      <SkipLink />
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-6">
          <Logo />
          <nav aria-label="Main" className="order-3 -mx-1 flex w-full gap-1 overflow-x-auto sm:order-2 sm:w-auto">
            <NavLink to="/" end className={navLinkClass}>
              Overview
            </NavLink>
            <NavLink to="/disputes" className={navLinkClass}>
              Disputes
            </NavLink>
            <NavLink to="/account" className={navLinkClass}>
              Account
            </NavLink>
          </nav>
          <div className="order-2 flex items-center gap-3 sm:order-3">
            {user && (
              <span className="hidden max-w-40 truncate text-sm text-slate-600 md:inline" title={user.email}>
                {user.name}
              </span>
            )}
            <button
              type="button"
              onClick={handleSignOut}
              disabled={signingOut}
              className="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-60"
            >
              {signingOut ? 'Signing out…' : 'Sign out'}
            </button>
          </div>
        </div>
      </header>
      <main id="main" ref={mainRef} tabIndex={-1} className="mx-auto w-full max-w-5xl flex-1 px-4 py-6 sm:px-6 sm:py-8">
        <Outlet />
      </main>
      <Footer />
    </div>
  )
}

function Footer() {
  return (
    <footer className="border-t border-slate-200 bg-white">
      <div className="mx-auto max-w-5xl px-4 py-5 text-sm text-slate-600 sm:px-6">
        Bill Auditor points out <em>possible</em> billing problems. It isn't legal or financial advice, and
        the municipality has the final say on your account.
      </div>
    </footer>
  )
}

/** Centered card layout for sign in and sign up. */
export function AuthLayout({ children }: { children?: ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col bg-slate-50">
      <SkipLink />
      <header className="mx-auto w-full max-w-md px-4 pt-8 sm:pt-12">
        <Logo />
      </header>
      <main id="main" tabIndex={-1} className="mx-auto w-full max-w-md flex-1 px-4 py-6">
        {children ?? <Outlet />}
      </main>
    </div>
  )
}
