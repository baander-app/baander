import { useEffect, useRef, useState } from 'react'
import { usePlaybackSession } from '@/features/session/hooks/use-playback-session'
import { requestVideoPlayback } from '../services/video-playback'
import { useVideoPresentationError } from '../hooks/use-video-presentation-error'
import { useVideoPresentation } from '../stores/video-presentation-store'

type PlayIntent = Parameters<typeof requestVideoPlayback>[0]

export interface DetailPlaybackOptions extends PlayIntent {
  title: string
  duration: number | null
}

export function useVideoDetailPlayback() {
  const [active, setActive] = useState<DetailPlaybackOptions | null>(null)
  const sequence = useRef(0)
  const { snapshot } = usePlaybackSession()
  const error = useVideoPresentationError(snapshot)

  useEffect(() => () => {
    ++sequence.current
  }, [])

  function play(options: DetailPlaybackOptions) {
    const current = ++sequence.current
    const { titleKind, titleId, videoId } = options
    const media = snapshot?.media
    const activeFile = snapshot && media?.kind === 'video' && media.publicId === videoId
      && (snapshot.observedState === 'playing' || snapshot.observedState === 'paused')
    const startSeconds = activeFile ? snapshot.position : options.startSeconds
    const opened = { ...options, startSeconds }
    if (activeFile && media.viewing?.titleKind === titleKind && media.viewing.titleId === titleId) {
      setActive(opened)
      return
    }

    requestVideoPlayback({ titleKind, titleId, videoId, startSeconds })
      .then(() => {
        if (current === sequence.current) setActive(opened)
      })
      .catch(failure => {
        if (current === sequence.current) useVideoPresentation.getState().reportError(failure)
      })
  }

  function close() {
    ++sequence.current
    setActive(null)
  }

  return { active, error, play, close }
}
