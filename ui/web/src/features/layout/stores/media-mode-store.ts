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
  persist(
    (set) => ({
      activeMedia: 'music' as MediaType,
      setActiveMedia: (activeMedia: MediaType) => set({ activeMedia }),
    }),
    {
      name: 'baander-media-mode',
      version: 1,
    },
  ),
)
