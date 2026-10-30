import { useQuery } from '@tanstack/react-query'
import { getLibraries } from '../api/library-api'
import { libraryKeys } from '../query-keys'

export function useLibraries(type?: string) {
  return useQuery({
    queryKey: libraryKeys.list(type),
    queryFn: ({ signal }) => getLibraries(type, signal),
  })
}
