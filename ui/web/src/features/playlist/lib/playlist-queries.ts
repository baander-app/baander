import type { QueryClient } from '@tanstack/react-query'
import type { LiveQueryCoordinator } from '@/shared/live/query-coordinator'

/** Refresh every list, filter and detail variant through the same family. */
export async function invalidatePlaylistQueries(
  queryClient: QueryClient,
  coordinator?: LiveQueryCoordinator | null,
): Promise<void> {
  if (coordinator) {
    coordinator.hint(['playlists'])
    return
  }
  await queryClient.invalidateQueries({
    predicate: query => typeof query.queryKey[0] === 'string'
      && /^\/api\/playlists\/(?:[^/]+)?$/.test(query.queryKey[0]),
  })
}
