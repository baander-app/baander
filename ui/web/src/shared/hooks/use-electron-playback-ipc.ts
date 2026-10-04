import { useEffect } from 'react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { getCurrentTime } from '@/features/player/stores/player-time-tracker'

declare global {
  interface Window {
    electron?: {
      playback?: {
        onToggle: (callback: () => void) => () => void
        onNext: (callback: () => void) => () => void
        onPrevious: (callback: () => void) => () => void
        onSeekForward: (callback: () => void) => () => void
        onSeekBackward: (callback: () => void) => () => void
      }
    }
  }
}

/**
 * Listens for Electron playback IPC events and dispatches to the player store.
 * Gracefully no-ops when not running in Electron (no `window.electron`).
 */
export function useElectronPlaybackIpc() {
  useEffect(() => {
    const electron = window.electron
    if (!electron?.playback) return

    const unsubToggle = electron.playback.onToggle(() => {
      const { isPlaying } = usePlayerStore.getState()
      usePlayerStore.getState().setIsPlaying(!isPlaying)
    })

    const unsubNext = electron.playback.onNext(() => {
      usePlayerStore.getState().playNext()
    })

    const unsubPrevious = electron.playback.onPrevious(() => {
      usePlayerStore.getState().playPrevious()
    })

    const unsubSeekFwd = electron.playback.onSeekForward(() => {
      usePlayerStore.getState().seekTo(getCurrentTime() + 10)
    })

    const unsubSeekBack = electron.playback.onSeekBackward(() => {
      usePlayerStore.getState().seekTo(Math.max(0, getCurrentTime() - 10))
    })

    return () => {
      unsubToggle()
      unsubNext()
      unsubPrevious()
      unsubSeekFwd()
      unsubSeekBack()
    }
  }, [])
}
