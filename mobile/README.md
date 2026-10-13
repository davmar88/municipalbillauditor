# RevenueGuard mobile app

The RevenueGuard phone app, built with Expo (SDK 57) and Expo Router. Screens live in `src/app`.

It is linked to the EAS project `cbf39e47-21b3-41a6-8aea-bbe20eeeff7f` through `extra.eas.projectId` in `app.json`. The slug, `revenueguard`, must match that project.

## Run it

```bash
cd mobile
npm install
npx expo start
```

Scan the QR code with Expo Go on Android, or with the Camera app on an iPhone.

## Build with EAS

Once, on your computer:

```bash
npm install --global eas-cli
eas login
cd mobile
eas init --id cbf39e47-21b3-41a6-8aea-bbe20eeeff7f   # confirms the link; it may update app.json, so commit that
```

Then, from `mobile`:

```bash
eas build --platform android --profile preview   # an APK to install on test phones
eas build --platform all --profile production    # builds for the stores
```

The store IDs are `za.co.revenueguard` (the iOS bundle identifier and the Android package). They can't change once the app is in a store, so change them in `app.json` before the first store build if you want others.

## Checks

```bash
npx expo lint
npx tsc --noEmit      # needs expo-env.d.ts, which `npx expo start` writes
npx expo-doctor
```

CI runs the lint and the type check on every pull request that touches `mobile/`.

## Next

The screens are still Expo's starter screens. RevenueGuard's own screens need an API in the Laravel app for signing in, bills and credit.
