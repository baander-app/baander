import { useCallback, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getLibraries } from '../api/library-api'
import { libraryKeys } from '../query-keys'

/** The fallback scan observer shares the authoritative library list. */
export function useScanPolling(intervalMs = 3000) {
  const [enabled, setEnabled] = useState(false)
  const { data, refetch } = useQuery({
    queryKey: libraryKeys.list(),
    queryFn: ({ signal }) => getLibraries(undefined, signal),
    enabled,
    refetchInterval: enabled ? intervalMs : false,
  })
  const scanningIds = new Set(
    data?.filter(library => library.scanStatus === 'scanning').map(library => library.publicId),
  )
  const startPolling = useCallback(() => setEnabled(true), [])
  const stopPolling = useCallback(() => setEnabled(false), [])
  return { scanningIds, startPolling, stopPolling, refresh: refetch }
}
