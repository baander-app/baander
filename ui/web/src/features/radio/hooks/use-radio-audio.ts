import { useEffect, useRef } from 'react'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { usePlayerStore } from '@/features/player/stores/player-store'

/**
 * Manages the radio audio element lifecycle.
 * Mount once in AppShell so the element persists across navigation.
 */
export function useRadioAudio() {
  const audioRef = useRef<HTMLAudioElement | null>(null)
  const initialized = useRef(false)

  const setAudioElement = useRadioStore((s) => s.setAudioElement)
  const setIsPlaying = useRadioStore((s) => s.setIsPlaying)
  const tryNextStream = useRadioStore((s) => s.tryNextStream)
  const setAllStreamsFailed = useRadioStore((s) => s.setAllStreamsFailed)

  useEffect(() => {
    if (initialized.current) return
    initialized.current = true

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
      setAudioElement(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Wire audio events to radio store
  useEffect(() => {
    const audio = audioRef.current
    if (!audio) return

    const onPlay = () => setIsPlaying(true)
    const onPause = () => setIsPlaying(false)

    const onError = () => {
      // Try next stream on error
      const nextUrl = tryNextStream()
      if (nextUrl === null) {
        setAllStreamsFailed(true)
      }
    }

    audio.addEventListener('play', onPlay)
    audio.addEventListener('pause', onPause)
    audio.addEventListener('error', onError)

    return () => {
      audio.removeEventListener('play', onPlay)
      audio.removeEventListener('pause', onPause)
      audio.removeEventListener('error', onError)
    }
  }, [setIsPlaying, tryNextStream, setAllStreamsFailed])

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
