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

  // Auto-select currently playing track when it changes and no explicit selection exists
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const userSelectedRef = useRef(false)

  // Track whether the user has made an explicit selection
  useEffect(() => {
    if (selectedId) {
      userSelectedRef.current = true
    }
  }, [selectedId])

  useEffect(() => {
    if (currentTrack && !userSelectedRef.current) {
      setSelectedItem({ type: 'song', publicId: currentTrack.publicId })
    }
  }, [currentTrack, setSelectedItem])
}
