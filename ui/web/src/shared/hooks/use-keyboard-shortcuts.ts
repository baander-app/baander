import { useEffect, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { useLyricsFullscreenStore } from '@/features/layout/stores/lyrics-fullscreen-store'
import { toast } from 'sonner'
import {
  registerShortcut,
  clearRegistry,
  getAllShortcuts,
} from '@/shared/lib/shortcut-registry'
import { createShortcutDefinitions } from '@/shared/lib/shortcut-definitions'

function isInputFocused() {
  const el = document.activeElement
  if (!el) return false
  const tag = el.tagName.toLowerCase()
  if (tag === 'input' || tag === 'textarea' || tag === 'select') {
    return true
  }
  return (el as HTMLElement).isContentEditable
}

interface KeyboardShortcutsOptions {
  /** Called when ? is pressed to toggle shortcuts help */
  onToggleHelp?: () => void
  /** Called when / is pressed to focus search */
  onFocusSearch?: () => void
}

export function useKeyboardShortcuts(options: KeyboardShortcutsOptions = {}) {
  const navigate = useNavigate()
  const registryRef = useRef<boolean>(false)
  const { onToggleHelp, onFocusSearch } = options

  // Build and register shortcuts once on mount
  if (!registryRef.current) {
    clearRegistry()

    const definitions = createShortcutDefinitions({
      // Player store (using getState for stable references)
      setIsPlaying: (p) => usePlayerStore.setState({ isPlaying: p }),
      isPlaying: () => usePlayerStore.getState().isPlaying,
      playNext: () => usePlayerStore.getState().playNext(),
      playPrevious: () => usePlayerStore.getState().playPrevious(),
      seekTo: (t) => usePlayerStore.getState().seekTo(t),
      getCurrentTime: () => usePlayerStore.getState().currentTime,
      setVolume: (v) => usePlayerStore.getState().setVolume(v),
      getVolume: () => usePlayerStore.getState().volume,
      toggleShuffle: () => usePlayerStore.getState().toggleShuffle(),
      toggleRepeat: () => usePlayerStore.getState().toggleRepeat(),
      toggleMute: () => usePlayerStore.getState().toggleMute(),
      clearQueue: () => usePlayerStore.getState().clearQueue(),
      getCurrentTrack: () => usePlayerStore.getState().currentTrack,

      // Context panel store
      setContextPanelTab: (tab) => useContextPanelStore.getState().setActiveTab(tab),

      // Lyrics fullscreen
      toggleLyricsFullscreen: () => useLyricsFullscreenStore.getState().toggle(),

      // Navigation
      goBack: () => navigate(-1),
      goToPlaying: () => {
        const track = usePlayerStore.getState().currentTrack
        if (track?.albumPublicId) {
          useContextPanelStore.getState().setSelectedItem({
            type: 'album',
            publicId: track.albumPublicId,
          })
        }
      },

      // Callbacks
      onToggleHelp,
      onFocusSearch,
    })

    for (const entry of definitions) {
      registerShortcut(entry)
    }

    registryRef.current = true
  }

  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (isInputFocused()) return

      // Special case: ? toggle help (shift+/)
      if (e.key === '?' && e.shiftKey) {
        e.preventDefault()
        onToggleHelp?.()
        return
      }

      // Iterate registry entries, find first match
      for (const entry of getAllShortcuts()) {
        if (entry.matches(e) && (entry.enabled?.() ?? true)) {
          e.preventDefault()
          entry.action?.(e)

          // Show toast for clear queue
          if (entry.id === 'panel.clear-queue') {
            toast.success('Queue cleared')
          }
          return
        }
      }
    },
    [onToggleHelp, onFocusSearch],
  )

  useEffect(() => {
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [handleKeyDown])
}
