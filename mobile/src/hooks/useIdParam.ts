import { useLocalSearchParams } from 'expo-router';

/** Reads a numeric route param such as /bill/[id]; returns 0 when missing or invalid. */
export function useIdParam(name = 'id'): number {
  const params = useLocalSearchParams<Record<string, string | string[]>>();
  const raw = params[name];
  const value = Number(Array.isArray(raw) ? raw[0] : raw);
  return Number.isInteger(value) && value > 0 ? value : 0;
}
