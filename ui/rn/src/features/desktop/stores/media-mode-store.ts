import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import AsyncStorage from '@react-native-async-storage/async-storage';

export type MediaType = 'music' | 'movies' | 'tv' | 'podcasts' | 'concerts' | 'ebooks';

interface MediaModeState {
  activeMedia: MediaType;
  setActiveMedia: (media: MediaType) => void;
}

export const useMediaModeStore = create<MediaModeState>()(
  persist(
    (set) => ({
      activeMedia: 'music',
      setActiveMedia: (media) => set({ activeMedia: media }),
    }),
    {
      name: 'baander-media-mode',
      storage: createJSONStorage(() => AsyncStorage),
    },
  ),
);
