import { useEffect, useRef } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useSidebarStore } from '../stores/sidebar-store'
import { useMediaModeStore } from '../stores/media-mode-store'
import { ALL_SCHEMAS } from '../schemas'
import type { MediaSidebarSchema } from '../schemas/types'

/**
 * Fetches per-media-type sidebar config from the API.
 *
 * Loading strategy:
 * - If the schema for the active media is already cached (non-empty sections),
 *   render immediately without showing skeletons — the API fetch runs in the
 *   background and silently updates when it returns.
 * - If no cached data exists (first load of a media type), show skeletons
 *   until the fetch completes or falls back to static defaults.
 */
export function useSidebarConfig() {
  const setLoading = useSidebarStore((s) => s.setLoading)
  const setError = useSidebarStore((s) => s.setError)
  const setSchema = useSidebarStore((s) => s.setSchema)
  const schemas = useSidebarStore((s) => s.schemas)
  const activeMedia = useMediaModeStore((s) => s.activeMedia)

  // Track which media types have been fetched at least once
  const fetchedMedia = useRef<Set<string>>(new Set())

  // Determine if we have cached content for the active media
  const activeSchema = schemas[activeMedia]
  const hasCachedContent = activeSchema.sections.length > 0

  // Show loading state only when we have nothing cached
  const isLoading = useSidebarStore((s) => s.isLoading)
  const shouldShowLoading = isLoading && !hasCachedContent

  useEffect(() => {
    let cancelled = false

    async function fetchConfig() {
      // If schema is already cached, skip the loading skeleton — fetch in background
      if (hasCachedContent) {
        setLoading(false)
      } else {
        setLoading(true)
      }
      setError(null)

      try {
        const res = await AXIOS_INSTANCE.get(`/api/user/sidebar-config/${activeMedia}`)
        if (!cancelled && res.data?.sections?.length > 0) {
          setSchema(activeMedia, res.data as MediaSidebarSchema)
        } else if (!cancelled) {
          setSchema(activeMedia, ALL_SCHEMAS[activeMedia])
        }
      } catch {
        if (!cancelled) {
          setSchema(activeMedia, ALL_SCHEMAS[activeMedia])
        }
      } finally {
        if (!cancelled) {
          fetchedMedia.current.add(activeMedia)
          setLoading(false)
        }
      }
    }

    fetchConfig()
    return () => { cancelled = true }
  }, [activeMedia, hasCachedContent, setSchema, setLoading, setError])

  return { isLoading: shouldShowLoading }
}
