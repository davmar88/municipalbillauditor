export const queryKeys = {
  me: ['me'] as const,
  metros: ['metros'] as const,
  dashboard: ['dashboard'] as const,
  properties: ['properties'] as const,
  property: (id: number) => ['properties', id] as const,
  propertyBills: (id: number) => ['properties', id, 'bills'] as const,
  propertyOutages: (id: number) => ['properties', id, 'outages'] as const,
  bills: ['bills'] as const,
  bill: (id: number) => ['bills', id] as const,
  disputes: ['disputes'] as const,
  dispute: (id: number) => ['disputes', id] as const,
};
