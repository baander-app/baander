import { useQuery } from '@tanstack/react-query'
import { getLibraryStats } from '../api/library-api'

export function useLibraryStats(id: string) {
  return useQuery({
    queryKey: ['libraries', id, 'stats'],
    queryFn: () => getLibraryStats(id),
    enabled: !!id,
    staleTime: 30_000,
  })
}
