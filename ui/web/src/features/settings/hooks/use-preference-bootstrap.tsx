import { useLiveCoordinator } from '@/shared/live/context'
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { useCallback, useEffect, useRef } from 'react'
import { useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'
import { useEqProcessingStore } from '@/features/equalizer/stores/eq-processing-store'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { useAudioPreferences } from './use-audio-preferences'
import { usePlayerPreferences } from './use-player-preferences'
import { useLayoutPreferences } from './use-layout-preferences'
import { PreferenceConflictDialog } from '../components/PreferenceConflictDialog'
import { useThemeMood, VALID_MOODS } from './use-theme-mood'
import { useAccentColor, VALID_COLORS } from './use-accent-color'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export function PreferenceSyncProvider({ children }: { children: React.ReactNode }) {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const userPublicId = useAuthStore((state) => state.user?.publicId)
  const { applyOnMount: applyThemeMood } = useThemeMood()
  const { applyOnMount: applyAccentColor } = useAccentColor()

  useEffect(() => {
    applyThemeMood()
    applyAccentColor()
  }, [applyThemeMood, applyAccentColor])

  return (
    <>
      {children}
      {isAuthenticated && userPublicId
        ? <PreferenceSyncSession key={userPublicId} userPublicId={userPublicId} />
        : null}
    </>
  )
}

function PreferenceSyncSession({ userPublicId }: { userPublicId: string }) {
  const coordinator = useLiveCoordinator()
  const isActive = useCallback(() => {
    const auth = useAuthStore.getState()
    return auth.isAuthenticated && auth.user?.publicId === userPublicId
  }, [userPublicId])
  const audioSync = useAudioPreferences(isActive)
  const playerSync = usePlayerPreferences(isActive)
  const layoutSync = useLayoutPreferences(isActive)

  // The sync objects returned by the preference hooks are fresh each render, and the
  // callbacks inside them are not referentially stable (their useCallback deps include
  // inline toPayload/fromPayload). Mirror the latest push/fetch callbacks into refs so the
  // subscribe + fetch effects below can depend only on `isActive` and run once per
  // auth transition instead of tearing down and recreating four store subscriptions (and
  // repeating server fetches) on every render.
  const audioPushRef = useRef(audioSync.pushToServer)
  const playerPushRef = useRef(playerSync.pushToServer)
  const layoutPushRef = useRef(layoutSync.pushToServer)
  const audioFetchRef = useRef(audioSync.fetchFromServer)
  const playerFetchRef = useRef(playerSync.fetchFromServer)
  const layoutFetchRef = useRef(layoutSync.fetchFromServer)

  // Keep the latest push/fetch callbacks mirrored into refs on every commit (not during
  // render — React 19's react-hooks/refs rule forbids writing ref.current in the render
  // body). This effect runs before the subscribe/fetch effects below, so the refs are
  // always fresh when those effects and their store-change callbacks read them.
  useEffect(() => {
    audioPushRef.current = audioSync.pushToServer
    playerPushRef.current = playerSync.pushToServer
    layoutPushRef.current = layoutSync.pushToServer
    audioFetchRef.current = audioSync.fetchFromServer
    playerFetchRef.current = playerSync.fetchFromServer
    layoutFetchRef.current = layoutSync.fetchFromServer
  }, [audioSync, playerSync, layoutSync])

  // Each authenticated account owns a fresh sync session. Cleanup also permits
  // StrictMode effect replay to restart requests instead of skipping initialization.
  useEffect(() => {
    if (!isActive()) return
    const controller = new AbortController()
    const canApply = () => !controller.signal.aborted && isActive()

    if (!coordinator) {
      Promise.resolve(audioFetchRef.current()).catch(() => undefined)
      Promise.resolve(playerFetchRef.current()).catch(() => undefined)
      Promise.resolve(layoutFetchRef.current()).catch(() => undefined)
    }
    const readTheme = async (signal: AbortSignal) => {
      const owner = captureOwnership()
      const [moodResult, colorResult] = await Promise.allSettled([
        AXIOS_INSTANCE.get('/api/user/theme-mood/', { signal }),
        AXIOS_INSTANCE.get('/api/user/accent-color/', { signal }),
      ])
      if (signal.aborted || !canApply() || !isCurrentOwnership(owner)) return
      const mood = moodResult.status === 'fulfilled'
        ? moodResult.value.data?.mood ?? moodResult.value.data?.data?.mood : undefined
      const color = colorResult.status === 'fulfilled'
        ? colorResult.value.data?.color ?? colorResult.value.data?.data?.color : undefined
      let storedSnapshotChanged = false
      if ((VALID_MOODS as readonly unknown[]).includes(mood)) {
        if (localStorage.getItem('baander-theme-mood') !== mood) {
          localStorage.setItem('baander-theme-mood', mood)
          storedSnapshotChanged = true
        }
        if (document.documentElement.getAttribute('data-theme') !== mood) {
          document.documentElement.setAttribute('data-theme', mood)
        }
      }
      if ((VALID_COLORS as readonly unknown[]).includes(color)) {
        if (localStorage.getItem('baander-accent-color') !== color) {
          localStorage.setItem('baander-accent-color', color)
          storedSnapshotChanged = true
        }
        if (document.documentElement.getAttribute('data-accent') !== color) {
          document.documentElement.setAttribute('data-accent', color)
        }
      }
      if (storedSnapshotChanged) window.dispatchEvent(new StorageEvent('storage'))
      if (moodResult.status === 'rejected' || colorResult.status === 'rejected') throw new Error('Theme snapshot incomplete')
    }
    const unregister = coordinator?.registerAdapter({
      id: 'preference:theme', family: 'preferences', isActive, read: readTheme,
    })
    if (!coordinator) readTheme(controller.signal).catch(() => undefined)
    return () => { controller.abort(); unregister?.() }
  }, [isActive, coordinator])

  // Subscribe to store changes and push to server
  useEffect(() => {
    if (!isActive()) return

    const unsubBands = useEqBandsStore.subscribe((state, prevState) => {
      const keys: (keyof typeof state)[] = ['enabled', 'bands', 'preset', 'visualizerMode']
      if (keys.some((k) => state[k] !== prevState[k])) {
        audioPushRef.current()
      }
    })

    const unsubProcessing = useEqProcessingStore.subscribe((state, prevState) => {
      const keys: (keyof typeof state)[] = [
        'compressionEnabled', 'compressorThreshold', 'compressorRatio',
        'compressorKnee', 'compressorAttack', 'compressorRelease',
        'masterGain', 'normalizationEnabled', 'targetLufs',
        'stereoEnabled', 'stereoWidth', 'stereoMode',
        'crossfeedEnabled', 'crossfeedPreset', 'loudnessContourEnabled',
        'chainOrder',
      ]
      if (keys.some((k) => state[k] !== prevState[k])) {
        audioPushRef.current()
      }
    })

    const unsubPlayer = usePlayerStore.subscribe((state, prevState) => {
      const keys: (keyof typeof state)[] = [
        'shuffle', 'repeat', 'volume', 'muted', 'crossfadeEnabled', 'crossfadeDuration',
      ]
      if (keys.some((k) => state[k] !== prevState[k])) {
        playerPushRef.current(state)
      }
    })

    const unsubLayout = useContextPanelStore.subscribe((state, prevState) => {
      if (state.mode !== prevState.mode || state.activeTab !== prevState.activeTab) {
        layoutPushRef.current(state)
      }
    })

    return () => {
      unsubBands()
      unsubProcessing()
      unsubPlayer()
      unsubLayout()
    }
  }, [isActive])

  // Render the highest-priority current conflict and use its current resolver.
  const activeConflict = audioSync.conflict.type === 'conflict'
    ? {
        serverVersion: audioSync.conflict.serverVersion,
        resolve: (resolution: 'mine' | 'theirs') => audioSync.resolveConflict(resolution),
      }
    : playerSync.conflict.type === 'conflict'
      ? {
          serverVersion: playerSync.conflict.serverVersion,
          resolve: (resolution: 'mine' | 'theirs') =>
            playerSync.resolveConflict(resolution, usePlayerStore.getState()),
        }
      : layoutSync.conflict.type === 'conflict'
        ? {
            serverVersion: layoutSync.conflict.serverVersion,
            resolve: (resolution: 'mine' | 'theirs') =>
              layoutSync.resolveConflict(resolution, useContextPanelStore.getState()),
          }
        : null

  function handleConflictResolve(resolution: 'mine' | 'theirs') {
    activeConflict?.resolve(resolution).catch(() => undefined)
  }

  return (
    <>
      <PreferenceConflictDialog
        open={activeConflict != null}
        serverVersion={activeConflict?.serverVersion ?? null}
        onResolve={handleConflictResolve}
      />
    </>
  )
}
