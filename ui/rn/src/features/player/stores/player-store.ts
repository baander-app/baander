/**
 * Player store -- minimal Zustand store for playback state.
 *
 * Created in Unit 4b (mobile shell needs it for MiniPlayer).
 * Unit 5b wires this to the native AudioModule.
 *
 * Shape matches the web player-store interface.
 */

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import AsyncStorage from '@react-native-async-storage/async-storage';

export interface Track {
  id: string;
  publicId: string;
  title: string;
  artistName: string | null;
  albumName: string | null;
  albumPublicId: string | null;
  duration: number | null;
  trackNumber: number | null;
  discNumber: number | null;
  coverImageBlurhash: string | null;
}

export enum RepeatMode {
  Off = 'off',
  All = 'all',
  One = 'one',
}

interface PlayerState {
  // Queue
  queue: Track[];
  queueIndex: number;
  currentTrack: Track | null;

  // Playback
  isPlaying: boolean;
  currentTime: number;
  duration: number;
  volume: number;
  isMuted: boolean;

  // Modes
  shuffle: boolean;
  repeat: RepeatMode;

  // Actions -- minimal shape, AudioModule wiring added in Unit 5b
  playTrack: (track: Track, queue: Track[], index: number) => void;
  setIsPlaying: (playing: boolean) => void;
  playNext: () => void;
  playPrevious: () => void;
  setCurrentTime: (time: number) => void;
  setVolume: (volume: number) => void;
  toggleMute: () => void;
  toggleShuffle: () => void;
  cycleRepeat: () => void;
  addToQueue: (tracks: Track[]) => void;
  clearQueue: () => void;
}

export const usePlayerStore = create<PlayerState>()(
  persist(
    (set, get) => ({
      queue: [],
      queueIndex: -1,
      currentTrack: null,
      isPlaying: false,
      currentTime: 0,
      duration: 0,
      volume: 80,
      isMuted: false,
      shuffle: false,
      repeat: RepeatMode.Off,

      playTrack: (track, queue, index) => {
        set({
          currentTrack: track,
          queue,
          queueIndex: index,
          isPlaying: true,
          currentTime: 0,
          duration: track.duration ?? 0,
        });
        // AudioModule.play() wired in Unit 5b
      },

      setIsPlaying: (playing) => {
        set({ isPlaying: playing });
      },

      playNext: () => {
        const { queue, queueIndex, shuffle, repeat } = get();
        if (queue.length === 0) return;

        let nextIndex: number;
        if (shuffle) {
          nextIndex = Math.floor(Math.random() * queue.length);
        } else if (repeat === RepeatMode.One) {
          nextIndex = queueIndex;
        } else {
          nextIndex = queueIndex + 1;
          if (nextIndex >= queue.length) {
            if (repeat === RepeatMode.All) {
              nextIndex = 0;
            } else {
              set({ isPlaying: false });
              return;
            }
          }
        }

        const nextTrack = queue[nextIndex];
        if (nextTrack) {
          set({
            currentTrack: nextTrack,
            queueIndex: nextIndex,
            currentTime: 0,
            duration: nextTrack.duration ?? 0,
          });
        }
      },

      playPrevious: () => {
        const { queue, queueIndex, currentTime } = get();
        if (queue.length === 0) return;

        // If more than 3 seconds in, restart current track
        if (currentTime > 3) {
          set({ currentTime: 0 });
          return;
        }

        const prevIndex = Math.max(0, queueIndex - 1);
        const prevTrack = queue[prevIndex];
        if (prevTrack) {
          set({
            currentTrack: prevTrack,
            queueIndex: prevIndex,
            currentTime: 0,
            duration: prevTrack.duration ?? 0,
          });
        }
      },

      setCurrentTime: (time) => set({ currentTime: time }),
      setVolume: (volume) => set({ volume }),
      toggleMute: () => set((s) => ({ isMuted: !s.isMuted })),
      toggleShuffle: () => set((s) => ({ shuffle: !s.shuffle })),

      cycleRepeat: () => {
        set((s) => ({
          repeat: s.repeat === RepeatMode.Off
            ? RepeatMode.All
            : s.repeat === RepeatMode.All
              ? RepeatMode.One
              : RepeatMode.Off,
        }));
      },

      addToQueue: (tracks) => {
        set((s) => ({ queue: [...s.queue, ...tracks] }));
      },

      clearQueue: () => {
        set({
          queue: [],
          queueIndex: -1,
          currentTrack: null,
          isPlaying: false,
          currentTime: 0,
          duration: 0,
        });
      },
    }),
    {
      name: 'baander-player',
      storage: createJSONStorage(() => AsyncStorage),
      partialize: (state) => ({
        volume: state.volume,
        isMuted: state.isMuted,
        shuffle: state.shuffle,
        repeat: state.repeat,
      }),
    },
  ),
);
