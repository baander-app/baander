import { replaceEqualDeep } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { useEffect } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useLiveCoordinator } from '@/shared/live/context'
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { useSidebarStore } from '../stores/sidebar-store'
import { useMediaModeStore } from '../stores/media-mode-store'
import { ALL_SCHEMAS } from '../schemas'
import type { MediaSidebarSchema } from '../schemas/types'

export function useSidebarConfig() {
  const coordinator = useLiveCoordinator()
  const activeMedia = useMediaModeStore(s => s.activeMedia)
  const hasCachedContent = useSidebarStore(s => s.schemas[activeMedia].sections.length > 0)
  const isLoading = useSidebarStore(s => s.isLoading)

  useEffect(() => {
    const controller = new AbortController()
    const read = async (signal: AbortSignal) => {
      const owner = captureOwnership()
      const current = () => !signal.aborted && !controller.signal.aborted && isCurrentOwnership(owner)
      const store = useSidebarStore.getState()
      store.setLoading(store.schemas[activeMedia].sections.length === 0)
      try {
        const res = await AXIOS_INSTANCE.get(`/api/user/sidebar-config/${activeMedia}`, { signal })
        if (!current()) return
        store.setError(null)
        const schema = res.data?.data as MediaSidebarSchema | undefined
        const next = schema && schema.sections?.length > 0 ? schema : ALL_SCHEMAS[activeMedia]
        const currentSchema = useSidebarStore.getState().schemas[activeMedia]
        const shared = replaceEqualDeep(currentSchema, next)
        if (shared !== currentSchema) store.setSchema(activeMedia, shared)
      } catch (error) {
        if (!current()) return
        if (isAxiosError(error) && [403, 404].includes(error.response?.status ?? 0)) {
          store.setError('Sidebar access unavailable')
          store.setSchema(activeMedia, ALL_SCHEMAS[activeMedia])
        }
        if (!store.schemas[activeMedia].sections.length) store.setSchema(activeMedia, ALL_SCHEMAS[activeMedia])
        if (coordinator) throw error
      } finally {
        if (current()) store.setLoading(false)
      }
    }
    const unregister = coordinator?.registerAdapter({
      id: `sidebar:${activeMedia}`, family: 'preferences', isActive: () => true, read,
    })
    if (!coordinator) read(controller.signal).catch(() => undefined)
    return () => { controller.abort(); unregister?.() }
  }, [activeMedia, coordinator])

  return { isLoading: isLoading && !hasCachedContent }
}
