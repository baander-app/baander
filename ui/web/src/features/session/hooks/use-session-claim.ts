import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useLiveCoordinator } from '@/shared/live/context'
import { assertCurrentOwnership, captureOwnership } from '@/shared/live/ownership'
import { mediator } from '@/shared/lib/mediator/bus'
import { PLAYER_ACTIONS } from '@/features/player/player-actions'
import type { Track } from '@/features/player/stores/player-store'
import { getDeviceId } from '../utils/device-id'
import type { SessionData } from './use-session'

export async function getSessionQueueSongs(publicIds: string[]): Promise<Record<string, unknown>[]> {
  const ownership = captureOwnership()
  const ids = [...new Set(publicIds)]
  const batches: string[][] = []
  for (let offset = 0; offset < ids.length; offset += 100) batches.push(ids.slice(offset, offset + 100))
  const responses = await Promise.all(batches.map((batch) => AXIOS_INSTANCE.get<{ data: Record<string, unknown>[] }>('/api/songs/', {
    params: { publicIds: batch.join(','), limit: batch.length },
  })))
  assertCurrentOwnership(ownership)
  return responses.flatMap((response) => response.data.data ?? [])
}

export function useSessionClaim(onClaim?: () => void) {
  const queryClient = useQueryClient()
  const coordinator = useLiveCoordinator()
  return useMutation({
    mutationFn: async (): Promise<SessionData> => {
      const ownership = captureOwnership()
      const release = coordinator?.beginMutation(['sessions'])
      try {
        const response = await AXIOS_INSTANCE.post('/api/session/claim', { deviceId: getDeviceId() })
        assertCurrentOwnership(ownership)
        const data: SessionData = response.data?.data ?? response.data
        queryClient.setQueryData(['session', 'current'], data)
        if (data.queue.length > 0) {
          try {
            const songs = await getSessionQueueSongs(data.queue)
            assertCurrentOwnership(ownership)
            const songMap = new Map<string, Record<string, unknown>>(songs.map((s: Record<string, unknown>) => [s.publicId as string, s]))
            const tracks: Track[] = data.queue
              .filter(Boolean)
              .map((id: string) => {
                const s = songMap.get(id)
                if (!s) return null
                return {
                  publicId: s.publicId as string,
                  available: s.available === true,
                  title: s.title as string,
                  artistName: (s.artistName as string) ?? undefined,
                  albumName: (s.albumName as string) ?? undefined,
                  albumPublicId: (s.albumId as string) ?? undefined,
                  duration: (s.length as number) ?? undefined,
                }
              })
              .filter(Boolean) as Track[]

            mediator.dispatch(PLAYER_ACTIONS.STATE_RESTORE, {
              queue: tracks,
              currentIndex: data.currentTrackIndex,
              currentTime: data.position,
            }, 'session')
          } catch {
            assertCurrentOwnership(ownership)
            mediator.dispatch(PLAYER_ACTIONS.STATE_RESTORE, {
              queue: [],
              currentIndex: 0,
              currentTime: 0,
            }, 'session')
          }
        }

        return data
      } finally {
        release?.()
      }
    },
    onSuccess: onClaim,
  })
}
