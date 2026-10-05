import { useCallback, useEffect, useRef, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { getCurrentTime, subscribe as subscribeToTime } from '@/features/player/stores/player-time-tracker'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { mediator } from '@/shared/lib/mediator/bus'
import { PLAYER_ACTIONS } from '@/features/player/player-actions'
import { getDeviceId, getDeviceName } from '../utils/device-id'
import { SessionSyncBus } from '../services/SessionSyncBus'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('Session')

export interface SessionData {
  id: string
  userId: string
  activeDeviceId: string | null
  queue: string[]
  currentTrackIndex: number
  position: number
  playbackState: 'playing' | 'paused' | 'stopped'
  createdAt: string
  updatedAt: string
  lastUsedAt: string | null
}

const SESSION_KEY = ['session', 'current']
const SYNC_DEBOUNCE_MS = 2000

export function useSession() {
  const queryClient = useQueryClient()
  const deviceId = getDeviceId()
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const sessionSyncBus = useRef<SessionSyncBus | null>(null)
  const deviceRegistered = useRef(false)

  const [dismissedTransfer, setDismissedTransfer] = useState<{ sessionId: string; deviceId: string } | null>(null)

  const query = useQuery({
    queryKey: SESSION_KEY,
    queryFn: async (): Promise<SessionData | null> => {
      try {
        const res = await AXIOS_INSTANCE.get('/api/session')
        return res.data?.data ?? res.data ?? null
      } catch (err) {
        // 404 means no session exists yet (first-time user) — treat as null, not an error.
        // Any other failure (network, 5xx) propagates so query.error reflects real problems.
        const status = (err as { response?: { status?: number } })?.response?.status
        if (status === 404) return null
        throw err
      }
    },
    staleTime: 30_000,
  })

  const pendingSession = query.data?.activeDeviceId && query.data.activeDeviceId !== deviceId ? query.data : null
  const showTransferPrompt = pendingSession !== null && !(dismissedTransfer?.sessionId === pendingSession.id
    && dismissedTransfer.deviceId === pendingSession.activeDeviceId)

  // Register this device once on mount
  useEffect(() => {
    if (deviceRegistered.current) return
    deviceRegistered.current = true
    AXIOS_INSTANCE.post('/api/devices', {
      deviceId,
      name: getDeviceName(),
    }).catch((err) => { logger.warn('Device registration failed:', err) })
  }, [deviceId])

  // Auto-create session when playback starts and no session exists
  const [sessionCreated, setSessionCreated] = useState(false)
  useEffect(() => {
    if (query.data || sessionCreated) return

    const unsub = usePlayerStore.subscribe((state, prev) => {
      if (!state.isPlaying || prev.isPlaying) return
      if (query.data || sessionCreated) return

      setSessionCreated(true)
      const { queue, currentIndex } = usePlayerStore.getState()
      const currentTime = getCurrentTime()
      const trackIds = queue.map(t => t.publicId)
      AXIOS_INSTANCE.post('/api/session/new', {
        queue: trackIds,
        currentTrackIndex: currentIndex,
        position: currentTime,
      }).then(res => {
        queryClient.setQueryData(SESSION_KEY, res.data?.data ?? res.data)
      }).catch((err) => {
        logger.warn('Session creation failed:', err)
        setSessionCreated(false) // retry on next play
      })
    })
    return unsub
  }, [query.data, sessionCreated, queryClient])

  // On session load, either show transfer prompt or hydrate queue
  useEffect(() => {
    const session = query.data
    if (!session) return
    if (session.activeDeviceId && session.activeDeviceId !== deviceId) return

    // This device is active — hydrate server queue into player store if empty
    const localQueue = usePlayerStore.getState().queue
    if (session.activeDeviceId === deviceId && localQueue.length === 0 && session.queue.length > 0) {
      AXIOS_INSTANCE.get('/api/songs/', {
        params: { publicIds: session.queue.join(','), limit: session.queue.length }
      }).then(res => {
        const songs = res.data?.data ?? []
        const songMap = new Map<string, Record<string, unknown>>(songs.map((s: Record<string, unknown>) => [s.publicId as string, s]))
        const tracks: Track[] = session.queue
          .filter(Boolean)
          .map((id: string) => {
            const s = songMap.get(id)
            if (!s) return null
            return {
              publicId: s.publicId as string,
              title: s.title as string,
              artistName: (s.artistName as string) ?? undefined,
              albumName: (s.albumName as string) ?? undefined,
              albumPublicId: (s.albumId as string) ?? undefined,
              duration: (s.length as number) ?? undefined,
            }
          })
          .filter(Boolean) as Track[]

        if (tracks.length > 0) {
          mediator.dispatch(PLAYER_ACTIONS.STATE_RESTORE, {
            queue: tracks,
            currentIndex: session.currentTrackIndex,
            currentTime: session.position,
          }, 'session')
        }
      }).catch((err) => { logger.warn('Queue hydration failed:', err) })
    }
  }, [query.data, deviceId])

  // Initialize SessionSyncBus when the session id changes. Keying on the stable id (not the
  // whole query.data object) avoids disconnecting/reconnecting the socket — and resetting the
  // reconnect backoff — on every setQueryData/staleTime refetch that produces a new object.
  const sessionId = query.data?.id ?? null
  useEffect(() => {
    if (!sessionId) return

    const accessToken = useAuthStore.getState().accessToken
    const bus = new SessionSyncBus({
      wsEndpoint: '/api/ws',
      authToken: accessToken ?? undefined,
      deviceId,
      getPosition: getCurrentTime,
      getQueue: () => usePlayerStore.getState().queue.map(t => t.publicId),
      getCurrentIndex: () => usePlayerStore.getState().currentIndex,
      getIsPlaying: () => usePlayerStore.getState().isPlaying,
    }, {
      onStateUpdate: (state) => {
        const local = usePlayerStore.getState()
        const localQueueIds = local.queue.map(t => t.publicId)
        const queueMatch = state.queue.length === localQueueIds.length &&
          state.queue.every((id: string, i: number) => id === localQueueIds[i])

        if (!queueMatch) {
          // Different queue — full state restore via batch resolve.
          // Read the latest session from the cache rather than the closure capture so a
          // refetched session is used without forcing a socket reconnect.
          const session = queryClient.getQueryData(SESSION_KEY) as SessionData | undefined
          if (session && session.queue.length > 0) {
            AXIOS_INSTANCE.get('/api/songs/', {
              params: { publicIds: session.queue.join(','), limit: session.queue.length }
            }).then(res => {
              const songs = res.data?.data ?? []
              const songMap = new Map<string, Record<string, unknown>>(songs.map((s: Record<string, unknown>) => [s.publicId as string, s]))
              const tracks: Track[] = session.queue
                .filter(Boolean)
                .map((id: string) => {
                  const s = songMap.get(id)
                  if (!s) return null
                  return {
                    publicId: s.publicId as string,
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
                currentIndex: state.currentTrackIndex,
                currentTime: state.position,
              }, 'session')
            }).catch((err) => { logger.warn('WS state restore failed:', err) })
          }
          return
        }

        const SYNC_TOLERANCE = 4.0 // seconds
        const localPos = getCurrentTime()
        if (Math.abs(localPos - state.position) < SYNC_TOLERANCE) {
          // Same track, within tolerance — no action needed (gapless resume)
          return
        }
        // Significant drift — seek to server position (handles long disconnections)
        local.seekTo(state.position)
      },
      onReconnect: () => {
        bus.sendSync()
      },
      onError: (err) => {
        logger.warn('[useSession] WS error:', err.message)
      },
    })

    sessionSyncBus.current = bus
    bus.connect(sessionId)

    return () => {
      bus.disconnect()
      if (sessionSyncBus.current === bus) sessionSyncBus.current = null
    }
  }, [sessionId, deviceId, queryClient])

  const claimMutation = useMutation({
    mutationFn: async (): Promise<SessionData> => {
      const res = await AXIOS_INSTANCE.post('/api/session/claim', { deviceId })
      return res.data?.data ?? res.data
    },
    // NOTE: onSuccess is async but React Query does not await it — the batch lookup
    // fires and forgets. The try/catch ensures the player store always gets a
    // STATE_RESTORE dispatch even if hydration fails.
    onSuccess: async (data) => {
      queryClient.setQueryData(SESSION_KEY, data)
      setDismissedTransfer(null)

      if (data.queue.length > 0) {
        // Hydrate server queue (publicIds) into Track[] via batch lookup
        try {
          const res = await AXIOS_INSTANCE.get('/api/songs/', {
            params: { publicIds: data.queue.join(','), limit: data.queue.length }
          })
          // CursorPaginatedResponse envelope: { data: [...songs], meta: {...} }
          const songs = res.data?.data ?? []
          const songMap = new Map<string, Record<string, unknown>>(songs.map((s: Record<string, unknown>) => [s.publicId as string, s]))
          const tracks: Track[] = data.queue
            .filter(Boolean)
            .map((id: string) => {
              const s = songMap.get(id)
              if (!s) return null
              return {
                publicId: s.publicId as string,
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
          mediator.dispatch(PLAYER_ACTIONS.STATE_RESTORE, {
            queue: [],
            currentIndex: 0,
            currentTime: 0,
          }, 'session')
        }
      }
    },
  })

  // "Bring local queue" option in claim flow
  const claimWithQueueMutation = useMutation({
    mutationFn: async (): Promise<SessionData> => {
      const { queue, currentIndex } = usePlayerStore.getState()
      const currentTime = getCurrentTime()
      const trackIds = queue.map(t => t.publicId)
      const res = await AXIOS_INSTANCE.post('/api/session/claim', {
        deviceId,
        queue: trackIds,
        currentTrackIndex: currentIndex,
        position: currentTime,
      })
      return res.data?.data ?? res.data
    },
    onSuccess: (data) => {
      queryClient.setQueryData(SESSION_KEY, data)
      setDismissedTransfer(null)
    },
  })

  const newMutation = useMutation({
    mutationFn: async (): Promise<SessionData> => {
      const { queue, currentIndex } = usePlayerStore.getState()
      const currentTime = getCurrentTime()
      const trackIds = queue.map((t) => t.publicId)
      const res = await AXIOS_INSTANCE.post('/api/session/new', {
        queue: trackIds,
        currentTrackIndex: currentIndex,
        position: currentTime,
      })
      return res.data?.data ?? res.data
    },
    onSuccess: (data) => {
      queryClient.setQueryData(SESSION_KEY, data)
      setDismissedTransfer(null)
    },
  })

  // Replace REST sync with WS-primary
  const syncToServer = useCallback(() => {
    const { queue, currentIndex, isPlaying } = usePlayerStore.getState()
    const currentTime = getCurrentTime()
    const isActive = query.data?.activeDeviceId === deviceId

    if (!isActive) return // Client-side sync gate

    if (sessionSyncBus.current?.isConnected()) {
      sessionSyncBus.current.sendSync()
    } else {
      // REST fallback
      const trackIds = queue.map((t) => t.publicId)
      AXIOS_INSTANCE.put('/api/session', {
        queue: trackIds,
        currentTrackIndex: currentIndex,
        position: currentTime,
        playbackState: isPlaying ? 'playing' : 'paused',
      }, {
        headers: { 'X-Baander-Device-Id': deviceId },
      }).catch((err) => { logger.warn('REST session sync failed:', err) })
    }
  }, [deviceId, query.data?.activeDeviceId])

  // A pending deadline is never extended by playback ticks: continuous playback syncs too.
  useEffect(() => {
    const scheduleSync = () => {
      if (debounceRef.current !== null) return
      debounceRef.current = setTimeout(() => {
        debounceRef.current = null
        syncToServer()
      }, SYNC_DEBOUNCE_MS)
    }
    const unsubPlayer = usePlayerStore.subscribe((state, previous) => {
      if (state.queue !== previous.queue || state.currentIndex !== previous.currentIndex
        || state.isPlaying !== previous.isPlaying) scheduleSync()
    })
    const unsubTime = subscribeToTime(scheduleSync)
    return () => {
      unsubPlayer()
      unsubTime()
      if (debounceRef.current !== null) clearTimeout(debounceRef.current)
      debounceRef.current = null
    }
  }, [syncToServer])

  function dismissTransfer() {
    if (pendingSession?.activeDeviceId) {
      setDismissedTransfer({ sessionId: pendingSession.id, deviceId: pendingSession.activeDeviceId })
    }
  }

  return {
    session: query.data,
    isLoading: query.isLoading,
    showTransferPrompt,
    pendingSession,
    claim: claimMutation.mutate,
    claimWithQueue: claimWithQueueMutation.mutate,
    newSession: newMutation.mutate,
    dismissTransfer,
    isClaiming: claimMutation.isPending,
    isCreating: newMutation.isPending,
  }
}
