import { Stack } from 'expo-router';

import { NotFound } from '@/components/NotFound';

export default function NotFoundRoute() {
  return (
    <>
      <Stack.Screen options={{ title: 'Not found' }} />
      <NotFound />
    </>
  );
}
