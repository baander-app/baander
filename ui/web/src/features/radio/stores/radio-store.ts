import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { RadioStation } from '@/features/radio/api/radio-api'
import { mediator } from '@/shared/lib/mediator/bus'
import { PLAYER_ACTIONS } from '@/features/player/player-actions'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('RadioStore')

export interface RadioState {
  // Current playback
  activeStation: RadioStation | null
  activeStreamUrl: string | null
  isPlaying: boolean
  streamFallbackIndex: number

  // Audio element reference (not persisted)
  audioElement: HTMLAudioElement | null

  // Failure state
  allStreamsFailed: boolean

  // Actions
  startStation: (station: RadioStation, streamUrl: string) => void
  stopRadio: () => void
  setIsPlaying: (playing: boolean) => void
  tryNextStream: () => string | null
  setAudioElement: (el: HTMLAudioElement | null) => void
  setAllStreamsFailed: (failed: boolean) => void
  reset: () => void
}

export const useRadioStore = create<RadioState>()(
  persist(
    (set, get) => ({
      activeStation: null,
      activeStreamUrl: null,
      isPlaying: false,
      streamFallbackIndex: 0,
      audioElement: null,
      allStreamsFailed: false,

      startStation: (station, streamUrl) => {
        // Pause music playback via mediator
        mediator.dispatch(PLAYER_ACTIONS.PAUSE, { reason: 'radio-started' }, 'radio')

        set({
          activeStation: station,
          activeStreamUrl: streamUrl,
          isPlaying: true,
          streamFallbackIndex: 0,
          allStreamsFailed: false,
        })

        // Set audio source
        const el = get().audioElement
        if (el) {
          el.src = streamUrl
          el.play().catch((err) => {
            logger.warn('Radio playback failed:', err)
            set({ isPlaying: false })
          })
        }
      },

      stopRadio: () => {
        const el = get().audioElement
        if (el) {
          el.pause()
          el.src = ''
        }

        set({
          activeStation: null,
          activeStreamUrl: null,
          isPlaying: false,
          streamFallbackIndex: 0,
          allStreamsFailed: false,
        })
      },

      setIsPlaying: (playing) => {
        set({ isPlaying: playing })
        const el = get().audioElement
        if (el) {
          if (playing) {
            el.play().catch((err) => { logger.warn('Radio playback failed:', err); set({ isPlaying: false }) })
          } else {
            el.pause()
          }
        }
      },

      tryNextStream: () => {
        const { activeStation, streamFallbackIndex } = get()
        if (!activeStation) return null

        const nextIndex = streamFallbackIndex + 1
        if (nextIndex >= activeStation.streams.length) {
          set({ allStreamsFailed: true, isPlaying: false })
          return null
        }

        const nextStream = activeStation.streams[nextIndex]
        set({
          streamFallbackIndex: nextIndex,
          activeStreamUrl: nextStream.url,
        })

        const el = get().audioElement
        if (el) {
          el.src = nextStream.url
          el.play().catch((err) => {
            logger.warn('Radio stream failed, will try next:', err)
          })
        }

        return nextStream.url
      },

      setAudioElement: (el) => {
        const { activeStreamUrl, isPlaying } = get()
        if (el && activeStreamUrl && isPlaying) {
          el.src = activeStreamUrl
          el.play().catch((err) => { logger.warn('Radio playback failed on element set:', err) })
        }
        set({ audioElement: el })
      },

      setAllStreamsFailed: (failed) => set({ allStreamsFailed: failed }),

      reset: () => {
        const el = get().audioElement
        if (el) {
          el.pause()
          el.src = ''
        }
        set({
          activeStation: null,
          activeStreamUrl: null,
          isPlaying: false,
          streamFallbackIndex: 0,
          allStreamsFailed: false,
        })
      },
    }),
    {
      name: 'baander-radio',
      version: 1,
      partialize: (state) => ({
        activeStation: state.activeStation,
      }),
    },
  ),
)

export function getRadioSnapshot() {
  return useRadioStore.getState()
}
