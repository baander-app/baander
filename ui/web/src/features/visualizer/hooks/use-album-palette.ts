import { useEffect } from 'react'
import { useAuthenticatedImage } from '@/shared/hooks/use-image-blob'
import { isCurrentOwnership } from '@/shared/live/ownership'
import { usePaletteStore } from '../stores/palette-store'
import { extractPalette } from '../utils/extract-palette'

/** Palette work uses the same authenticated bytes and ownership as cover renderers. */
export function useAlbumPalette(albumPublicId: string, imageUrl?: string | null): void {
  const { blob, ownership } = useAuthenticatedImage(imageUrl)
  useEffect(() => {
    if (!albumPublicId) return
    const store = usePaletteStore.getState()
    const token = store.beginExtraction(albumPublicId)
    if (!blob) {
      store.finishExtraction(albumPublicId, token, null)
      return
    }
    let active = true
    extractPalette(blob).then((palette) => {
      if (!active || !isCurrentOwnership(ownership)) return
      store.finishExtraction(albumPublicId, token, palette)
    })
    return () => {
      active = false
      store.cancelExtraction(albumPublicId, token)
    }
  }, [albumPublicId, blob, ownership])
}
