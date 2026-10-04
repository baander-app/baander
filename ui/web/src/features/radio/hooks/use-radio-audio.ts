import { useEffect, useRef } from 'react'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { usePlayerStore } from '@/features/player/stores/player-store'

/**
 * Manages the radio audio element lifecycle.
 * Mount once in AppShell so the element persists across navigation.
 */
export function useRadioAudio() {
  const audioRef = useRef<HTMLAudioElement | null>(null)

  const setAudioElement = useRadioStore((s) => s.setAudioElement)
  const observePlaying = useRadioStore((s) => s.observePlaying)
  const tryNextStream = useRadioStore((s) => s.tryNextStream)

  useEffect(() => {
    const audio = new Audio()
    audio.preload = 'none'
    audioRef.current = audio

    // Sync volume from player store
    const { volume, muted } = usePlayerStore.getState()
    audio.volume = muted ? 0 : volume / 100
    audio.muted = muted

    setAudioElement(audio)

    return () => {
      audio.pause()
      audio.src = ''
      if (useRadioStore.getState().audioElement === audio) setAudioElement(null)
      audioRef.current = null
    }
  }, [setAudioElement])

  // Wire audio events to radio store
  useEffect(() => {
    const audio = audioRef.current
    if (!audio) return

    const onPlay = () => {
      if (useRadioStore.getState().audioElement === audio && !audio.paused && !audio.ended) observePlaying(true)
    }
    const onPause = () => {
      if (useRadioStore.getState().audioElement === audio && audio.paused) observePlaying(false)
    }

    const onError = () => {
      if (useRadioStore.getState().audioElement !== audio || !audio.error) return
      // Try next stream on error
      tryNextStream()
    }

    audio.addEventListener('play', onPlay)
    audio.addEventListener('pause', onPause)
    audio.addEventListener('error', onError)

    return () => {
      audio.removeEventListener('play', onPlay)
      audio.removeEventListener('pause', onPause)
      audio.removeEventListener('error', onError)
    }
  }, [observePlaying, tryNextStream])

  // Sync volume with player store
  useEffect(() => {
    let lastVolume = usePlayerStore.getState().volume
    let lastMuted = usePlayerStore.getState().muted

    const unsub = usePlayerStore.subscribe((state) => {
      const audio = audioRef.current
      if (!audio) return

      if (state.volume !== lastVolume) {
        lastVolume = state.volume
        audio.volume = state.muted ? 0 : state.volume / 100
      }
      if (state.muted !== lastMuted) {
        lastMuted = state.muted
        audio.muted = state.muted
        if (!state.muted) audio.volume = state.volume / 100
      }
    })

    return unsub
  }, [])

  return audioRef
}
