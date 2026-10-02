import { useEffect, useRef, useCallback, useState } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

interface UseBaanderPlayerOptions {
  videoId: string
  containerRef: React.RefObject<HTMLDivElement | null>
  autoPlay?: boolean
  onTimeUpdate?: (currentTime: number, duration: number) => void
  onStateChange?: (state: string) => void
  onError?: (error: MediaError | null) => void
}

interface UseBaanderPlayerReturn {
  play: () => void
  pause: () => void
  seekTo: (time: number) => void
  destroy: () => void
  state: string | null
  duration: number
  currentTime: number
}

export function useBaanderPlayer({
  videoId,
  containerRef,
  autoPlay = false,
  onTimeUpdate,
  onStateChange,
  onError,
}: UseBaanderPlayerOptions): UseBaanderPlayerReturn {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const [state, setState] = useState<string | null>(null)
  const [duration, setDuration] = useState(0)
  const [currentTime, setCurrentTime] = useState(0)

  useEffect(() => {
    if (!containerRef.current || !videoId) return

    const video = document.createElement('video')
    video.style.width = '100%'
    video.style.height = '100%'
    video.controls = true
    containerRef.current.appendChild(video)
    videoRef.current = video

    const abortController = new AbortController()
    // Authorize and start playback before handing a signed URL to the native player.
    void AXIOS_INSTANCE.post<{ url: string }>('/api/stream/sign', {
      path: `/api/transcode/${videoId}/master.m3u8`,
    }, { signal: abortController.signal }).then(({ data }) => {
      if (abortController.signal.aborted || videoRef.current !== video) return
      video.src = new URL(data.url, window.__BAANDER_API_URL__ || window.location.origin).href
      if (autoPlay) void video.play().catch(() => {})
    }).catch(() => {
      if (abortController.signal.aborted || videoRef.current !== video) return
      setState('error')
      onError?.(null)
    })

    video.addEventListener('loadedmetadata', () => {
      setDuration(video.duration)
      setState('loaded')
    })

    video.addEventListener('timeupdate', () => {
      setCurrentTime(video.currentTime)
      onTimeUpdate?.(video.currentTime, video.duration)
    })

    video.addEventListener('play', () => {
      setState('playing')
      onStateChange?.('playing')
    })

    video.addEventListener('pause', () => {
      setState('paused')
      onStateChange?.('paused')
    })

    video.addEventListener('ended', () => {
      setState('ended')
      onStateChange?.('ended')
    })

    video.addEventListener('error', () => {
      setState('error')
      onError?.(video.error)
    })

    return () => {
      abortController.abort()
      video.pause()
      video.src = ''
      videoRef.current = null
      if (video.parentElement) {
        video.parentElement.removeChild(video)
      }
    }
  }, [videoId]) // eslint-disable-line react-hooks/exhaustive-deps

  const play = useCallback(() => { videoRef.current?.play() }, [])
  const pause = useCallback(() => { videoRef.current?.pause() }, [])
  const seekTo = useCallback((time: number) => { if (videoRef.current) videoRef.current.currentTime = time }, [])
  const destroy = useCallback(() => {
    if (videoRef.current) {
      videoRef.current.pause()
      videoRef.current.src = ''
    }
    videoRef.current = null
  }, [])

  return { play, pause, seekTo, destroy, state, duration, currentTime }
}
