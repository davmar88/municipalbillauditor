import { router, type Href } from 'expo-router';

/** Typed links to every detail screen. */
export const routes = {
  dashboard: '/' as Href,
  disputes: '/disputes' as Href,
  account: '/account' as Href,
  signIn: '/sign-in' as Href,
  signUp: '/sign-up' as Href,
  newProperty: '/property/new' as Href,
  property: (id: number): Href => ({ pathname: '/property/[id]', params: { id: String(id) } }),
  editProperty: (id: number): Href => ({ pathname: '/property/[id]/edit', params: { id: String(id) } }),
  addBill: (propertyId: number): Href => ({ pathname: '/property/[id]/add-bill', params: { id: String(propertyId) } }),
  bill: (id: number): Href => ({ pathname: '/bill/[id]', params: { id: String(id) } }),
  dispute: (id: number): Href => ({ pathname: '/dispute/[id]', params: { id: String(id) } }),
};

/** Goes back when there is somewhere to go back to, otherwise to the fallback screen. */
export function goBackOr(fallback: Href = routes.dashboard): void {
  if (router.canGoBack()) router.back();
  else router.replace(fallback);
}
