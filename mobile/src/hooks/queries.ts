import { useQuery } from '@tanstack/react-query';

import * as api from '@/api';
import { queryKeys } from '@/lib/queryKeys';

export function useMe() {
  return useQuery({ queryKey: queryKeys.me, queryFn: api.getMe });
}

export function useMetros() {
  return useQuery({ queryKey: queryKeys.metros, queryFn: api.listMetros, staleTime: 60 * 60 * 1000 });
}

/** The metro record for a code, once metros have loaded. */
export function useMetro(code: api.MetroCode | undefined) {
  const metros = useMetros();
  return { ...metros, metro: code ? metros.data?.find((m) => m.code === code) : undefined };
}

export function useDashboard() {
  return useQuery({ queryKey: queryKeys.dashboard, queryFn: api.getDashboard });
}

export function useProperties() {
  return useQuery({ queryKey: queryKeys.properties, queryFn: api.listProperties });
}

export function useProperty(id: number) {
  return useQuery({ queryKey: queryKeys.property(id), queryFn: () => api.getProperty(id), enabled: id > 0 });
}

export function usePropertyBills(id: number) {
  return useQuery({ queryKey: queryKeys.propertyBills(id), queryFn: () => api.listBills(id), enabled: id > 0 });
}

export function usePropertyOutages(id: number) {
  return useQuery({ queryKey: queryKeys.propertyOutages(id), queryFn: () => api.listOutages(id), enabled: id > 0 });
}

export function useBill(id: number) {
  return useQuery({
    queryKey: queryKeys.bill(id),
    queryFn: () => api.getBill(id),
    enabled: id > 0,
    // While the AI is reading the bill, check back every few seconds.
    refetchInterval: (query) => (query.state.data?.status === 'extracting' ? 3000 : false),
  });
}

export function useDisputes() {
  return useQuery({ queryKey: queryKeys.disputes, queryFn: api.listDisputes });
}

export function useDispute(id: number) {
  return useQuery({ queryKey: queryKeys.dispute(id), queryFn: () => api.getDispute(id), enabled: id > 0 });
}
