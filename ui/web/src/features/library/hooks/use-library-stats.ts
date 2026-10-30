import { useQuery } from '@tanstack/react-query'
import { getLibraryStats } from '../api/library-api'
import { libraryKeys } from '../query-keys'

export function useLibraryStats(id: string) {
  return useQuery({
    queryKey: libraryKeys.stats(id),
    queryFn: ({ signal }) => getLibraryStats(id, signal),
    enabled: !!id,
    staleTime: 30_000,
  })
}
