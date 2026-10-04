import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { createSelectiveJSONStorage } from '@/shared/stores/persistence'
import { withStoreDebug } from '@/shared/stores/debug'
import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export type MediaType = 'music' | 'movies' | 'tv' | 'podcasts' | 'concerts' | 'ebooks'

export const MEDIA_TYPES: MediaType[] = ['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks']

export const MEDIA_TYPE_LABELS: Record<MediaType, string> = {
  music: 'Music',
  movies: 'Movies',
  tv: 'TV',
  podcasts: 'Podcasts',
  concerts: 'Concerts',
  ebooks: 'Ebooks',
}

interface MediaModeState {
  activeMedia: MediaType
  setActiveMedia: (media: MediaType) => void
}

export const useMediaModeStore = create<MediaModeState>()(
  withStoreDebug('layout.media-mode', persist(
    withNoopGuard((set) => ({
      activeMedia: 'music' as MediaType,
      setActiveMedia: (activeMedia: MediaType) => set({ activeMedia }),
    })),
    {
      name: 'baander-media-mode',
      storage: createSelectiveJSONStorage(),
      version: 1,
      partialize: (state) => ({ activeMedia: state.activeMedia }),
    },
  )),
)
