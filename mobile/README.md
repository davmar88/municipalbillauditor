# Municipal Bill Auditor: mobile app

The Android and iOS app for Municipal Bill Auditor, built with [Expo](https://expo.dev) (SDK 57), React Native, TypeScript and Expo Router. It's a thin client over the Laravel API: every request follows [`docs/api.md`](../docs/api.md), and all audit rules, deadlines and letters come from the backend.

What you can do in the app:

- Create an account (with POPIA consent and a separate opt-in for AI bill reading), sign in and sign out.
- See a dashboard with your possible overcharge, money recovered, open findings, active disputes and upcoming deadlines.
- Add and edit properties, record water and electricity outages, and delete a property.
- Add a bill by taking a photo, choosing a photo or PDF, or typing in the dates, total and line items.
- See what the bill check found, dismiss or reopen findings, view the original file and re-check a bill.
- Start a dispute, edit and copy the letter, mark it as sent, log responses, escalate and close it.
- Change your name, turn AI bill reading on or off, download all your data and delete your account.

## Requirements

- Node.js 20.19 or newer, with npm.
- The backend API running somewhere the phone or emulator can reach (see `../backend`).
- One of these:
  - the **Expo Go** app (for SDK 57) on an Android or iOS phone,
  - an Android emulator (Android Studio), or
  - an iOS simulator (Xcode, macOS only).

Every native module used here ships with Expo Go, so you don't need a custom development build.

## Setup

```bash
cd mobile
npm ci
cp .env.example .env   # then edit EXPO_PUBLIC_API_URL if needed
```

### Environment variables

| Variable | Default | What it's for |
|---|---|---|
| `EXPO_PUBLIC_API_URL` | `http://10.0.2.2:8000/api/v1` on Android, `http://localhost:8000/api/v1` elsewhere | Base URL of the API, including `/api/v1`. |

`10.0.2.2` is how the Android emulator reaches your computer. On a **physical phone**, use your computer's LAN address instead, for example `EXPO_PUBLIC_API_URL=http://192.168.1.20:8000/api/v1`, and start the backend so it listens on your network (`php artisan serve --host=0.0.0.0 --port=8000`).

`EXPO_PUBLIC_*` values are built into the JavaScript bundle, so never put secrets in them. Restart `npx expo start` after changing `.env`.

## Running the app

```bash
npx expo start
```

Then:

- **Phone with Expo Go:** scan the QR code (Camera app on iOS, Expo Go on Android). The phone and computer must be on the same network, or use `npx expo start --tunnel`.
- **Android emulator:** start the emulator, then press `a`.
- **iOS simulator:** press `i` (macOS only).

The app talks to the API over plain HTTP in development, which Expo Go allows. Use HTTPS for anything beyond local development.

## Scripts

| Command | What it does |
|---|---|
| `npm start` | Starts the Expo dev server. |
| `npm run android` / `npm run ios` | Starts the dev server and opens the app on an emulator or simulator. |
| `npm test` | Runs the Jest test suite once (jest-expo + React Native Testing Library). |
| `npm run test:watch` | Runs tests in watch mode. |
| `npm run typecheck` | Type-checks with `tsc --noEmit`. |
| `npm run lint` | Lints with ESLint via `expo lint`. |
| `npm run format` / `npm run format:check` | Formats or checks formatting with Prettier. |
| `npx expo-doctor` | Checks dependency versions and project config. Two of its checks need the Expo API and React Native Directory online. |
| `npx expo export --platform android` | Builds the production JavaScript bundle, which is a quick way to check that everything compiles. |

## How the code is organised

```
src/
  app/          Expo Router routes only (thin files that render a screen)
    (tabs)/     Dashboard, Disputes and Account tabs
    property/   add, view, edit a property; add a bill
    bill/       bill detail
    dispute/    dispute detail
  screens/      one component per screen
  components/   shared UI (components/ui) and feature components
  api/          the typed API module: types.ts mirrors docs/api.md, endpoints.ts has one function per endpoint
  auth/         token storage (expo-secure-store) and the auth provider
  hooks/        TanStack Query hooks
  lib/          money and date formatting, labels, form helpers
  theme.ts      colours, spacing and sizes
```

A few conventions:

- **Money** is integer cents everywhere. `lib/money.ts` formats it as `R1 523.40` (a normal space between thousands, `-R12.00` for negatives) and parses what people type (`1523.40`, `1 523,40`, `R1,523.40`) back to cents. It doesn't use `Intl`, so output is the same on every phone.
- **Dates** show as `25 Sep 2026`. People can type `25/09/2026`, `2026-09-25` or `25 Sep 2026`.
- **Readings and usage** accept `1203.5`, `1 203,5` or `12,345.6`. A lone comma before three digits (`12,345`) could mean 12345 or 12.345, so the app asks instead of guessing. Each field shows how it was read. Usage follows the two readings until you type your own, so changing a reading never saves an old usage.
- **Photos** from the iPhone library are requested as JPEG rather than HEIC, because AI bill reading can't open HEIC. Uploads are labelled with the type of the file the picker actually saved.
- **Keyboard:** Android apps draw edge to edge, so the window no longer shrinks for the keyboard. `Screen` pads itself by the part the keyboard covers on Android; iOS uses the scroll view's keyboard insets.
- **Server state** lives in TanStack Query. After any change, the app refreshes loaded data because a re-audit can change bills, findings, totals and deadlines at once.
- **Errors:** a `422` shows each message next to its field, and any `401` clears the token and returns to sign-in.
- **Wording** is plain and in the second person. Findings are always *possible* problems, never certain ones.

## Privacy

- The API token is kept in the device's secure keystore (`expo-secure-store`), never in plain storage.
- Bill files and the data export are downloaded with the token in the `Authorization` header to a `downloads` folder in the app's cache, then opened with the system share sheet. That folder is deleted when you sign out, when your session expires and when you delete your account.
- The camera permission is requested only when you tap **Take photo**. If it's refused, the app explains how to allow it or choose a file instead.
- The app doesn't log bill contents, account numbers or tokens.
