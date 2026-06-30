import { useCallback } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { useMediaModeStore } from '../stores/media-mode-store'
import type { MediaType } from '../stores/media-mode-store'

export function useMediaMode() {
  const activeMedia = useMediaModeStore((s) => s.activeMedia)
  const setActiveMedia = useMediaModeStore((s) => s.setActiveMedia)
  const navigate = useNavigate()
  const location = useLocation()

  const switchMedia = useCallback(
    (media: MediaType) => {
      setActiveMedia(media)

      // Navigate to media home unless already on a route under this media prefix
      const isAlreadyOnMediaRoute = location.pathname.startsWith(`/${media}`)
      if (!isAlreadyOnMediaRoute) {
        navigate(`/${media}`)
      }
    },
    [setActiveMedia, navigate, location.pathname],
  )

  return { activeMedia, switchMedia }
}
