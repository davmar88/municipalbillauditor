/* Shared test setup for jest-expo. */

// Use React Native's own FormData (not Node's) so multipart tests see exactly what the app
// sends on a device, including file parts given as { uri, name, type }.
// eslint-disable-next-line @typescript-eslint/no-require-imports
const ReactNativeFormData = require('react-native/Libraries/Network/FormData').default;
globalThis.FormData = ReactNativeFormData;
