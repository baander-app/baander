import { useQuery } from '@tanstack/react-query'
import { albumDuplicatesApi } from '../api/album-duplicates-api'

const DUPLICATES_KEY = ['admin-album-duplicates']

export function useAlbumDuplicates(libraryId: string | null) {
  return useQuery({
    queryKey: [...DUPLICATES_KEY, libraryId],
    queryFn: () => albumDuplicatesApi.getDuplicates(libraryId!),
    enabled: libraryId !== null,
    staleTime: 60_000,
    retry: false,
  })
}
