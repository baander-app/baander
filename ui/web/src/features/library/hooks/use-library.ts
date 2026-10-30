import { useQuery } from '@tanstack/react-query'
import { getLibrary } from '../api/library-api'
import { libraryKeys } from '../query-keys'

export function useLibrary(id: string) {
  return useQuery({
    queryKey: libraryKeys.detail(id),
    queryFn: ({ signal }) => getLibrary(id, signal),
    enabled: !!id,
  })
}
