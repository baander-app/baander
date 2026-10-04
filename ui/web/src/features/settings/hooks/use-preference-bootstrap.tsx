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
  const userUuid = useAuthStore((state) => state.user?.uuid)
  const { applyOnMount: applyThemeMood } = useThemeMood()
  const { applyOnMount: applyAccentColor } = useAccentColor()

  useEffect(() => {
    applyThemeMood()
    applyAccentColor()
  }, [applyThemeMood, applyAccentColor])

  return (
    <>
      {children}
      {isAuthenticated && userUuid
        ? <PreferenceSyncSession key={userUuid} userUuid={userUuid} />
        : null}
    </>
  )
}

function PreferenceSyncSession({ userUuid }: { userUuid: string }) {
  const isActive = useCallback(() => {
    const auth = useAuthStore.getState()
    return auth.isAuthenticated && auth.user?.uuid === userUuid
  }, [userUuid])
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

    void audioFetchRef.current()
    void playerFetchRef.current()
    void layoutFetchRef.current()

    void (async () => {
      try {
        const moodRes = await AXIOS_INSTANCE.get('/api/user/theme-mood/', { signal: controller.signal })
        const serverMood = moodRes.data?.mood ?? moodRes.data?.data?.mood
        if (canApply() && serverMood && (VALID_MOODS as readonly string[]).includes(serverMood)) {
          localStorage.setItem('baander-theme-mood', serverMood)
          document.documentElement.setAttribute('data-theme', serverMood)
        }
      } catch { /* first-time user, use local/OS default */ }

      if (!canApply()) return
      try {
        const colorRes = await AXIOS_INSTANCE.get('/api/user/accent-color/', { signal: controller.signal })
        const serverColor = colorRes.data?.color ?? colorRes.data?.data?.color
        if (canApply() && serverColor && (VALID_COLORS as readonly string[]).includes(serverColor)) {
          localStorage.setItem('baander-accent-color', serverColor)
          document.documentElement.setAttribute('data-accent', serverColor)
        }
      } catch { /* first-time user, use local default */ }
    })()

    return () => controller.abort()
  }, [isActive])

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
    void activeConflict?.resolve(resolution)
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
