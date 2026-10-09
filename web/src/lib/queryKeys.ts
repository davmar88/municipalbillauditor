import type { QueryClient } from '@tanstack/react-query'

/** Every TanStack Query key in one place so invalidation stays consistent. */
export const queryKeys = {
  me: ['me'] as const,
  metros: ['metros'] as const,
  dashboard: ['dashboard'] as const,
  properties: ['properties'] as const,
  property: (id: number) => ['properties', id] as const,
  bills: (propertyId: number) => ['properties', propertyId, 'bills'] as const,
  outages: (propertyId: number) => ['properties', propertyId, 'outages'] as const,
  bill: (id: number) => ['bills', id] as const,
  disputes: ['disputes'] as const,
  dispute: (id: number) => ['disputes', id] as const,
}

/**
 * Bills, findings and disputes feed the dashboard, property counts and lists,
 * so after changing any of them we mark all of those as stale.
 */
export function invalidateMoneyData(queryClient: QueryClient): Promise<void> {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
    queryClient.invalidateQueries({ queryKey: queryKeys.properties }),
    queryClient.invalidateQueries({ queryKey: ['bills'] }),
    queryClient.invalidateQueries({ queryKey: queryKeys.disputes }),
  ]).then(() => undefined)
}
