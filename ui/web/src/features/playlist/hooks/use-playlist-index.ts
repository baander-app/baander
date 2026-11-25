import { useQuery } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { getGetPlaylistIndexQueryKey, type PlaylistResource } from '@/shared/api-client/gen/endpoints'
import { readAllPages, type OffsetPage } from '@/shared/utils/read-all-pages'

/** The largest page the playlist list returns. */
const PLAYLIST_PAGE_LIMIT = 100

/** Every playlist the user owns or collaborates on, in the list's name order. */
export interface PlaylistIndex {
  data: PlaylistResource[]
}

/**
 * Reads the user's playlists page by page to the end. The query keeps the generated
 * index key, so playlist invalidation and live refresh reach it.
 */
export function usePlaylistIndex({ enabled = true }: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: getGetPlaylistIndexQueryKey(),
    queryFn: async ({ signal }): Promise<PlaylistIndex> => ({
      data: await readAllPages<PlaylistResource>(async (offset, limit) => {
        const response = await AXIOS_INSTANCE.get<OffsetPage<PlaylistResource>>('/api/playlists/', {
          params: { limit, offset },
          signal,
        })

        return response.data
      }, PLAYLIST_PAGE_LIMIT),
    }),
    enabled,
  })
}
