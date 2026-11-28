import { useCallback, useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import {
  getGetPartySessionIndexQueryKey,
  getPartySessionIndex,
  postActivityVideoProgress,
  type PlaybackViewingContextResource
} from '@/shared/api-client/gen/endpoints'
import { VideoViewingReporter, type ViewingAction } from '../services/video-viewing-reporter'

export function useVideoViewingReporter(
  videoId: string,
  viewing: PlaybackViewingContextResource | undefined,
  ownsPlayback: () => boolean
) {
  const client = useQueryClient()
  const reporter = useRef<VideoViewingReporter | null>(null)
  const owns = useRef(ownsPlayback)
  useEffect(() => {
    owns.current = ownsPlayback
  }, [ownsPlayback])
  const titleKind = viewing?.titleKind
  const titleId = viewing?.titleId

  useEffect(() => {
    if (!videoId || !titleKind || !titleId) return
    const current = new VideoViewingReporter(videoId, { titleKind, titleId }, {
      current: () => owns.current(),
      isWatching: async (selectedVideo, signal) => {
        const response = await client.fetchQuery({
          queryKey: getGetPartySessionIndexQueryKey(),
          queryFn: ({ signal: querySignal }) => getPartySessionIndex({
            signal: AbortSignal.any([signal, querySignal])
          }),
          staleTime: 0,
          retry: false
        })
        if (!Array.isArray(response.data)) {
          throw new Error('Party membership could not be checked.')
        }
        return response.data.some(party => party.isActive && party.videoId === selectedVideo)
      },
      report: (selectedVideo, body, signal) => postActivityVideoProgress(selectedVideo, body, { signal })
    })
    reporter.current = current
    return () => {
      current.dispose()
      if (reporter.current === current) reporter.current = null
    }
  }, [client, videoId, titleKind, titleId])

  const tick = useCallback((position: number, playing: boolean, sourceVideoId: string) => {
    if (sourceVideoId === videoId) reporter.current?.tick(position, playing)
  }, [videoId])
  const action = useCallback((action: ViewingAction, position: number, sourceVideoId: string) => {
    if (sourceVideoId === videoId) reporter.current?.action(action, position)
  }, [videoId])
  const flush = useCallback((signal?: AbortSignal) => reporter.current?.flush(signal) ?? Promise.resolve(), [])
  return { tick, action, flush }
}
