import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { apiPath } from '@/shared/api-client/api-path'
import type { DuplicateGroup } from '@/features/admin/api/album-duplicates-api'
import { useQuery } from '@tanstack/react-query'

export function useAlbumDuplicates(albumPublicId: string | undefined) {
  return useQuery({
    queryKey: ['album-duplicates', albumPublicId],
    queryFn: ({ signal }) => {
      if (!albumPublicId) return []
      return AXIOS_INSTANCE.get<{ data: DuplicateGroup[] }>(apiPath`/api/albums/${albumPublicId}/duplicates`, { signal })
        .then((r) => r.data.data)
    },
    enabled: !!albumPublicId,
    staleTime: 60_000,
  })
}
