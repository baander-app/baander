import { useQuery } from '@tanstack/react-query'
import { getLibraries } from '../api/library-api'

export function useLibraries(type?: string) {
  return useQuery({
    queryKey: ['libraries', type],
    queryFn: () => getLibraries(type),
  })
}
