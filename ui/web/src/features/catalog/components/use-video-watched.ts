import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  getGetMovieShowQueryKey,
  getTvEpisodeShowQueryKey,
  getTvSeriesEpisodesQueryKey,
  getTvSeriesShowQueryKey,
  usePutActivityWatchedUpdate,
  type PutActivityWatchedUpdateMutationVariables,
} from '@/shared/api-client/gen/endpoints'

export type WatchedTarget = Pick<PutActivityWatchedUpdateMutationVariables, 'kind' | 'publicId'>

export function useVideoWatched(seriesId?: string) {
  const queryClient = useQueryClient()
  const mutation = usePutActivityWatchedUpdate({
    mutation: {
      onSuccess: async (_, { kind, publicId }) => {
        await Promise.all([
          seriesId ? queryClient.invalidateQueries({ queryKey: getTvSeriesShowQueryKey(seriesId) }) : undefined,
          seriesId ? queryClient.invalidateQueries({ queryKey: getTvSeriesEpisodesQueryKey(seriesId) }) : undefined,
          kind === 'movie' ? queryClient.invalidateQueries({ queryKey: getGetMovieShowQueryKey(publicId) }) : undefined,
          kind === 'episode'
            ? queryClient.invalidateQueries({ queryKey: getTvEpisodeShowQueryKey(publicId) })
            : kind === 'season' || kind === 'series'
              ? queryClient.invalidateQueries({
                  predicate: ({ queryKey }) => typeof queryKey[0] === 'string' && queryKey[0].startsWith('/api/tv/episodes/'),
                })
              : undefined,
        ])
      },
      onError: () => {
        toast.error('Could not update the watched state. Try again.')
      },
    },
  })
  const pending = mutation.isPending ? mutation.variables : undefined

  return {
    pending,
    actions: (target: WatchedTarget, unavailable = false) => [true, false].map(watched => ({
      label: watched ? 'Mark as watched' : 'Mark as unwatched',
      disabled: unavailable || mutation.isPending,
      run: () => mutation.mutate({ ...target, data: { watched } }),
    })),
  }
}
