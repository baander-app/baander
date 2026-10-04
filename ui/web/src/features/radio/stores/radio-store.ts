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
  startStation: (station: RadioStation, streamUrl: string) => void
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
  let wantsPlayback = false
  const play = () => {
    const owner = ++attempt
    const state = get(), element = state.audioElement
    if (!element) return
    const src = element.src, station = state.activeStation
    element.play().catch((error) => {
      if (owner !== attempt || get().audioElement !== element || element.src !== src || get().activeStation !== station) return
      logger.warn('Radio playback failed:', error)
      if (get().isPlaying) set({ isPlaying: false })
    })
  }
  const stop = () => {
    wantsPlayback = false
    attempt++
    const state = get(), element = state.audioElement
    if (element) { element.pause(); if (element.src) element.src = '' }
    if (state.activeStation === null && state.activeStreamUrl === null && !state.isPlaying
      && state.streamFallbackIndex === 0 && !state.allStreamsFailed) return
    set({ activeStation: null, activeStreamUrl: null, isPlaying: false, streamFallbackIndex: 0, allStreamsFailed: false })
  }
  return {
    activeStation: null, activeStreamUrl: null, isPlaying: false, streamFallbackIndex: 0, audioElement: null, allStreamsFailed: false,
    startStation: (station, streamUrl) => {
      const state = get()
      if (state.activeStation === station && state.activeStreamUrl === streamUrl && state.isPlaying && !state.allStreamsFailed) return
      wantsPlayback = true
      mediator.dispatch(PLAYER_ACTIONS.PAUSE, { reason: 'radio-started' }, 'radio')
      const sorted = [...station.streams].sort((a, b) => b.reliability - a.reliability)
      set({ activeStation: station, activeStreamUrl: streamUrl, isPlaying: true,
        streamFallbackIndex: Math.max(0, sorted.findIndex((stream) => stream.url === streamUrl)), allStreamsFailed: false })
      if (get().audioElement) get().audioElement!.src = streamUrl
      play()
    },
    stopRadio: stop,
    reset: stop,
    setIsPlaying: (playing) => {
      // Manual intent differs from a native pause/error notification.
      if (get().isPlaying === playing && wantsPlayback === playing) return
      wantsPlayback = playing
      set({ isPlaying: playing })
      if (playing) play()
      else { attempt++; get().audioElement?.pause() }
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
      if (state.audioElement) state.audioElement.src = next.url
      play()
      return next.url
    },
    setAudioElement: (element) => {
      const state = get()
      if (state.audioElement === element) return
      attempt++
      set({ audioElement: element })
      if (element && state.activeStreamUrl && state.isPlaying) { element.src = state.activeStreamUrl; play() }
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
