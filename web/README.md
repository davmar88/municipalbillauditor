# Municipal Bill Auditor: web app

The React web client for Municipal Bill Auditor. People add their property, upload or type in a municipal bill, see
*possible* billing problems, and prepare, send and track a dispute.

It is a thin client over the Laravel API in `../backend`. All business logic (audit rules, deadlines, letters) lives
there. The contract both sides follow is [`../docs/api.md`](../docs/api.md); this app is built strictly against it.

Stack: React 19, TypeScript, Vite, React Router, TanStack Query, Tailwind CSS v4, Vitest with React Testing Library,
ESLint.

## Setup

Requirements: Node.js 20.19+ or 22.12+ and npm.

```sh
cd web
cp .env.example .env      # optional: only needed if the API isn't on http://localhost:8000
npm ci
npm run dev               # http://localhost:5173
```

The app works with an empty backend: every screen has loading, error and empty states. The backend needs to allow
the web app's origin (for example `http://localhost:5173`) in its CORS settings for `/api/v1/*`, with the
`Authorization` header allowed.

## Environment variables

| Name | Default | What it does |
|---|---|---|
| `VITE_API_URL` | `http://localhost:8000/api/v1` | Base URL of the API, without a trailing slash. Read at build time. |

Only `.env.example` is committed. `.env` and `.env.*` are git-ignored.

## Scripts

| Command | What it does |
|---|---|
| `npm run dev` | Start the Vite dev server with hot reload. |
| `npm run build` | Type-check (`tsc -b`) and build to `dist/`. |
| `npm run preview` | Serve the production build locally. |
| `npm run typecheck` | Type-check only (`tsc -b`). |
| `npm run lint` | Run ESLint. |
| `npm test` | Run all tests once (`vitest run`). |
| `npm run test:watch` | Run tests in watch mode. |

## Project layout

```
src/
  api/          The typed API module: types.ts mirrors docs/api.md (enums, field names, nullability),
                http.ts holds the fetch wrapper, token storage and errors, endpoints.ts has one function per endpoint.
  auth/         Auth context (token + current user), sign-out on 401, route guards.
  components/   Shared UI: buttons, form fields, badges, alerts, layout, finding cards, bill and outage editors.
  hooks/        Small hooks (metros reference data, today's date).
  lib/          Formatters (money, dates), human labels for enums, form helpers, query keys.
  pages/        One component per screen.
  test/         Test setup, fixtures and a fake fetch for the API.
```

## Conventions

- **Money** is integer cents everywhere, as in the API. Amounts are shown with our own formatter, not `Intl`, so the
  output is the same in every browser: `R1 523.40`, `R0.00`, `R12 345 678.90`, `-R12.00` (normal space as thousands
  separator). People type rand amounts (`1523.40`, `1 523,40` and `R1,523.40` all work); `parseRandToCents` converts
  them to cents without floating-point maths and rejects more than two decimals.
- **Dates** are shown as `25 Sep 2026`. API dates (`YYYY-MM-DD`) are formatted without time-zone conversion;
  timestamps are shown in the viewer's local time.
- **Errors**: 422 responses are shown next to the matching field (including `line_items.N.field` keys for line
  items); anything else is summarised above the form. Any 401 clears the token and returns to sign in.
- **Language**: plain, friendly, second person. Findings are always *possible* problems, never certainties.
  Low-severity findings are labelled "For information" and don't count towards totals.

## Security and privacy notes

- **Token storage.** The Sanctum token is kept in `localStorage` so people stay signed in across reloads. The
  trade-off: any script that runs on this origin can read it, so a cross-site scripting (XSS) bug would let an
  attacker steal the token. We reduce the risk by never rendering HTML from the API (React escapes all text), not
  using `dangerouslySetInnerHTML`, loading no third-party scripts or fonts, and revoking the token on sign-out and
  account deletion. If the threat model changes, move to an httpOnly cookie session (Sanctum SPA auth) and add a
  strict Content-Security-Policy header where the app is hosted.
- **Bill files** are fetched with the `Authorization` header and opened from a temporary `blob:` URL. They are never
  linked directly.
- **POPIA.** Sign-up requires explicit consent with a short notice of what is collected and why. AI bill reading is a
  separate, optional opt-in that says the bill image goes to an AI provider outside South Africa. People can change
  that choice, download all their data (`GET /me/export`) and delete their account (`DELETE /me`) from the Account page.

## Tests

`npm test` runs:

- unit tests for the money and date formatters and the rand-to-cents parser (`src/lib/*.test.ts`)
- API client tests with a mocked `fetch`: bearer header, `{ data }` unwrapping, 422 parsing, sign-out on 401,
  multipart bill upload with `line_items` JSON-encoded (`src/api/client.test.ts`)
- component tests that render the real routes against a fake API: sign-up (POPIA consent required), bill findings
  (dismiss calls the API, low severity labelled "For information"), starting a dispute, and the dispute workflow
  (`src/**/*.test.tsx`)
