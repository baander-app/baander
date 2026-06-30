import { useQuery } from '@tanstack/react-query'
import { getLibrary } from '../api/library-api'

export function useLibrary(id: string) {
  return useQuery({
    queryKey: ['libraries', id],
    queryFn: () => getLibrary(id),
    enabled: !!id,
  })
}
