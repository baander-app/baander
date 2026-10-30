import { useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { getCurrentTime } from '@/features/player/stores/player-time-tracker'
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

function isFocusedControlKey(event: KeyboardEvent) {
  if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return false
  if (![' ', 'Enter', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'PageUp', 'PageDown'].includes(event.key)) {
    return false
  }
  return document.activeElement?.matches(
    'button, a[href], summary, [role="button"], [role="switch"], [role="checkbox"], [role="radio"], [role="tab"], [role="slider"], [role="combobox"], [role="listbox"], [role^="menuitem"], [role="option"]',
  ) ?? false
}

interface KeyboardShortcutsOptions {
  /** Called when ? is pressed to toggle shortcuts help */
  onToggleHelp?: () => void
  /** Called when / is pressed to focus search */
  onFocusSearch?: () => void
}

export function useKeyboardShortcuts(options: KeyboardShortcutsOptions = {}) {
  const navigate = useNavigate()
  const { onToggleHelp, onFocusSearch } = options

  // Keep external shortcut registration in the effect lifecycle.
  useEffect(() => {
    clearRegistry()

    const definitions = createShortcutDefinitions({
      // Player store (using getState for stable references)
      setIsPlaying: (p) => usePlayerStore.getState().setIsPlaying(p),
      isPlaying: () => usePlayerStore.getState().isPlaying,
      playNext: () => usePlayerStore.getState().playNext(),
      playPrevious: () => usePlayerStore.getState().playPrevious(),
      seekTo: (t) => usePlayerStore.getState().seekTo(t),
      getCurrentTime,
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

    return clearRegistry
  }, [navigate, onToggleHelp, onFocusSearch])

  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (e.defaultPrevented || isInputFocused() || isFocusedControlKey(e)) return

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
    [onToggleHelp],
  )

  useEffect(() => {
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [handleKeyDown])
}
