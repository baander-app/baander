import { useEffect, useRef } from 'react'
import { audioService } from '@/features/player/services/audio-service'
import { usePlayerStore, resolveNextIndex, buildStreamUrl, syncPlaybackVolume } from '@/features/player/stores/player-store'
import { updateTime } from '@/features/player/stores/player-time-tracker'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('AudioPlayback')

/** Owns persistent dual audio elements, including transition and teardown work. */
export function useAudioPlayback() {
  const audioRefA = useRef<HTMLAudioElement | null>(null)
  const audioRefB = useRef<HTMLAudioElement | null>(null)
  const lifetimeRef = useRef<{ active: boolean } | null>(null)
  const setAudioElement = usePlayerStore((s) => s.setAudioElement)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)

  useEffect(() => {
    const lifetime = { active: true }
    lifetimeRef.current = lifetime
    const audioA = new Audio()
    const audioB = new Audio()
    audioRefA.current = audioA
    audioRefB.current = audioB
    for (const audio of [audioA, audioB]) {
      audio.crossOrigin = 'anonymous'
      audio.preload = 'auto'
    }
    setAudioElement(audioA)
    audioService.initialize()

    let connection: Promise<void> | undefined
    const connect = () => {
      if (!connection) {
        syncVolume()
        const attempt = Promise.resolve(audioService.connectDualAudioElements(audioA, audioB)).catch((err) => {
          if (lifetime.active && connection === attempt) {
            connection = undefined
            syncVolume()
            if (!audioService.getProcessor()?.isActive) {
              invalidate()
              audioA.pause()
              audioB.pause()
              usePlayerStore.getState().setIsPlaying(false)
              audioService.setPlayingState(false)
            }
          }
          throw err
        }).finally(() => {
          if (lifetime.active) syncVolume()
        })
        connection = attempt
        // The core graph is wired synchronously; EQ reapplication finishes later.
        syncVolume()
      }
      return connection
    }
    const onLoadStart = () => {
      if (lifetime.active && audioA.src) connect().catch((err) => {
        if (lifetime.active) logger.warn('Dual audio connection failed:', err)
      })
    }
    audioA.addEventListener('loadstart', onLoadStart)

    type Candidate = {
      audio: HTMLAudioElement
      owner: HTMLAudioElement
      ownerSrc: string
      currentId: string
      nextId: string
      src: string
      ready: boolean
    }
    let candidate: Candidate | undefined
    let transitionPending = false
    let generation = 0
    let adopting = false
    let fadeTimer: ReturnType<typeof setTimeout> | undefined
    let removeReadyListeners: (() => void) | undefined
    const active = () => usePlayerStore.getState().audioElement
    const inactive = () => active() === audioA ? audioB : audioA
    const syncVolume = () => {
      const { volume, muted } = usePlayerStore.getState()
      syncPlaybackVolume([audioA, audioB], volume, muted)
    }
    syncVolume()

    const invalidate = () => {
      generation++
      transitionPending = false
      removeReadyListeners?.()
      candidate = undefined
      if (fadeTimer !== undefined) clearTimeout(fadeTimer)
      fadeTimer = undefined
      audioService.getProcessor()?.cancelCrossfade()
      inactive().pause()
    }
    const valid = (next: Candidate) => {
      const state = usePlayerStore.getState()
      const index = resolveNextIndex(state.queue, state.currentIndex, state.shuffle, state.repeat, state.shuffleBag)
      return lifetime.active && candidate === next && active() === next.owner
        && next.owner.src === next.ownerSrc && next.audio.src === next.src
        && state.currentTrack?.publicId === next.currentId && state.repeat !== 'one'
        && index !== null && state.queue[index]?.publicId === next.nextId
    }
    const handoff = async (fade: boolean) => {
      const next = candidate
      if (!next?.ready || transitionPending || !valid(next)) return
      const token = generation
      const startingTime = next.owner.currentTime
      transitionPending = true
      try {
        await connect()
        if (token !== generation || !valid(next)) return
        const processor = audioService.getProcessor()
        if (!processor || !processor.isActive || processor.passive) {
          invalidate()
          if (next.owner.ended) usePlayerStore.getState().playNext()
          return
        }
        await next.audio.play()
        if (token !== generation || !valid(next)) {
          if (active() !== next.audio && candidate?.audio !== next.audio) next.audio.pause()
          return
        }
        const remaining = next.owner.duration - next.owner.currentTime
        const state = usePlayerStore.getState()
        if (next.owner.seeking || next.owner.currentTime < startingTime || (fade && (!state.isPlaying || remaining > state.crossfadeDuration))) {
          invalidate()
          return
        }
        const duration = fade ? Math.max(0, Math.min(usePlayerStore.getState().crossfadeDuration, remaining)) : 0
        adopting = true
        const adopted = usePlayerStore.getState().adoptPreloadedNext(next.audio, next.currentId, next.nextId)
        adopting = false
        if (!adopted) { invalidate(); return }
        processor.resetProgramme()
        removeReadyListeners?.()
        candidate = undefined
        if (duration > 0) {
          processor.crossfadeToInactive(duration)
          fadeTimer = setTimeout(() => {
            if (!lifetime.active || generation !== token) return
            fadeTimer = undefined
            next.owner.pause()
            next.owner.currentTime = 0
          }, duration * 1000)
        } else {
          processor.instantSwap()
          next.owner.pause()
          next.owner.currentTime = 0
        }
        audioService.setPlayingState(true)
      } catch (err) {
        if (token !== generation || !lifetime.active) return
        logger.warn('Preloaded playback failed:', err)
        invalidate()
        if (next.owner.ended) usePlayerStore.getState().playNext()
      } finally {
        adopting = false
        if (generation === token) transitionPending = false
      }
    }
    const maybeFade = () => {
      const state = usePlayerStore.getState()
      const audio = active()
      if (audio && state.isPlaying && state.crossfadeEnabled && state.crossfadeDuration > 0
        && audio.duration - audio.currentTime <= state.crossfadeDuration) handoff(true)
    }
    const schedulePreload = (audio: HTMLAudioElement) => {
      if (candidate || transitionPending || fadeTimer !== undefined || !Number.isFinite(audio.duration) || audio.duration <= 0) return
      const processor = audioService.getProcessor()
      if (!processor || processor.passive) return
      const state = usePlayerStore.getState()
      if (state.repeat === 'one' || !state.currentTrack) return
      const threshold = state.crossfadeEnabled ? state.crossfadeDuration + 3 : 6
      if (audio.duration - audio.currentTime >= threshold) return
      const index = resolveNextIndex(state.queue, state.currentIndex, state.shuffle, state.repeat, state.shuffleBag)
      if (index === null) return
      const nextAudio = inactive()
      const next: Candidate = {
        audio: nextAudio, owner: audio, ownerSrc: audio.src,
        currentId: state.currentTrack.publicId, nextId: state.queue[index].publicId,
        src: '', ready: false,
      }
      candidate = next
      const onReady = () => {
        if (!valid(next)) return
        removeReadyListeners?.()
        next.ready = true
        maybeFade()
      }
      const onError = () => { if (candidate === next) invalidate() }
      removeReadyListeners = () => {
        nextAudio.removeEventListener('canplaythrough', onReady)
        nextAudio.removeEventListener('error', onError)
        removeReadyListeners = undefined
      }
      nextAudio.addEventListener('canplaythrough', onReady)
      nextAudio.addEventListener('error', onError)
      nextAudio.src = buildStreamUrl(next.nextId)
      next.src = nextAudio.src
      nextAudio.load()
    }
    const removers = [audioA, audioB].map((audio) => {
      const owned = () => lifetime.active && active() === audio
      const onPlay = () => {
        if (!owned() || audio.paused || audio.ended) return
        usePlayerStore.getState().setIsPlaying(true)
        audioService.setPlayingState(true)
        if (audio.src) connect().catch((err) => {
          if (owned()) logger.warn('Dual audio connection failed:', err)
        })
      }
      const onPause = () => {
        if (!owned() || audio.ended || !audio.paused) return
        usePlayerStore.getState().setIsPlaying(false)
        audioService.setPlayingState(false)
      }
      const onTimeUpdate = () => {
        if (!owned()) return
        updateTime(audio.currentTime)
        if (candidate && !valid(candidate)) invalidate()
        schedulePreload(audio)
        maybeFade()
      }
      const onDurationChange = () => {
        if (owned() && Number.isFinite(audio.duration) && audio.duration > 0) usePlayerStore.getState().setDuration(audio.duration)
      }
      const onEnded = () => {
        if (!owned() || !audio.ended || transitionPending) return
        const state = usePlayerStore.getState()
        if (state.repeat === 'one' && state.currentTrack) {
          audioService.getProcessor()?.resetProgramme()
          state.replayCurrentTrack()
        } else if (candidate?.ready && valid(candidate)) {
          handoff(false)
        } else {
          invalidate()
          state.playNext()
        }
      }
      const onSeeking = () => { if (owned()) invalidate() }
      const onSourceChange = () => {
        if (!owned()) return
        invalidate()
        if (audio.src) audioService.getProcessor()?.resetProgramme()
      }
      const listeners = { seeking: onSeeking, loadstart: onSourceChange, play: onPlay, pause: onPause, timeupdate: onTimeUpdate, durationchange: onDurationChange, ended: onEnded }
      for (const [name, listener] of Object.entries(listeners)) audio.addEventListener(name, listener)
      return () => { for (const [name, listener] of Object.entries(listeners)) audio.removeEventListener(name, listener) }
    })
    const unsubscribe = usePlayerStore.subscribe((state, previous) => {
      if (state.volume !== previous.volume || state.muted !== previous.muted) syncVolume()
      if (!adopting && (state.queue !== previous.queue || state.currentTrack !== previous.currentTrack
        || state.currentIndex !== previous.currentIndex || state.audioElement !== previous.audioElement
        || state.shuffle !== previous.shuffle || state.repeat !== previous.repeat || state.shuffleBag !== previous.shuffleBag
        || state.crossfadeEnabled !== previous.crossfadeEnabled || state.crossfadeDuration !== previous.crossfadeDuration
        || (!state.isPlaying && previous.isPlaying))) invalidate()
    })
    return () => {
      lifetime.active = false
      lifetimeRef.current = null
      unsubscribe()
      invalidate()
      audioA.removeEventListener('loadstart', onLoadStart)
      removers.forEach((remove) => remove())
      audioRefA.current = null
      audioRefB.current = null
      if (active() === audioA || active() === audioB) setAudioElement(null)
      for (const audio of [audioA, audioB]) { audio.pause(); audio.src = '' }
      audioService.destroy()
    }
  }, [setAudioElement])

  const isPlaying = usePlayerStore((s) => s.isPlaying)
  useEffect(() => {
    const audio = usePlayerStore.getState().audioElement
    if (!audio || !audio.src) return
    const src = audio.src
    const track = usePlayerStore.getState().currentTrack
    let cancelled = false
    const lifetime = lifetimeRef.current
    const isActive = () => !cancelled && lifetime?.active === true
      && usePlayerStore.getState().audioElement === audio && audio.src === src
      && usePlayerStore.getState().currentTrack === track
    if (isPlaying && audio.paused) {
      audioService.resumeContextIfNeeded().then(async () => {
        if (isActive() && usePlayerStore.getState().isPlaying) await audio.play()
      }).catch((err) => {
        if (!isActive()) return
        logger.warn('Playback resume failed:', err)
        setIsPlaying(false)
      })
    } else if (!isPlaying) {
      audio.pause()
      audioService.setPlayingState(false)
    }
    return () => { cancelled = true }
  }, [isPlaying, setIsPlaying])

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
      resumeAndReapply().catch((err) => {
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
