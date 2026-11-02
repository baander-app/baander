import { useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { PLAYBACK_SESSION_KEY } from '@/features/session/services/playback-session-coordinator'
import { toast } from 'sonner'
import { getSongIndex } from '@/shared/api-client/gen/endpoints'
import { readTrackResource } from '../stores/player-availability'
import type { Track } from '../stores/player-types'
import { usePlaybackSession } from '@/features/session/hooks/use-playback-session'
import { registerPlaybackAdapter, requestPlayback } from '@/features/session/services/playback-execution'
import { createMusicPlaybackAdapter } from '../services/music-playback-adapter'
import { audioService } from '@/features/player/services/audio-service'
import { usePlayerStore, resolveNextIndex, buildStreamUrl, syncPlaybackVolume, getPlaybackSelectionGeneration } from '@/features/player/stores/player-store'
import { updateTime } from '@/features/player/stores/player-time-tracker'
import {
  chooseTranscodeFormat,
  isTrackStream,
  isTranscodedStream,
  isUnsupportedOriginalStream,
  notifyPlaybackFailure,
} from '@/features/player/stores/player-playback-runtime'
import { musicNavigation } from '../stores/player-intent-actions'
import { isRecoveringPlayback } from '@/features/player/stores/player-playback-recovery'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('AudioPlayback')

/**
 * Chromium retries a cut stream internally and can then wait without ever reporting an error.
 * Playback that makes no progress for this long after `waiting` or `stalled` counts as interrupted.
 */
const STALL_TIMEOUT_MS = 10_000

/** Owns persistent dual audio elements, including transition and teardown work. */
export function useAudioPlayback() {
  const queryClient = useQueryClient()
  const { snapshot, endpoint, connected } = usePlaybackSession()
  useEffect(() => {
    if (!snapshot || snapshot.activeIncarnationId === endpoint?.incarnationId) return
    const state = usePlayerStore.getState()
    const known = new Map(state.queue.map(track => [track.publicId, track]))
    const sameQueue = state.queue.length === snapshot.queue.length
      && state.queue.every((track, index) => track.publicId === snapshot.queue[index])
    const queue = sameQueue ? state.queue : snapshot.queue.map(id => known.get(id) ?? { publicId: id, title: id })
    const currentIndex = queue.length ? snapshot.currentTrackIndex : -1
    const selectedDuration = (track: Track | undefined) => snapshot.media?.kind === 'song'
      && track?.duration !== undefined && Number.isFinite(track.duration) && track.duration > 0 ? track.duration : 0
    state.runtimeActions.observePlaybackState({ queue, currentIndex, currentTrack: queue[currentIndex] ?? null,
      duration: selectedDuration(queue[currentIndex]),
      isPlaying: snapshot.media?.kind === 'song' && snapshot.observedState === 'playing' })
    updateTime(snapshot.position)
    const destination = snapshot.destinations.find(item => item.destinationId === snapshot.activeDestinationId)
    if (destination?.observedVolume !== null && destination?.observedVolume !== undefined) {
      state.runtimeActions.observeListeningVolume(destination.observedVolume * 100)
    }
    const missing = queue.filter(track => track.available === undefined || track.duration === undefined).map(track => track.publicId)
    const controller = new AbortController()
    const observedAt = performance.now()
    const tick = () => {
      const current = usePlayerStore.getState()
      if (current.playbackExecutionActive || queryClient.getQueryData(PLAYBACK_SESSION_KEY) !== snapshot) return
      const projected = snapshot.position + (performance.now() - observedAt) / 1000
      updateTime(current.duration > 0 ? Math.min(projected, current.duration) : projected)
    }
    const timer = connected && destination?.available !== false && snapshot.activeIncarnationId
      && snapshot.media?.kind === 'song' && snapshot.observedState === 'playing'
      ? setInterval(tick, 250) : undefined
    if (missing.length) {
      const batches: string[][] = []
      for (let offset = 0; offset < missing.length; offset += 100) batches.push(missing.slice(offset, offset + 100))
      Promise.all(batches.map(ids => getSongIndex({ publicIds: ids.join(','), limit: ids.length }, { signal: controller.signal }))).then(responses => {
        if (controller.signal.aborted || queryClient.getQueryData(PLAYBACK_SESSION_KEY) !== snapshot
          || usePlayerStore.getState().playbackExecutionActive) return
        const tracks = new Map<string, Track>()
        for (const entry of responses.flatMap(response => response.data ?? [])) {
          const track = readTrackResource(entry)
          if (track) tracks.set(track.publicId, track)
        }
        const hydrated = queue.map(track => tracks.get(track.publicId) ?? track)
        usePlayerStore.getState().runtimeActions.observePlaybackState({ queue: hydrated, currentTrack: hydrated[currentIndex] ?? null,
          duration: selectedDuration(hydrated[currentIndex]) })
      }).catch(error => {
        if (!controller.signal.aborted) logger.warn('Queue details could not be loaded:', error)
      })
    }
    return () => {
      controller.abort()
      if (timer !== undefined) clearInterval(timer)
    }
  }, [snapshot, endpoint?.incarnationId, connected, queryClient])
  const audioRefA = useRef<HTMLAudioElement | null>(null)
  const audioRefB = useRef<HTMLAudioElement | null>(null)
  const lifetimeRef = useRef<{ active: boolean; executionOwned: boolean } | null>(null)
  const setAudioElement = usePlayerStore((s) => s.setAudioElement)
  const setIsPlaying = usePlayerStore((s) => s.runtimeActions.setIsPlaying)

  useEffect(() => {
    const lifetime = { active: true, executionOwned: usePlayerStore.getState().playbackExecutionActive }
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
              usePlayerStore.getState().runtimeActions.setIsPlaying(false)
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
    let navigationPending = false
    let generation = 0
    let adopting = false
    let fadeTimer: ReturnType<typeof setTimeout> | undefined
    let removeReadyListeners: (() => void) | undefined
    const clearStallTimers = new Set<() => void>()
    const active = () => usePlayerStore.getState().audioElement
    const inactive = () => active() === audioA ? audioB : audioA
    const syncVolume = () => {
      const { volume, muted } = usePlayerStore.getState()
      syncPlaybackVolume([audioA, audioB], volume, muted)
    }
    syncVolume()

    const invalidate = () => {
      generation++
      for (const clear of clearStallTimers) clear()
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
      return lifetime.active && lifetime.executionOwned && candidate === next && active() === next.owner
        && next.owner.src === next.ownerSrc && next.audio.src === next.src
        && state.currentTrack?.publicId === next.currentId && state.repeat !== 'one'
        && index !== null && state.queue[index]?.publicId === next.nextId
    }
    const handoff = async (fade: boolean, executing = false) => {
      if (!executing) {
        if (navigationPending || !candidate?.ready || !valid(candidate)) return
        navigationPending = true
        try { await requestPlayback({ action: 'next', navigation: musicNavigation(usePlayerStore.getState()) }) }
        catch (error) {
          logger.warn('Queue advance failed:', error)
          toast.error('Playback request failed', { id: 'player-playback-request', description: error instanceof Error ? error.message : undefined })
        }
        finally { navigationPending = false }
        return
      }
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
        throw err
      } finally {
        adopting = false
        if (generation === token) transitionPending = false
      }
    }
    const maybeFade = () => {
      const state = usePlayerStore.getState()
      const audio = active()
      if (lifetime.executionOwned && audio && state.isPlaying && state.crossfadeEnabled && state.crossfadeDuration > 0
        && audio.duration - audio.currentTime <= state.crossfadeDuration) handoff(true)
    }
    const schedulePreload = (audio: HTMLAudioElement) => {
      if (!lifetime.executionOwned || candidate || transitionPending || fadeTimer !== undefined || !Number.isFinite(audio.duration) || audio.duration <= 0) return
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
      const onError = () => {
        if (candidate !== next) return
        if (isUnsupportedOriginalStream(nextAudio, next.nextId)) {
          nextAudio.src = buildStreamUrl(next.nextId, chooseTranscodeFormat(nextAudio))
          next.src = nextAudio.src
          nextAudio.load()
          return
        }
        invalidate()
      }
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
      const owned = () => lifetime.active && lifetime.executionOwned && active() === audio
      const recovering = () => isRecoveringPlayback(audio)
      // Error wins: Firefox reports a cut stream as an error, then plays its buffer and fires ended.
      let loadFailed = false
      let stallTimer: ReturnType<typeof setTimeout> | undefined
      let stallPosition = 0
      const clearStall = () => {
        if (stallTimer !== undefined) clearTimeout(stallTimer)
        stallTimer = undefined
      }
      clearStallTimers.add(clearStall)
      /** The current track's own stream can be retried; any other source fails as before. */
      const playsCurrentTrack = () => {
        const track = usePlayerStore.getState().currentTrack
        return track !== null && isTrackStream(audio.src, track.publicId)
      }
      const failPlayback = () => {
        if (!owned()) return
        const state = usePlayerStore.getState()
        if (state.currentTrack && isUnsupportedOriginalStream(audio, state.currentTrack.publicId)) {
          logger.info('Original format is not playable; requesting a transcoded stream')
          state.switchToTranscodedStream()
          return
        }
        logger.warn('Active audio playback failed:', audio.error)
        audio.pause()
        state.runtimeActions.setIsPlaying(false)
        audioService.setPlayingState(false)
        notifyPlaybackFailure(isTranscodedStream(audio.src))
      }
      const onPlay = () => {
        if (!owned() || audio.paused || audio.ended) return
        usePlayerStore.getState().runtimeActions.setIsPlaying(true)
        audioService.setPlayingState(true)
        if (audio.src) connect().catch((err) => {
          if (owned()) logger.warn('Dual audio connection failed:', err)
        })
      }
      const onPause = () => {
        if (!owned() || audio.ended || !audio.paused) return
        clearStall()
        if (recovering()) return
        usePlayerStore.getState().runtimeActions.setIsPlaying(false)
        audioService.setPlayingState(false)
      }
      const onError = () => {
        if (audio.error) loadFailed = true
        if (!owned() || !audio.error) return
        clearStall()
        invalidate()
        const { code, MEDIA_ERR_NETWORK, MEDIA_ERR_SRC_NOT_SUPPORTED } = audio.error
        // A server outage surfaces as a network error, or as an unsupported source when the
        // request itself fails. The probe in the recovery tells it from a broken track.
        if (recovering() || ((code === MEDIA_ERR_NETWORK || code === MEDIA_ERR_SRC_NOT_SUPPORTED) && playsCurrentTrack())) {
          usePlayerStore.getState().interruptPlayback(audio, 'error', failPlayback)
          return
        }
        failPlayback()
      }
      const onStall = () => {
        if (!owned() || audio.paused || stallTimer !== undefined || recovering()) return
        if (!usePlayerStore.getState().isPlaying || !playsCurrentTrack()) return
        stallPosition = audio.currentTime
        stallTimer = setTimeout(() => {
          stallTimer = undefined
          if (!owned() || audio.paused || recovering() || audio.currentTime !== stallPosition) return
          if (!usePlayerStore.getState().isPlaying || !playsCurrentTrack()) return
          invalidate()
          usePlayerStore.getState().interruptPlayback(audio, 'stall', failPlayback)
        }, STALL_TIMEOUT_MS)
      }
      const onTimeUpdate = () => {
        if (!owned()) return
        if (audio.currentTime !== stallPosition) clearStall()
        // The recovery holds the saved position; a failed element may report 0.
        if (recovering()) return
        updateTime(audio.currentTime)
        if (candidate && !valid(candidate)) invalidate()
        schedulePreload(audio)
        maybeFade()
      }
      const onDurationChange = () => {
        if (owned() && Number.isFinite(audio.duration) && audio.duration > 0) usePlayerStore.getState().setDuration(audio.duration)
      }
      const onEnded = () => {
        if (!owned() || !audio.ended || transitionPending || loadFailed || recovering()) return
        const state = usePlayerStore.getState()
        if (state.repeat === 'one' && state.currentTrack) {
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
        loadFailed = false
        clearStall()
        if (!owned()) return
        invalidate()
        // Resuming the same track at its position continues the same programme.
        if (audio.src && !recovering()) audioService.getProcessor()?.resetProgramme()
      }
      const listeners = {
        seeking: onSeeking, loadstart: onSourceChange, play: onPlay, pause: onPause, playing: clearStall,
        waiting: onStall, stalled: onStall, timeupdate: onTimeUpdate, durationchange: onDurationChange,
        ended: onEnded, error: onError,
      }
      for (const [name, listener] of Object.entries(listeners)) audio.addEventListener(name, listener)
      return () => {
        clearStall()
        clearStallTimers.delete(clearStall)
        for (const [name, listener] of Object.entries(listeners)) audio.removeEventListener(name, listener)
      }
    })
    const unregisterAdapter = registerPlaybackAdapter(createMusicPlaybackAdapter({
      elements: [audioA, audioB], invalidate, connect,
      preparedAudio: id => candidate?.ready && candidate.nextId === id && valid(candidate) ? candidate.audio : undefined,
      transition: async (id, signal) => {
        const next = candidate
        if (!next?.ready || next.nextId !== id || !valid(next)) return false
        const state = usePlayerStore.getState()
        const cancel = () => {
          invalidate()
          audioA.pause()
          audioB.pause()
          lifetime.executionOwned = false
          usePlayerStore.getState().runtimeActions.setPlaybackExecutionActive(false)
          usePlayerStore.getState().runtimeActions.setIsPlaying(false)
          audioService.setPlayingState(false)
        }
        signal.addEventListener('abort', cancel, { once: true })
        try {
          if (signal.aborted) { cancel(); return false }
          await handoff(state.crossfadeEnabled && !next.owner.ended, true)
          return !signal.aborted && active() === next.audio
        } finally {
          signal.removeEventListener('abort', cancel)
        }
      },
      isAlive: () => lifetime.active,
      setExecutionOwned: owned => {
        lifetime.executionOwned = owned
        usePlayerStore.getState().runtimeActions.setPlaybackExecutionActive(owned)
      },
    }))
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
      unregisterAdapter()
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
    const generation = getPlaybackSelectionGeneration()
    let cancelled = false
    const lifetime = lifetimeRef.current
    const isActive = () => !cancelled && lifetime?.active === true && lifetime.executionOwned
      && getPlaybackSelectionGeneration() === generation
      && usePlayerStore.getState().audioElement === audio && audio.src === src
      && usePlayerStore.getState().currentTrack === track
    if (lifetime?.executionOwned && isPlaying && audio.paused && !isRecoveringPlayback(audio)) {
      audioService.resumeContextIfNeeded().then(async () => {
        if (isActive() && usePlayerStore.getState().isPlaying) {
          await audio.play()
          if ((!isActive() || !usePlayerStore.getState().isPlaying) && audio.src === src) audio.pause()
        }
      }).catch((err) => {
        if (!isActive()) return
        logger.warn('Playback resume failed:', err)
        setIsPlaying(false)
      })
    } else if (lifetime?.executionOwned && !isPlaying) {
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
