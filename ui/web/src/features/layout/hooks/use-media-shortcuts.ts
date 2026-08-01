import { useEffect, useCallback } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { useMediaModeStore, MEDIA_TYPES } from '../stores/media-mode-store'

function isInputFocused(): boolean {
  const el = document.activeElement
  if (!el) return false
  const tag = el.tagName.toLowerCase()
  if (tag === 'input' || tag === 'textarea' || tag === 'select') return true
  if ((el as HTMLElement).isContentEditable) return true
  return false
}

export function useMediaShortcuts() {
  const setActiveMedia = useMediaModeStore((s) => s.setActiveMedia)
  const navigate = useNavigate()
  const location = useLocation()

  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (isInputFocused()) return

      const isMeta = e.metaKey || e.ctrlKey
      if (!isMeta) return

      // Cmd+1 through Cmd+6 for media type switching
      const num = parseInt(e.key, 10)
      if (num >= 1 && num <= MEDIA_TYPES.length) {
        e.preventDefault()
        const mediaType = MEDIA_TYPES[num - 1]
        setActiveMedia(mediaType)

        // Navigate to media home unless already on a route under this media prefix
        if (!location.pathname.startsWith(`/${mediaType}`)) {
          navigate(`/${mediaType}`)
        }
      }
    },
    [setActiveMedia, navigate, location.pathname],
  )

  useEffect(() => {
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [handleKeyDown])
}
