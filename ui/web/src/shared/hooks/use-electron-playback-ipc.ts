import { useEffect } from 'react'
import { usePlayerStore } from '@/features/player/stores/player-store'

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
      usePlayerStore.setState({ isPlaying: !isPlaying })
    })

    const unsubNext = electron.playback.onNext(() => {
      usePlayerStore.getState().playNext()
    })

    const unsubPrevious = electron.playback.onPrevious(() => {
      usePlayerStore.getState().playPrevious()
    })

    const unsubSeekFwd = electron.playback.onSeekForward(() => {
      const { currentTime } = usePlayerStore.getState()
      usePlayerStore.getState().seekTo(currentTime + 10)
    })

    const unsubSeekBack = electron.playback.onSeekBackward(() => {
      const { currentTime } = usePlayerStore.getState()
      usePlayerStore.getState().seekTo(Math.max(0, currentTime - 10))
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
