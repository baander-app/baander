import { useSearchParams } from 'react-router-dom'
import { useCallback } from 'react'

/**
 * Syncs a tab value with the URL `?tab=` search parameter.
 * Preserves sibling query params (e.g., ?album=<id>).
 * Falls back to `defaultTab` if the URL value is not in `validTabs`.
 *
 * Each tab switch creates a browser history entry for back-button support.
 */
export function useTabParam(
  defaultTab: string,
  validTabs: readonly string[],
) {
  const [searchParams, setSearchParams] = useSearchParams()
  const raw = searchParams.get('tab') ?? defaultTab
  const tab = validTabs.includes(raw) ? raw : defaultTab

  const setTab = useCallback(
    (value: string) => {
      setSearchParams((prev) => {
        prev.set('tab', value)
        return prev
      })
    },
    [setSearchParams],
  )

  return [tab, setTab] as const
}
