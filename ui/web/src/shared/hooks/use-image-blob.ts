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
  const [src, setSrc] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(false)

  useEffect(() => {
    if (!imageUrl) {
      setSrc(null)
      setIsLoading(false)
      return
    }

    const controller = new AbortController()
    let objectUrl: string | null = null

    setIsLoading(true)

    AXIOS_INSTANCE
      .get(imageUrl, {
        responseType: 'blob',
        signal: controller.signal,
      })
      .then((response) => {
        objectUrl = URL.createObjectURL(response.data as Blob)
        setSrc(objectUrl)
      })
      .catch((err) => {
        if (!controller.signal.aborted) logger.warn('Image blob fetch failed:', err)
      })
      .finally(() => {
        setIsLoading(false)
      })

    return () => {
      controller.abort()
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl)
      }
    }
  }, [imageUrl])

  return { src, isLoading }
}
