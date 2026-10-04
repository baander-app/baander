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
  const lifetimeRef = useRef<{ active: boolean } | null>(null)

  const setAudioElement = usePlayerStore((s) => s.setAudioElement)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)
  const setDuration = usePlayerStore((s) => s.setDuration)
  const playNext = usePlayerStore((s) => s.playNext)

  useEffect(() => {
    const lifetime = { active: true }
    lifetimeRef.current = lifetime

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
      if (lifetime.active && audioA.src && !dualConnected.value) {
        audioService.connectDualAudioElements(audioA, audioB)
        dualConnected.value = true
        audioA.removeEventListener('loadstart', onLoadStart)
      }
    }
    audioA.addEventListener('loadstart', onLoadStart)

    return () => {
      lifetime.active = false
      lifetimeRef.current = null
      audioA.removeEventListener('loadstart', onLoadStart)
      audioRefA.current = null
      audioRefB.current = null
      preloadState.current = 'idle'
      preloadedTrackId.current = null
      const storedAudio = usePlayerStore.getState().audioElement
      if (storedAudio === audioA || storedAudio === audioB) setAudioElement(null)
      audioA.pause()
      audioA.src = ''
      audioB.pause()
      audioB.src = ''
      audioService.destroy()
    }
  }, [setAudioElement])

  // Sync store isPlaying → active audio element (store → DOM direction)
  const isPlaying = usePlayerStore((s) => s.isPlaying)

  useEffect(() => {
    const audio = audioRefA.current
    if (!audio || !audio.src) return

    let cancelled = false
    const lifetime = lifetimeRef.current
    const isActive = () => !cancelled && lifetime?.active === true && audioRefA.current === audio

    if (isPlaying && audio.paused) {
      audioService.resumeContextIfNeeded().then(() => {
        // Re-check after async resume — user might have paused again
        if (isActive() && usePlayerStore.getState().isPlaying) {
          audio.play().catch((err) => {
            if (!isActive()) return
            logger.warn('Playback resume failed:', err)
            setIsPlaying(false)
          })
        }
      }).catch((err) => {
        if (!isActive()) return
        logger.warn('Audio context resume failed:', err)
        setIsPlaying(false)
      })
    } else if (!isPlaying && !audio.paused) {
      audio.pause()
      audioService.setPlayingState(false)
    }
    return () => { cancelled = true }
  }, [isPlaying, setIsPlaying])

  // Sync audio element events to player store (DOM → store direction)
  useEffect(() => {
    const audio = audioRefA.current
    if (!audio) return

    const lifetime = lifetimeRef.current
    let removePreloadListeners: (() => void) | undefined

    const onPlay = () => {
      if (!lifetime?.active) return
      setIsPlaying(true)
      audioService.setPlayingState(true)
    }
    const onPause = () => {
      if (!lifetime?.active) return
      setIsPlaying(false)
      audioService.setPlayingState(false)
    }

    const onTimeUpdate = () => {
      if (!lifetime?.active) return
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

            removePreloadListeners?.()
            const onReady = () => {
              removePreloadListeners?.()
              if (lifetime?.active) preloadState.current = 'ready'
            }
            const onError = () => {
              removePreloadListeners?.()
              if (!lifetime?.active) return
              preloadState.current = 'idle'
              preloadedTrackId.current = null
            }
            removePreloadListeners = () => {
              inactiveAudio.removeEventListener('canplaythrough', onReady)
              inactiveAudio.removeEventListener('error', onError)
              removePreloadListeners = undefined
            }
            inactiveAudio.addEventListener('canplaythrough', onReady, { once: true })
            inactiveAudio.addEventListener('error', onError, { once: true })
          }
        }
      }
    }

    const onDurationChange = () => {
      if (!lifetime?.active) return
      if (audio.duration && isFinite(audio.duration)) {
        setDuration(audio.duration)
      }
    }

    const onEnded = () => {
      if (!lifetime?.active) return
      const { repeat, currentTrack, crossfadeEnabled, crossfadeDuration } = usePlayerStore.getState()

      // Repeat-one: restart current track
      if (repeat === 'one' && currentTrack) {
        audio.currentTime = 0
        audio.play().catch((err) => {
          if (lifetime?.active) logger.warn('Repeat-one resume failed:', err)
        })
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

        inactiveAudio.play().catch((err) => {
          if (lifetime?.active) logger.warn('Crossfade playback failed:', err)
        })
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
      removePreloadListeners?.()
      audio.removeEventListener('play', onPlay)
      audio.removeEventListener('pause', onPause)
      audio.removeEventListener('timeupdate', onTimeUpdate)
      audio.removeEventListener('durationchange', onDurationChange)
      audio.removeEventListener('ended', onEnded)
    }
  }, [setIsPlaying, setDuration, playNext])

  // Resume AudioContext + re-apply EQ on user interaction
  // Browsers suspend AudioContext until first user gesture.
  // After resume, EQ params set while suspended need to be refreshed.
  useEffect(() => {
    let cancelled = false
    const resumeAndReapply = async () => {
      await audioService.resumeContextIfNeeded()
      if (cancelled) return
      // Re-apply persisted EQ state now that the context is running
      const { reapplyAllEqState } = await import('@/features/equalizer/stores/eq-reapply')
      if (!cancelled) reapplyAllEqState()
    }

    const onFirstInteraction = () => {
      void resumeAndReapply().catch((err) => {
        if (!cancelled) logger.warn('Audio context resume failed:', err)
      })
      document.removeEventListener('click', onFirstInteraction)
      document.removeEventListener('keydown', onFirstInteraction)
    }

    document.addEventListener('click', onFirstInteraction)
    document.addEventListener('keydown', onFirstInteraction)

    return () => {
      cancelled = true
      document.removeEventListener('click', onFirstInteraction)
      document.removeEventListener('keydown', onFirstInteraction)
    }
  }, [])

  return audioRefA
}
