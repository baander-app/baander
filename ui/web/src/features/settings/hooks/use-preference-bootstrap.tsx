import { useEffect, useRef, useState } from 'react'
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
  const audioSync = useAudioPreferences()
  const playerSync = usePlayerPreferences()
  const layoutSync = useLayoutPreferences()

  const themeMood = useThemeMood()
  const accentColor = useAccentColor()

  const [activeConflict, setActiveConflict] = useState<{
    sync: ReturnType<typeof useAudioPreferences>
    store: 'audio' | 'player' | 'layout'
  } | null>(null)

  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const initialized = useRef(false)

  // The sync objects returned by the preference hooks are fresh each render, and the
  // callbacks inside them are not referentially stable (their useCallback deps include
  // inline toPayload/fromPayload). Mirror the latest push/fetch callbacks into refs so the
  // subscribe + fetch effects below can depend only on `isAuthenticated` and run once per
  // auth transition instead of tearing down and recreating four store subscriptions (and
  // re-running the fetch guard) on every render.
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

  // Apply theme immediately on mount (before auth check)
  useEffect(() => {
    themeMood.applyOnMount()
    accentColor.applyOnMount()
  }, [themeMood.applyOnMount, accentColor.applyOnMount])

  // Fetch all preferences from server once authenticated
  useEffect(() => {
    if (!isAuthenticated) return
    if (initialized.current) return
    initialized.current = true

    audioFetchRef.current()
    playerFetchRef.current()
    layoutFetchRef.current()

    // Fetch theme mood and accent color from server
    ;(async () => {
      try {
        const moodRes = await AXIOS_INSTANCE.get('/api/user/theme-mood/')
        const serverMood = moodRes.data?.mood ?? moodRes.data?.data?.mood
        if (serverMood && (VALID_MOODS as readonly string[]).includes(serverMood)) {
          localStorage.setItem('baander-theme-mood', serverMood)
          document.documentElement.setAttribute('data-theme', serverMood)
        }
      } catch { /* first-time user, use local/OS default */ }

      try {
        const colorRes = await AXIOS_INSTANCE.get('/api/user/accent-color/')
        const serverColor = colorRes.data?.color ?? colorRes.data?.data?.color
        if (serverColor && (VALID_COLORS as readonly string[]).includes(serverColor)) {
          localStorage.setItem('baander-accent-color', serverColor)
          document.documentElement.setAttribute('data-accent', serverColor)
        }
      } catch { /* first-time user, use local default */ }
    })()
  }, [isAuthenticated])

  // Reset initialization when user logs out
  useEffect(() => {
    if (!isAuthenticated) {
      initialized.current = false
    }
  }, [isAuthenticated])

  // Subscribe to store changes and push to server
  useEffect(() => {
    if (!isAuthenticated) return

    const unsubBands = useEqBandsStore.subscribe((state, prevState) => {
      const keys: (keyof typeof state)[] = ['enabled', 'bands', 'preset', 'visualizerMode']
      if (keys.some((k) => state[k] !== prevState[k])) {
        // toPayload reads from stores directly; arg triggers the debounce+push
        audioPushRef.current({} as any)
      }
    })

    const unsubProcessing = useEqProcessingStore.subscribe((state, prevState) => {
      const keys: (keyof typeof state)[] = [
        'compressionEnabled', 'masterGain', 'normalizationEnabled',
        'targetLufs',
      ]
      if (keys.some((k) => state[k] !== prevState[k])) {
        audioPushRef.current({} as any)
      }
    })

    const unsubPlayer = usePlayerStore.subscribe((state, prevState) => {
      const keys: (keyof typeof state)[] = ['shuffle', 'repeat', 'volume', 'muted']
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
  }, [isAuthenticated])

  // Detect conflicts from any sync
  useEffect(() => {
    if (audioSync.conflict.type === 'conflict') {
      setActiveConflict({ sync: audioSync as any, store: 'audio' })
    } else if (playerSync.conflict.type === 'conflict') {
      setActiveConflict({ sync: playerSync as any, store: 'player' })
    } else if (layoutSync.conflict.type === 'conflict') {
      setActiveConflict({ sync: layoutSync as any, store: 'layout' })
    }
  }, [audioSync.conflict, playerSync.conflict, layoutSync.conflict])

  function handleConflictResolve(resolution: 'mine' | 'theirs') {
    if (!activeConflict) return

    const localState = (() => {
      switch (activeConflict.store) {
        case 'audio':
          // The sync's toPayload reads from stores directly, so pass a minimal payload
          return {
            enabled: true,
            bands: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            preset: 'FLAT',
            compressionEnabled: false,
            compressorThreshold: -20,
            compressorRatio: 4,
            compressorKnee: 4,
            compressorAttack: 0.003,
            compressorRelease: 0.1,
            masterGain: 0,
            normalizationEnabled: false,
            targetLufs: -16,
            visualizerMode: 'spectrum',
            stereoEnabled: false,
            stereoWidth: 100,
            stereoMode: 'normal',
            crossfeedEnabled: false,
            crossfeedPreset: 'normal',
            loudnessContourEnabled: false,
            chainOrder: [],
          } as const
        case 'player':
          return usePlayerStore.getState()
        case 'layout':
          return useContextPanelStore.getState()
      }
    })()

    activeConflict.sync.resolveConflict(resolution, localState as any)
    setActiveConflict(null)
  }

  return (
    <>
      {children}
      <PreferenceConflictDialog
        open={activeConflict != null}
        serverVersion={activeConflict?.sync.conflict.serverVersion ?? null}
        onResolve={handleConflictResolve}
      />
    </>
  )
}
