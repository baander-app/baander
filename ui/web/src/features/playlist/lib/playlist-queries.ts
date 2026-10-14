import type { QueryClient } from '@tanstack/react-query'
import {
  getGetPlaylistIndexQueryKey,
  getGetPlaylistShowQueryKey,
} from '@/shared/api-client/gen/endpoints'

/** Refetches the list and the one playlist after a change to either. */
export async function invalidatePlaylistQueries(queryClient: QueryClient, publicId: string): Promise<void> {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: getGetPlaylistShowQueryKey(publicId) }),
    queryClient.invalidateQueries({ queryKey: getGetPlaylistIndexQueryKey() }),
  ])
}

/** Forgets one playlist the viewer can no longer read, and refetches the list. */
export async function forgetPlaylistQueries(queryClient: QueryClient, publicId: string): Promise<void> {
  queryClient.removeQueries({ queryKey: getGetPlaylistShowQueryKey(publicId) })
  await queryClient.invalidateQueries({ queryKey: getGetPlaylistIndexQueryKey() })
}
