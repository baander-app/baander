import { useEffect } from 'react'
import { useTheme } from 'styled-components'
import { extractDominantColor, readableArtworkAccent } from '@/shared/utils/blurhash'

export function useBlurhashAccent(
  blurhash: string | null | undefined,
  containerRef: React.RefObject<HTMLElement | null>,
): void {
  const theme = useTheme()
  useEffect(() => {
    const el = containerRef.current
    if (!el) return

    const color = extractDominantColor(blurhash)
    const colors = theme.colors
    el.style.setProperty('--accent-derived', color ?? colors.primary)
    el.style.setProperty('--accent-derived-foreground', color
      ? readableArtworkAccent(color, [colors.background, colors.card, colors.highlight], colors.accentText)
      : colors.accentText)

    return () => {
      el.style.removeProperty('--accent-derived')
      el.style.removeProperty('--accent-derived-foreground')
    }
  }, [blurhash, containerRef, theme])
}
