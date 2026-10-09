import { useQuery } from '@tanstack/react-query'
import { api, METROS, type Metro, type MetroCode } from '../api'
import { METRO_LABELS } from '../lib/labels'
import { queryKeys } from '../lib/queryKeys'

/** GET /metros. Reference data, so it is cached for the whole session. */
export function useMetros() {
  return useQuery({
    queryKey: queryKeys.metros,
    queryFn: api.listMetros,
    staleTime: Infinity,
  })
}

/** The metro for a code, if the server knows it. */
export function useMetro(code: MetroCode | undefined): Metro | undefined {
  const { data } = useMetros()
  if (!code) return undefined
  return data?.find((metro) => metro.code === code)
}

/** A metro's display name, from the server when available. */
export function useMetroName(code: MetroCode | undefined): string {
  const metro = useMetro(code)
  if (!code) return ''
  return metro?.name ?? METRO_LABELS[code]
}

/** Options for a metro picker: the server's list, or the contract's enum as a fallback. */
export function metroOptions(metros: Metro[] | undefined): { value: MetroCode; label: string }[] {
  if (metros && metros.length > 0) {
    return metros.map((metro) => ({ value: metro.code, label: metro.name }))
  }
  return METROS.map((code) => ({ value: code, label: METRO_LABELS[code] }))
}
