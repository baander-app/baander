import type { PlaybackViewingContextResource } from '@/shared/api-client/gen/endpoints'
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
  viewing: PlaybackViewingContextResource | null
  host: HTMLDivElement | null
  duration: number
  currentTime: number
  pendingSeekPosition: number | null
  state: string | null
  error: string | null
  select: (videoId: string, viewing?: PlaybackViewingContextResource) => void
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
      viewing: null,
      host: null,
      duration: 0,
      currentTime: 0,
      pendingSeekPosition: null,
      state: null,
      error: null,
      select: (videoId, viewing) =>
        set((state) => {
          if (state.videoId === videoId) return { error: null, viewing: viewing ?? null }
          return {
            ...initialVideoSettings(),
            videoId,
            viewing: viewing ?? null,
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
