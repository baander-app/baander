import { create } from 'zustand'
import { withStoreDebug } from '@/shared/stores/debug'
import { withNoopGuard } from '@/shared/stores/with-noop-guard'

interface VideoPresentation {
  videoId: string
  host: HTMLDivElement | null
  duration: number
  currentTime: number
  pendingSeekPosition: number | null
  state: string | null
  error: string | null
  select: (videoId: string) => void
  publish: (values: Partial<Omit<VideoPresentation, 'select' | 'publish' | 'reportError'>>) => void
  reportError: (error: unknown) => void
}

export const useVideoPresentation = create<VideoPresentation>()(withStoreDebug('catalog.video-presentation', withNoopGuard(set => ({
  videoId: '', host: null, duration: 0, currentTime: 0, pendingSeekPosition: null, state: null, error: null,
  select: videoId => set({ videoId, error: null }),
  publish: values => set(values),
  reportError: error => set({ error: error instanceof Error ? error.message : 'Video playback failed.' }),
}))))
