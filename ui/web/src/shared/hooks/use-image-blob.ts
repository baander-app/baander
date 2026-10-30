import { useMemo, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { captureOwnership, isCurrentOwnership, subscribeOwnership, type OwnershipStamp } from '@/shared/live/ownership'

export interface UseImageBlobResult {
  src: string | null
  isLoading: boolean
}

/** Authenticated bytes are shared; each renderer owns and releases its own object URL. */
export function useAuthenticatedImage(imageUrl?: string | null) {
  const source = imageUrl || null
  const ownership = useSyncExternalStore(subscribeOwnership, captureOwnership)
  const query = useQuery({
    queryKey: ['catalog', 'binary-image', source, ownership.generation],
    enabled: source !== null,
    gcTime: 0,
    queryFn: async ({ signal }) => {
      if (!source) return null
      try {
        const response = await AXIOS_INSTANCE.get<Blob>(source, { responseType: 'blob', signal })
        return response.data
      } catch (error) {
        const status = (error as { response?: { status?: number } } | null)?.response?.status
        if (status === 404) return null
        throw error
      }
    },
  })
  const revoked = [query.error, query.failureReason].some(error => {
    const status = (error as { response?: { status?: number } } | null)?.response?.status
    return status === 401 || status === 403
  })
  return { blob: revoked ? null : query.data ?? null, isLoading: source !== null && query.isPending, source, ownership }
}

function createImageSource(blob: Blob | null, source: string | null, ownership: OwnershipStamp) {
  let src: string | null = null
  return {
    getSnapshot: () => isCurrentOwnership(ownership) ? src : null,
    subscribe: (notify: () => void) => {
      if (!blob || !source) return () => undefined
      src = URL.createObjectURL(blob)
      notify()
      return () => {
        if (src) URL.revokeObjectURL(src)
        src = null
      }
    },
  }
}

export function useImageBlob(imageUrl?: string | null): UseImageBlobResult {
  const { blob, isLoading, source, ownership } = useAuthenticatedImage(imageUrl)
  const image = useMemo(() => createImageSource(blob, source, ownership), [blob, source, ownership])
  const src = useSyncExternalStore(image.subscribe, image.getSnapshot)
  return { src, isLoading }
}
