import { useState, useEffect } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('ImageBlob')

export interface UseImageBlobResult {
  src: string | null
  isLoading: boolean
}

/**
 * Fetch an image via authenticated AXIOS_INSTANCE and return a blob URL.
 * Revokes the blob URL on unmount or when imageUrl changes (fixes memory leak).
 */
export function useImageBlob(imageUrl?: string | null): UseImageBlobResult {
  const source = imageUrl || null
  const [image, setImage] = useState(() => ({
    source, src: null as string | null, isLoading: source !== null,
  }))

  // Reset during render so children never receive a cover owned by another URL.
  // Tracking the source also distinguishes A → B → A from the earlier request.
  if (image.source !== source) {
    setImage({ source, src: null, isLoading: source !== null })
  }

  useEffect(() => {
    if (!source) return
    const controller = new AbortController()
    let objectUrl: string | null = null

    AXIOS_INSTANCE.get<Blob>(source, { responseType: 'blob', signal: controller.signal })
      .then((response) => {
        // A transport can settle after abort; cancellation alone is not ownership.
        if (controller.signal.aborted) return
        objectUrl = URL.createObjectURL(response.data)
        setImage({ source, src: objectUrl, isLoading: false })
      })
      .catch((err) => {
        if (controller.signal.aborted) return
        logger.warn('Image blob fetch failed:', err)
        setImage({ source, src: null, isLoading: false })
      })

    return () => {
      controller.abort()
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [source])

  return image.source === source
    ? { src: image.src, isLoading: image.isLoading }
    : { src: null, isLoading: source !== null }
}
