import { useQueryClient } from '@tanstack/react-query';
import { useCallback } from 'react';

import { queryKeys } from '@/lib/queryKeys';

/**
 * Re-audits ripple through bills, findings, dashboard totals and disputes, so after a change
 * we refresh everything the user has loaded except slow-changing reference data.
 */
export function useRefreshAll() {
  const queryClient = useQueryClient();
  return useCallback(
    () =>
      queryClient.invalidateQueries({
        predicate: (query) => query.queryKey[0] !== queryKeys.metros[0],
      }),
    [queryClient],
  );
}
