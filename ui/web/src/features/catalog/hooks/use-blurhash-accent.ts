import { useEffect } from 'react'
import { extractDominantColor } from '@/shared/utils/blurhash'

/**
 * Decode a blurhash string and set `--accent-derived` CSS variable on the
 * container ref. Falls back to `--color-primary` when the blurhash is absent
 * or the extracted color is too dark/light/unsaturated.
 */
export function useBlurhashAccent(
  blurhash: string | null | undefined,
  containerRef: React.RefObject<HTMLElement | null>,
): void {
  useEffect(() => {
    const el = containerRef.current
    if (!el) return

    const color = extractDominantColor(blurhash)
    el.style.setProperty('--accent-derived', color ?? 'var(--color-primary)')

    return () => {
      el.style.removeProperty('--accent-derived')
    }
  }, [blurhash, containerRef])
}
