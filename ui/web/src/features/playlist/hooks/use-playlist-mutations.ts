import * as generated from '@/shared/api-client/gen/endpoints'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { getGetPlaylistShowQueryKey } from '@/shared/api-client/gen/endpoints'
import { useQueryClient } from '@tanstack/react-query'
import { useLiveCoordinator } from '@/shared/live/context'
import { assertCurrentOwnership, captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { invalidatePlaylistQueries } from '../lib/playlist-queries'

function usePlaylistOperation() {
  const coordinator = useLiveCoordinator()
  const client = useQueryClient()
  return async <T,>(operation: () => Promise<T>, forgetId?: string): Promise<T> => {
    const owner = captureOwnership()
    const release = coordinator?.beginMutation(['playlists'])
    try {
      const result = await operation()
      assertCurrentOwnership(owner)
      if (forgetId) client.removeQueries({ queryKey: getGetPlaylistShowQueryKey(forgetId) })
      return result
    } finally {
      release?.()
      if (!coordinator && isCurrentOwnership(owner)) await invalidatePlaylistQueries(client)
    }
  }
}

export function usePostPlaylistStore(options?: Parameters<typeof generated.usePostPlaylistStore>[0]) {
  const run = usePlaylistOperation()
  return generated.usePostPlaylistStore({
    ...options,
    mutation: {
      ...options?.mutation,
      mutationFn: ({ data }) => run(() => generated.postPlaylistStore(data, options?.request)),
    },
  })
}

export function useDeletePlaylistDestroy(options?: Parameters<typeof generated.useDeletePlaylistDestroy>[0]) {
  const run = usePlaylistOperation()
  return generated.useDeletePlaylistDestroy({
    ...options,
    mutation: {
      ...options?.mutation,
      mutationFn: ({ publicId }) => run(() => generated.deletePlaylistDestroy(publicId, options?.request)),
    },
  })
}

export function usePostPlaylistAddSong(options?: Parameters<typeof generated.usePostPlaylistAddSong>[0]) {
  const run = usePlaylistOperation()
  return generated.usePostPlaylistAddSong({
    ...options,
    mutation: {
      ...options?.mutation,
      mutationFn: ({ publicId, data }) => run(() => generated.postPlaylistAddSong(publicId, data, options?.request)),
    },
  })
}

export function usePatchPlaylistUpdate(options?: Parameters<typeof generated.usePatchPlaylistUpdate>[0]) {
  const run = usePlaylistOperation()
  return generated.usePatchPlaylistUpdate({
    ...options,
    mutation: {
      ...options?.mutation,
      mutationFn: ({ publicId, data }) => run(() => generated.patchPlaylistUpdate(publicId, data, options?.request)),
    },
  })
}

export function usePutPlaylistAddCollaborator(options?: Parameters<typeof generated.usePutPlaylistAddCollaborator>[0]) {
  const run = usePlaylistOperation()
  return generated.usePutPlaylistAddCollaborator({
    ...options,
    mutation: {
      ...options?.mutation,
      mutationFn: ({ publicId, userPublicId }) => run(() => generated.putPlaylistAddCollaborator(publicId, userPublicId, options?.request)),
    },
  })
}

export function useDeletePlaylistRemoveCollaborator(options?: Parameters<typeof generated.useDeletePlaylistRemoveCollaborator>[0]) {
  const run = usePlaylistOperation()
  return generated.useDeletePlaylistRemoveCollaborator({
    ...options,
    mutation: {
      ...options?.mutation,
      mutationFn: ({ publicId, userPublicId }) => run(
        () => generated.deletePlaylistRemoveCollaborator(publicId, userPublicId, options?.request),
        userPublicId === useAuthStore.getState().user?.publicId ? publicId : undefined,
      ),
    },
  })
}
