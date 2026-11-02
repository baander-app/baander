import { toast } from 'sonner'
import { getPlaybackAdapter } from '@/features/session/services/playback-execution'
import { pausePlaybackForRadio } from '@/features/session/services/playback-session-coordinator'
import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { withStoreDebug } from '@/shared/stores/debug'
import { createSelectiveJSONStorage } from '@/shared/stores/persistence'
import type { RadioStation } from '@/features/radio/api/radio-api'
import { mediator } from '@/shared/lib/mediator/bus'
import { PLAYER_ACTIONS } from '@/features/player/player-actions'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('RadioStore')
export interface RadioState {
  activeStation: RadioStation | null
  activeStreamUrl: string | null
  isPlaying: boolean
  streamFallbackIndex: number
  audioElement: HTMLAudioElement | null
  allStreamsFailed: boolean
  startStation: (station: RadioStation, streamUrl: string) => Promise<boolean>
  stopRadio: () => void
  setIsPlaying: (playing: boolean) => void
  observePlaying: (playing: boolean) => void
  tryNextStream: () => string | null
  setAudioElement: (el: HTMLAudioElement | null) => void
  setAllStreamsFailed: (failed: boolean) => void
  reset: () => void
}

export const useRadioStore = create<RadioState>()(withStoreDebug('radio.radio', persist(withNoopGuard((set, get) => {
  let attempt = 0
  let selection = 0
  let wantsPlayback = false
  let pendingStart = false
  const play = () => {
    if (!wantsPlayback || pendingStart) return false
    const owner = ++attempt
    const state = get(), element = state.audioElement
    if (!element) return true
    if (getPlaybackAdapter('song')?.localRecovery?.eligible) {
      if ('disableRemotePlayback' in element) element.disableRemotePlayback = true
      if (!element.disableRemotePlayback) {
        wantsPlayback = false
        set({ isPlaying: false })
        toast.error('Radio could not start', { description: 'Remote playback must be disabled for this local player.' })
        return false
      }
    }
    if (state.activeStreamUrl && element.src !== state.activeStreamUrl) element.src = state.activeStreamUrl
    const src = element.src, station = state.activeStation
    element.play().catch((error) => {
      if (owner !== attempt || get().audioElement !== element || element.src !== src || get().activeStation !== station) return
      logger.warn('Radio playback failed:', error)
      if (get().isPlaying) set({ isPlaying: false })
    })
    return true
  }
  const stop = () => {
    wantsPlayback = false
    pendingStart = false
    selection++
    attempt++
    const state = get(), element = state.audioElement
    if (element) { element.pause(); if (element.src) element.src = '' }
    if (state.activeStation === null && state.activeStreamUrl === null && !state.isPlaying
      && state.streamFallbackIndex === 0 && !state.allStreamsFailed) return
    set({ activeStation: null, activeStreamUrl: null, isPlaying: false, streamFallbackIndex: 0, allStreamsFailed: false })
  }
  return {
    activeStation: null, activeStreamUrl: null, isPlaying: false, streamFallbackIndex: 0, audioElement: null, allStreamsFailed: false,
    startStation: async (station, streamUrl) => {
      const state = get()
      if (state.activeStation === station && state.activeStreamUrl === streamUrl && state.isPlaying && !state.allStreamsFailed) return true
      const owner = ++selection
      attempt++
      pendingStart = true
      wantsPlayback = false
      mediator.dispatch(PLAYER_ACTIONS.PAUSE, { reason: 'radio-started' }, 'radio')
      if (state.audioElement?.src) { state.audioElement.pause(); state.audioElement.src = '' }
      const sorted = [...station.streams].sort((a, b) => b.reliability - a.reliability)
      set({ activeStation: station, activeStreamUrl: streamUrl, isPlaying: false,
        streamFallbackIndex: Math.max(0, sorted.findIndex((stream) => stream.url === streamUrl)), allStreamsFailed: false })
      try {
        await pausePlaybackForRadio()
      } catch (error) {
        if (owner !== selection) return false
        pendingStart = false
        toast.error('Radio could not start', { description: error instanceof Error ? error.message : 'The previous playback could not be paused.' })
        return false
      }
      if (owner !== selection) return false
      pendingStart = false
      wantsPlayback = true
      set({ isPlaying: true })
      return play()
    },
    stopRadio: stop,
    reset: stop,
    setIsPlaying: (playing) => {
      if (pendingStart && playing) return
      if (!pendingStart && get().isPlaying === playing && wantsPlayback === playing) return
      if (playing) {
        const { activeStation, activeStreamUrl } = get()
        if (activeStation && activeStreamUrl) get().startStation(activeStation, activeStreamUrl)
        return
      }
      pendingStart = false
      wantsPlayback = false
      selection++
      attempt++
      set({ isPlaying: false })
      get().audioElement?.pause()
    },
    // Native notifications update state without calling play()/pause() again.
    observePlaying: (playing) => {
      if (playing && !wantsPlayback) return
      if (get().isPlaying === playing) return
      if (!playing) attempt++
      set({ isPlaying: playing })
    },
    tryNextStream: () => {
      const state = get()
      if (!wantsPlayback || !state.activeStation || state.allStreamsFailed) return null
      const sorted = [...state.activeStation.streams].sort((a, b) => b.reliability - a.reliability)
      const index = state.streamFallbackIndex + 1
      const next = sorted[index]
      if (!next) {
        wantsPlayback = false
        attempt++
        state.audioElement?.pause()
        set({ allStreamsFailed: true, isPlaying: false })
        return null
      }
      set({ streamFallbackIndex: index, activeStreamUrl: next.url, isPlaying: true })
      play()
      return next.url
    },
    setAudioElement: (element) => {
      const state = get()
      if (state.audioElement === element) return
      attempt++
      set({ audioElement: element })
      if (element && state.activeStreamUrl && state.isPlaying) play()
    },
    setAllStreamsFailed: (failed) => {
      if (get().allStreamsFailed !== failed) set({ allStreamsFailed: failed })
    },
  }
}), {
  name: 'baander-radio', version: 1, storage: createSelectiveJSONStorage(),
  partialize: (state) => ({ activeStation: state.activeStation }),
})))

export function getRadioSnapshot() { return useRadioStore.getState() }
