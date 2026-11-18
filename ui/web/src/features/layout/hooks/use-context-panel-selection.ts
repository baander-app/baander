import { useEffect, useRef } from 'react'
import { useParams, useLocation } from 'react-router-dom'
import { useContextPanelStore } from '../stores/context-panel-store'
import { useSelectionStore } from '@/features/catalog/stores/selection-store'
import { usePlayerStore } from '@/features/player/stores/player-store'

/**
 * Syncs both URL params and the catalog selection store with the context panel.
 *
 * 1. Visiting /albums/:publicId or /artists/:publicId updates the context panel.
 * 2. Catalog browse selections (selection store) update the context panel.
 */
export function useContextPanelSelection() {
  const { publicId } = useParams<{ publicId: string }>()
  const location = useLocation()
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)

  // Sync URL params → context panel store
  useEffect(() => {
    if (!publicId) return

    const pathname = location.pathname
    if (pathname.startsWith('/albums/')) {
      setSelectedItem({ type: 'album', publicId })
    } else if (pathname.startsWith('/artists/')) {
      setSelectedItem({ type: 'artist', publicId })
    }
  }, [publicId, location.pathname, setSelectedItem])

  // Sync selection store → context panel store
  const selectedId = useSelectionStore((s) => s.selectedId)
  const selectedType = useSelectionStore((s) => s.selectedType)

  useEffect(() => {
    if (selectedId && selectedType) {
      setSelectedItem({ type: selectedType, publicId: selectedId })
    }
  }, [selectedId, selectedType, setSelectedItem])

  // Auto-select the playing track when it changes and no explicit selection exists. Until this
  // session's saved layout has been read, the panel keeps its tab: the listening session's track
  // can arrive first, and a switch to the Details tab would then count as an edit of a layout
  // this page has not seen, which the read reports as a settings conflict.
  // The playing track's ID, not its object: a session's track arrives as a placeholder and is
  // replaced when its details load, and that must not reopen a panel the user has closed.
  const currentTrackId = usePlayerStore((s) => s.currentTrack?.publicId)
  const selectItemKeepingTab = useContextPanelStore((s) => s.selectItemKeepingTab)
  const userSelectedRef = useRef(false)

  // Track whether the user has made an explicit selection
  useEffect(() => {
    if (selectedId) {
      userSelectedRef.current = true
    }
  }, [selectedId])

  useEffect(() => {
    if (!currentTrackId || userSelectedRef.current) return
    const item = { type: 'song' as const, publicId: currentTrackId }
    if (useContextPanelStore.getState().layoutPreferencesRead) {
      setSelectedItem(item)
    } else {
      selectItemKeepingTab(item)
    }
  }, [currentTrackId, setSelectedItem, selectItemKeepingTab])
}
