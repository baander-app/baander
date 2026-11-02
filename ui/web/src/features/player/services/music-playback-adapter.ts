import { getSongIndex, type PlaybackCommandResource } from '@/shared/api-client/gen/endpoints'
import type { PlaybackExecutionAdapter, PlaybackObservation } from '@/features/session/services/playback-execution'
import { readTrackResource } from '../stores/player-availability'
import { usePlayerStore } from '../stores/player-store'
import { getCurrentTime, updateTime } from '../stores/player-time-tracker'
import { buildStreamUrl, canSeekStream, chooseTranscodeFormat, invalidatePlaybackSelection, isUnsupportedOriginalStream, isTrackStream } from '../stores/player-playback-runtime'
import type { Track } from '../stores/player-types'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { audioService } from './audio-service'
import { activityService } from './activity-service'

type Payload = PlaybackCommandResource['payload']
interface PreparedMusic {
  payload: Payload
  queue: Track[]
  audio: HTMLAudioElement
  src: string
  preloaded: boolean
}
interface Runtime {
  elements: [HTMLAudioElement, HTMLAudioElement]
  invalidate: () => void
  connect: () => Promise<void>
  preparedAudio: (id: string) => HTMLAudioElement | undefined
  transition: (id: string, signal: AbortSignal) => Promise<boolean>
  isAlive: () => boolean
  setExecutionOwned: (owned: boolean) => void
}

const aborted = () => new DOMException('Playback execution was cancelled.', 'AbortError')

function withAbort<T>(work: Promise<T>, signal: AbortSignal): Promise<T> {
  if (signal.aborted) return Promise.reject(aborted())
  return new Promise((resolve, reject) => {
    const cancel = () => reject(aborted())
    signal.addEventListener('abort', cancel, { once: true })
    work.then(resolve, reject).finally(() => signal.removeEventListener('abort', cancel))
  })
}

function existingSourceReady(audio: HTMLAudioElement, signal: AbortSignal): Promise<void> {
  if (audio.error) return Promise.reject(new Error('The current music source failed.'))
  if (audio.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA) return Promise.resolve()
  return new Promise((resolve, reject) => {
    const cleanup = () => {
      audio.removeEventListener('canplay', ready)
      audio.removeEventListener('error', fail)
      signal.removeEventListener('abort', cancel)
    }
    const ready = () => { cleanup(); resolve() }
    const fail = () => { cleanup(); reject(new Error('The current music source failed.')) }
    const cancel = () => { cleanup(); reject(aborted()) }
    audio.addEventListener('canplay', ready, { once: true })
    audio.addEventListener('error', fail, { once: true })
    signal.addEventListener('abort', cancel, { once: true })
  })
}

export function createMusicPlaybackAdapter(runtime: Runtime): PlaybackExecutionAdapter {
  const chromium = /Chrom(?:e|ium)\//.test(navigator.userAgent) && !/CriOS|Edg\/|OPR\//.test(navigator.userAgent)
  const eligible = chromium && runtime.elements.every(element => {
    if (!('disableRemotePlayback' in element)) return false
    element.disableRemotePlayback = true
    return element.disableRemotePlayback
  })
  let lifecycleFenced = false
  let generation = 0
  let stopped = false
  let prepared: PreparedMusic | undefined
  const check = (signal: AbortSignal, token: number) => {
    if (signal.aborted || lifecycleFenced || !runtime.isAlive() || token !== generation) throw aborted()
  }
  const observe = (): PlaybackObservation => {
    const state = usePlayerStore.getState()
    const audio = state.audioElement
    return { observedState: stopped || !audio?.src ? 'stopped' : audio.paused ? 'paused' : 'playing',
      position: audio?.src ? audio.currentTime : getCurrentTime(), volume: state.muted ? 0 : state.volume / 100 }
  }
  const stopPlayback = () => {
    stopped = false
    generation++
    runtime.setExecutionOwned(false)
    invalidatePlaybackSelection()
    runtime.invalidate()
    for (const audio of runtime.elements) audio.pause()
    usePlayerStore.getState().runtimeActions.setIsPlaying(false)
    audioService.setPlayingState(false)
    return observe()
  }
  const resolveTracks = async (ids: string[], signal: AbortSignal, token: number): Promise<Track[]> => {
    const cached = new Map(usePlayerStore.getState().queue.map(track => [track.publicId, track]))
    const missing = [...new Set(ids.filter(id => cached.get(id)?.available === undefined))]
    for (let offset = 0; offset < missing.length; offset += 100) {
      const batch = missing.slice(offset, offset + 100)
      const response = await getSongIndex({ publicIds: batch.join(','), limit: batch.length }, { signal })
      check(signal, token)
      for (const entry of response.data ?? []) {
        const track = readTrackResource(entry)
        if (track) cached.set(track.publicId, track)
      }
    }
    return ids.map(id => cached.get(id) ?? { publicId: id, title: id, available: false })
  }
  const retainPrepared = (payload: Payload, queue: Track[], audio: HTMLAudioElement, preloaded: boolean) => {
    if (payload.position > 0 && !canSeekStream(audio)) throw new Error('This stream cannot resume at the requested position yet.')
    prepared = { payload, queue, audio, src: audio.src, preloaded }
  }
  const prepare = async (payload: Payload, signal: AbortSignal, allowPreloaded = true) => {
    const token = ++generation
    check(signal, token)
    if (payload.media?.kind !== 'song') throw new Error('No music track was selected.')
    const ids = payload.queue.length ? payload.queue : [payload.media.publicId]
    const queue = await resolveTracks(ids, signal, token)
    const track = queue[payload.queue.length ? payload.currentTrackIndex : 0]
    const retainedQueue = payload.queue.length ? queue : []
    if (!track || track.publicId !== payload.media.publicId || track.available !== true) throw new Error('This track is unavailable.')
    const selected = usePlayerStore.getState().audioElement
    if (selected && isTrackStream(selected.src, track.publicId)) {
      await existingSourceReady(selected, signal)
      check(signal, token)
      retainPrepared(payload, retainedQueue, selected, false)
      return
    }
    const preloaded = allowPreloaded ? runtime.preparedAudio(track.publicId) : undefined
    if (preloaded) {
      retainPrepared(payload, retainedQueue, preloaded, true)
      return
    }
    runtime.invalidate()
    const current = usePlayerStore.getState().audioElement
    const processor = audioService.getProcessor()
    const alternate = current === runtime.elements[0] ? runtime.elements[1] : runtime.elements[0]
    const needsAlternate = (processor?.isActive && !processor.passive) || (current?.src && !current.ended)
    const audio = needsAlternate ? alternate : current ?? runtime.elements[0]
    audio.pause()
    const readiness = new Promise<void>((resolve, reject) => {
      const cleanup = () => {
        audio.removeEventListener('canplay', ready)
        audio.removeEventListener('error', failed)
        signal.removeEventListener('abort', cancel)
      }
      const ready = () => { cleanup(); resolve() }
      const cancel = () => { cleanup(); audio.pause(); reject(aborted()) }
      const failed = () => {
        if (isUnsupportedOriginalStream(audio, track.publicId)) {
          audio.src = buildStreamUrl(track.publicId, chooseTranscodeFormat(audio))
          audio.load()
          return
        }
        cleanup()
        reject(new Error('This track could not be prepared.'))
      }
      audio.addEventListener('canplay', ready)
      audio.addEventListener('error', failed)
      signal.addEventListener('abort', cancel, { once: true })
      audio.src = buildStreamUrl(track.publicId)
      audio.load()
      if (audio.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA) ready()
    })
    await readiness
    check(signal, token)
    retainPrepared(payload, retainedQueue, audio, false)
  }
  const seek = async (audio: HTMLAudioElement, position: number, signal: AbortSignal, token: number) => {
    check(signal, token)
    if (Math.abs(audio.currentTime - position) < 0.01) {
      updateTime(audio.currentTime)
      return
    }
    if (!canSeekStream(audio)) throw new Error('Seeking is not available for this stream yet.')
    await new Promise<void>((resolve, reject) => {
      const cleanup = () => {
        audio.removeEventListener('seeked', done)
        audio.removeEventListener('error', fail)
        signal.removeEventListener('abort', cancel)
      }
      const done = () => { cleanup(); resolve() }
      const fail = () => { cleanup(); reject(new Error('The playback seek failed.')) }
      const cancel = () => { cleanup(); reject(aborted()) }
      audio.addEventListener('seeked', done, { once: true })
      audio.addEventListener('error', fail, { once: true })
      signal.addEventListener('abort', cancel, { once: true })
      try { audio.currentTime = position } catch (error) { cleanup(); reject(error) }
    })
    check(signal, token)
    updateTime(audio.currentTime)
  }
  return {
    kind: 'song', supportsVolume: true, prepare, observe,
    quiesce: async () => stopPlayback(),
    localRecovery: {
      eligible,
      fence: () => {
        if (!runtime.isAlive()) return null
        lifecycleFenced = true
        prepared = undefined
        let successful = true
        try { audioService.fenceOutput() } catch { successful = false }
        try { useRadioStore.getState().stopRadio() } catch { successful = false }
        try { stopPlayback() } catch {
          successful = false
          for (const element of runtime.elements) {
            try { element.pause() } catch { /* A failed pause cannot establish a stop proof. */ }
          }
        }
        stopped = true
        return successful && runtime.elements.every(element => element.paused) ? observe() : null
      },
      rearmAfterRegistration: () => {
        if (!runtime.isAlive()) return
        generation++
        lifecycleFenced = false
        audioService.rearmOutput()
      },
    },
    execute: async (command, signal) => {
      const token = generation
      check(signal, token)
      const { payload, type } = command
      if (type === 'observe') return observe()
      if (type === 'stop' || type === 'pause' || type === 'quiesce') {
        stopPlayback()
        stopped = type === 'stop'
        return observe()
      }
      if (type === 'queue') {
        const state = usePlayerStore.getState()
        const queue = await resolveTracks(payload.queue, signal, token)
        check(signal, token)
        state.runtimeActions.applyQueueSnapshot(queue, payload.currentTrackIndex)
        return observe()
      }
      let state = usePlayerStore.getState()
      if (type === 'volume') {
        state.runtimeActions.applyPreferences({ volume: (payload.volume ?? 1) * 100, muted: false })
        return observe()
      }
      if (type === 'play' || type === 'load' || type === 'start') {
        useRadioStore.getState().stopRadio()
        stopped = false
        runtime.setExecutionOwned(true)
      }
      if (type === 'load' || type === 'start') {
        if (!prepared || prepared.payload.media?.publicId !== payload.media?.publicId
          || prepared.payload.currentTrackIndex !== payload.currentTrackIndex
          || prepared.payload.queue.length !== payload.queue.length
          || prepared.payload.queue.some((id, index) => id !== payload.queue[index])) await prepare(payload, signal)
        const preparationToken = generation
        check(signal, preparationToken)
        let next = prepared!
        if (next.preloaded) {
          await withAbort(runtime.connect(), signal)
          const processor = audioService.getProcessor()
          if (!processor?.isActive || processor.passive) {
            await prepare(payload, signal, false)
            next = prepared!
          }
        }
        if (next.src !== next.audio.src) throw new Error('Prepared music source was replaced.')
        if (next.preloaded && payload.position === 0 && payload.desiredState === 'playing') {
          if (!await withAbort(runtime.transition(payload.media!.publicId, signal), signal)) throw new Error('Playback transition was cancelled.')
          check(signal, preparationToken)
          prepared = undefined
          return observe()
        }
        stopPlayback()
        runtime.setExecutionOwned(true)
        state = usePlayerStore.getState()
        const oldAudio = state.audioElement
        state.runtimeActions.setAudioElement(next.audio)
        state.runtimeActions.applyQueueSnapshot(next.queue, payload.currentTrackIndex)
        state.runtimeActions.setDuration(Number.isFinite(next.audio.duration) && next.audio.duration > 0 ? next.audio.duration : 0)
        updateTime(next.audio.currentTime)
        audioService.getProcessor()?.resetProgramme()
        if (oldAudio !== next.audio) audioService.getProcessor()?.instantSwap()
        prepared = undefined
      }
      const executionToken = generation
      const audio = usePlayerStore.getState().audioElement
      if (!audio?.src) throw new Error('No music source is ready.')
      if (type === 'seek' || type === 'load' || type === 'start') await seek(audio, payload.position, signal, executionToken)
      check(signal, executionToken)
      if ((type === 'load' || type === 'start') && payload.desiredState !== 'playing') {
        audio.pause()
        stopped = payload.desiredState === 'stopped'
        usePlayerStore.getState().runtimeActions.setIsPlaying(false)
        return observe()
      }
      if (type === 'play' || type === 'start' || type === 'load') {
        const cancel = () => { audio.pause(); runtime.setExecutionOwned(false) }
        signal.addEventListener('abort', cancel, { once: true })
        try {
          await withAbort(runtime.connect(), signal)
          await withAbort(audioService.resumeContextIfNeeded(), signal)
          check(signal, executionToken)
          const started = audio.play()
          started.then(() => {
            if (signal.aborted || executionToken !== generation || !runtime.isAlive()) audio.pause()
          }, () => {})
          await withAbort(started, signal)
          try { check(signal, executionToken) } catch (error) { audio.pause(); throw error }
          usePlayerStore.getState().runtimeActions.setIsPlaying(true)
          usePlayerStore.getState().runtimeActions.observePlaybackState({ playbackRecovery: 'none' })
          audioService.setPlayingState(true)
          const track = usePlayerStore.getState().currentTrack
          if (track && (type === 'load' || type === 'start')) activityService.recordPlay({ songId: track.publicId, albumId: track.albumPublicId })
        } catch (error) {
          audio.pause()
          usePlayerStore.getState().runtimeActions.setIsPlaying(false)
          if (error instanceof DOMException && error.name === 'NotAllowedError') {
            usePlayerStore.getState().runtimeActions.observePlaybackState({ playbackRecovery: 'tap-to-resume' })
          }
          throw error
        } finally {
          signal.removeEventListener('abort', cancel)
        }
      }
      return observe()
    },
  }
}
