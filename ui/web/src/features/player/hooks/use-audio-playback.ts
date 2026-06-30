import { useEffect, useRef } from 'react'
import { audioService } from '@/features/player/services/audio-service'
import { usePlayerStore, resolveNextIndex, buildStreamUrl } from '@/features/player/stores/player-store'
import { updateTime } from '@/features/player/stores/player-time-tracker'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('AudioPlayback')

type PreloadState = 'idle' | 'preloading' | 'ready'

/**
 * Manages dual audio elements and AudioService lifecycle for gapless/crossfade playback.
 * Mount once in AppShell above the router outlet so the audio elements
 * persist across page navigation.
 */
export function useAudioPlayback() {
  const audioRefA = useRef<HTMLAudioElement | null>(null)
  const audioRefB = useRef<HTMLAudioElement | null>(null)
  const preloadState = useRef<PreloadState>('idle')
  const preloadedTrackId = useRef<string | null>(null)
  const initialized = useRef(false)

  const setAudioElement = usePlayerStore((s) => s.setAudioElement)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)
  const setDuration = usePlayerStore((s) => s.setDuration)
  const playNext = usePlayerStore((s) => s.playNext)

  useEffect(() => {
    if (initialized.current) return
    initialized.current = true

    // Create dual persistent audio elements
    const audioA = new Audio()
    audioA.crossOrigin = 'anonymous'
    audioA.preload = 'auto'

    const audioB = new Audio()
    audioB.crossOrigin = 'anonymous'
    audioB.preload = 'auto'

    audioRefA.current = audioA
    audioRefB.current = audioB

    // Set volume from persisted store
    const { volume, muted } = usePlayerStore.getState()
    audioA.volume = muted ? 0 : volume / 100
    audioB.volume = 0 // inactive starts silent

    // Wire primary element to player store
    setAudioElement(audioA)

    // Initialize AudioService
    audioService.initialize()

    // Connect processor when first src is set (not available at mount time)
    const dualConnected = { value: false }
    const onLoadStart = () => {
      if (audioA.src && !dualConnected.value) {
        audioService.connectDualAudioElements(audioA, audioB)
        dualConnected.value = true
        audioA.removeEventListener('loadstart', onLoadStart)
      }
    }
    audioA.addEventListener('loadstart', onLoadStart)

    return () => {
      audioA.pause()
      audioA.src = ''
      audioB.pause()
      audioB.src = ''
      audioService.destroy()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Sync store isPlaying → active audio element (store → DOM direction)
  const isPlaying = usePlayerStore((s) => s.isPlaying)

  useEffect(() => {
    const audio = audioRefA.current
    if (!audio || !audio.src) return

    if (isPlaying && audio.paused) {
      audioService.resumeContextIfNeeded().then(() => {
        // Re-check after async resume — user might have paused again
        if (usePlayerStore.getState().isPlaying) {
          audio.play().catch((err) => { logger.warn('Playback resume failed:', err); setIsPlaying(false) })
        }
      })
    } else if (!isPlaying && !audio.paused) {
      audio.pause()
      audioService.setPlayingState(false)
    }
  }, [isPlaying, setIsPlaying])

  // Sync audio element events to player store (DOM → store direction)
  useEffect(() => {
    const audio = audioRefA.current
    if (!audio) return

    const onPlay = () => {
      setIsPlaying(true)
      audioService.setPlayingState(true)
    }
    const onPause = () => {
      setIsPlaying(false)
      audioService.setPlayingState(false)
    }

    const onTimeUpdate = () => {
      updateTime(audio.currentTime)

      // --- Preload scheduler ---
      if (preloadState.current !== 'idle') return
      if (!audio.duration || !isFinite(audio.duration)) return

      const { queue, currentIndex, shuffle, repeat, shuffleBag, crossfadeEnabled, crossfadeDuration } = usePlayerStore.getState()
      const PRELOAD_THRESHOLD_GAPLESS = 6
      const PRELOAD_BUFFER = 3
      const threshold = crossfadeEnabled ? crossfadeDuration + PRELOAD_BUFFER : PRELOAD_THRESHOLD_GAPLESS

      if (audio.duration - audio.currentTime < threshold) {
        const nextIdx = resolveNextIndex(queue, currentIndex, shuffle, repeat, shuffleBag)
        if (nextIdx !== null) {
          const nextTrack = queue[nextIdx]
          const processor = audioService.getProcessor()
          const inactiveAudio = processor?.getActiveSource() === 'A'
            ? audioRefB.current
            : audioRefA.current

          if (inactiveAudio && nextTrack) {
            inactiveAudio.src = buildStreamUrl(nextTrack.publicId)
            inactiveAudio.preload = 'auto'
            preloadState.current = 'preloading'
            preloadedTrackId.current = nextTrack.publicId

            inactiveAudio.addEventListener('canplaythrough', function onReady() {
              inactiveAudio.removeEventListener('canplaythrough', onReady)
              preloadState.current = 'ready'
            }, { once: true })

            inactiveAudio.addEventListener('error', function onError() {
              inactiveAudio.removeEventListener('error', onError)
              preloadState.current = 'idle'
              preloadedTrackId.current = null
            }, { once: true })
          }
        }
      }
    }

    const onDurationChange = () => {
      if (audio.duration && isFinite(audio.duration)) {
        setDuration(audio.duration)
      }
    }

    const onEnded = () => {
      const { repeat, currentTrack, crossfadeEnabled, crossfadeDuration } = usePlayerStore.getState()

      // Repeat-one: restart current track
      if (repeat === 'one' && currentTrack) {
        audio.currentTime = 0
        audio.play().catch((err) => { logger.warn('Repeat-one resume failed:', err) })
        return
      }

      // Gapless / crossfade transition
      const processor = audioService.getProcessor()
      if (preloadState.current === 'ready' && processor) {
        const activeSrc = processor.getActiveSource()
        const inactiveAudio = activeSrc === 'A' ? audioRefB.current! : audioRefA.current!
        const activeAudio = activeSrc === 'A' ? audioRefA.current! : audioRefB.current!

        if (crossfadeEnabled && crossfadeDuration > 0) {
          processor.crossfadeToInactive(crossfadeDuration)
        } else {
          processor.instantSwap()
        }

        inactiveAudio.play().catch((err) => { logger.warn('Crossfade playback failed:', err) })
        activeAudio.pause()
        activeAudio.currentTime = 0

        playNext()

        // Reset preload state for next track
        preloadState.current = 'idle'
        preloadedTrackId.current = null
      } else {
        // Fallback: normal playback with audible gap
        playNext()
      }
    }

    audio.addEventListener('play', onPlay)
    audio.addEventListener('pause', onPause)
    audio.addEventListener('timeupdate', onTimeUpdate)
    audio.addEventListener('durationchange', onDurationChange)
    audio.addEventListener('ended', onEnded)

    return () => {
      audio.removeEventListener('play', onPlay)
      audio.removeEventListener('pause', onPause)
      audio.removeEventListener('timeupdate', onTimeUpdate)
      audio.removeEventListener('durationchange', onDurationChange)
      audio.removeEventListener('ended', onEnded)
    }
  }, [setIsPlaying, setDuration, playNext, updateTime])

  // Resume AudioContext + re-apply EQ on user interaction
  // Browsers suspend AudioContext until first user gesture.
  // After resume, EQ params set while suspended need to be refreshed.
  useEffect(() => {
    const resumeAndReapply = async () => {
      await audioService.resumeContextIfNeeded()
      // Re-apply persisted EQ state now that the context is running
      const { reapplyAllEqState } = await import('@/features/equalizer/stores/eq-reapply')
      reapplyAllEqState()
    }

    const onFirstInteraction = () => {
      resumeAndReapply()
      document.removeEventListener('click', onFirstInteraction)
      document.removeEventListener('keydown', onFirstInteraction)
    }

    document.addEventListener('click', onFirstInteraction)
    document.addEventListener('keydown', onFirstInteraction)

    return () => {
      document.removeEventListener('click', onFirstInteraction)
      document.removeEventListener('keydown', onFirstInteraction)
    }
  }, [])

  return audioRefA
}
