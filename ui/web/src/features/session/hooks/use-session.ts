import { useCallback, useEffect, useRef, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
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

  const [showTransferPrompt, setShowTransferPrompt] = useState(false)
  const [pendingSession, setPendingSession] = useState<SessionData | null>(null)

  const query = useQuery({
    queryKey: SESSION_KEY,
    queryFn: async (): Promise<SessionData | null> => {
      try {
        const res = await AXIOS_INSTANCE.get('/api/session')
        return res.data?.data ?? res.data ?? null
      } catch {
        return null
      }
    },
    staleTime: 30_000,
  })

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
      const { queue, currentIndex, currentTime } = usePlayerStore.getState()
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
    if (session.activeDeviceId && session.activeDeviceId !== deviceId) {
      setPendingSession(session)
      setShowTransferPrompt(true)
      return
    }

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

  // Initialize SessionSyncBus when session loads
  useEffect(() => {
    if (!query.data) return

    const accessToken = useAuthStore.getState().accessToken
    const bus = new SessionSyncBus({
      wsEndpoint: '/api/ws',
      authToken: accessToken ?? undefined,
      deviceId,
      getPosition: () => usePlayerStore.getState().currentTime,
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
          // Different queue — full state restore via batch resolve
          const session = query.data
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
        const localPos = local.currentTime
        if (Math.abs(localPos - state.position) < SYNC_TOLERANCE) {
          // Same track, within tolerance — no action needed (gapless resume)
          return
        }
        // Significant drift — seek to server position (handles long disconnections)
        const audio = usePlayerStore.getState().audioElement
        if (audio) {
          audio.currentTime = state.position
        }
      },
      onReconnect: () => {
        bus.sendSync()
      },
      onError: (err) => {
        logger.warn('[useSession] WS error:', err.message)
      },
    })

    sessionSyncBus.current = bus
    bus.connect(query.data.id)

    return () => { bus.disconnect() }
  }, [query.data, deviceId])

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
      setShowTransferPrompt(false)
      setPendingSession(null)

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
      const { queue, currentIndex, currentTime } = usePlayerStore.getState()
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
      setShowTransferPrompt(false)
      setPendingSession(null)
    },
  })

  const newMutation = useMutation({
    mutationFn: async (): Promise<SessionData> => {
      const { queue, currentIndex, currentTime } = usePlayerStore.getState()
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
      setShowTransferPrompt(false)
      setPendingSession(null)
    },
  })

  // Replace REST sync with WS-primary
  const syncToServer = useCallback(() => {
    const { queue, currentIndex, currentTime, isPlaying } = usePlayerStore.getState()
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
        headers: { 'X-Device-Id': deviceId },
      }).catch((err) => { logger.warn('REST session sync failed:', err) })
    }
  }, [deviceId, query.data])

  // Subscribe to player store changes for debounced sync
  useEffect(() => {
    const unsub = usePlayerStore.subscribe((state, prevState) => {
      if (
        state.queue !== prevState.queue ||
        state.currentIndex !== prevState.currentIndex ||
        state.currentTime !== prevState.currentTime ||
        state.isPlaying !== prevState.isPlaying
      ) {
        if (debounceRef.current) clearTimeout(debounceRef.current)
        debounceRef.current = setTimeout(syncToServer, SYNC_DEBOUNCE_MS)
      }
    })
    return () => {
      unsub()
      if (debounceRef.current) clearTimeout(debounceRef.current)
    }
  }, [syncToServer])

  function dismissTransfer() {
    setShowTransferPrompt(false)
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
