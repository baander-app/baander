import { create } from 'zustand'
import {
  initialVideoSettings,
  type VideoPresentationSettings,
  type VideoPresentationCommands
} from '../hooks/video-presentation'
import { withStoreDebug } from '@/shared/stores/debug'
import { withNoopGuard } from '@/shared/stores/with-noop-guard'

export interface VideoPresentation extends VideoPresentationSettings {
  commands: VideoPresentationCommands | null
  controlsAvailable: boolean
  videoId: string
  host: HTMLDivElement | null
  duration: number
  currentTime: number
  pendingSeekPosition: number | null
  state: string | null
  error: string | null
  select: (videoId: string) => void
  publish: (
    values: Partial<
      Omit<
        VideoPresentation,
        'select' | 'publish' | 'reportError' | 'dismissError'
      >
    >
  ) => void
  reportError: (error: unknown) => void
  /** Clears the shown error only while it is still this message; any other error stays. */
  dismissError: (message: string) => void
}

export const useVideoPresentation = create<VideoPresentation>()(
  withStoreDebug(
    'catalog.video-presentation',
    withNoopGuard((set) => ({
      ...initialVideoSettings(),
      commands: null,
      controlsAvailable: false,
      videoId: '',
      host: null,
      duration: 0,
      currentTime: 0,
      pendingSeekPosition: null,
      state: null,
      error: null,
      select: (videoId) =>
        set((state) => {
          if (state.videoId === videoId) return { error: null }
          return {
            ...initialVideoSettings(),
            videoId,
            error: null,
            commands: null,
            controlsAvailable: false
          }
        }),
      publish: (values) => set(values),
      reportError: (error) =>
        set({
          error:
            error instanceof Error ? error.message : 'Video playback failed.'
        }),
      dismissError: (message) =>
        set((state) => (state.error === message ? { error: null } : state))
    }))
  )
)
