import { StateCreator } from 'zustand';
import { SongResource } from '@/app/libs/api-client/gen/models';
import { PlaybackSource } from '@/app/models/playback-source';

export interface QueueSlice {
  // State
  queue: SongResource[];
  currentSongIndex: number;
  currentSongPublicId: string | null;
  source: PlaybackSource;

  // Actions
  setQueue: (queue: SongResource[]) => void;
  addToQueue: (song: SongResource) => void;
  addManyToQueue: (songs: SongResource[]) => void;
  removeFromQueue: (index: number) => void;
  playSongAtIndex: (index: number) => void;
  playNext: () => void;
  playPrevious: () => void;
  setQueueAndPlay: (queue: SongResource[], publicId: string) => void;
  setPlaybackSource: (source: PlaybackSource) => void;
}

export const createQueueSlice: StateCreator<QueueSlice> = (set) => ({
  // Initial state
  queue: [],
  currentSongIndex: -1,
  currentSongPublicId: null,
  source: PlaybackSource.NONE,

  // Actions
  setQueue: (queue) => set({
    queue,
    source: PlaybackSource.LIBRARY,
  }),

  addToQueue: (song) => set((state) => ({
    queue: [...state.queue, song],
    source: PlaybackSource.LIBRARY,
  })),

  addManyToQueue: (songs) => set((state) => ({
    queue: [...state.queue, ...songs],
    source: PlaybackSource.LIBRARY,
  })),

  removeFromQueue: (index) => set((state) => {
    const newQueue = [...state.queue];
    newQueue.splice(index, 1);
    return { queue: newQueue };
  }),

  playSongAtIndex: (index) => set((state) => {
    if (index >= 0 && index < state.queue.length) {
      const song = state.queue[index];
      return {
        currentSongIndex: index,
        currentSongPublicId: song.publicId,
        song: { publicId: song.publicId, title: song.title },
      };
    }
    return {};
  }),

  playNext: () => set((state) => {
    if (state.queue.length === 0) return {};
    let nextIndex = state.currentSongIndex + 1;
    if (nextIndex >= state.queue.length) {
      nextIndex = 0; // Loop back to start
    }
    const song = state.queue[nextIndex];
    return {
      currentSongIndex: nextIndex,
      currentSongPublicId: song.publicId,
      song: { publicId: song.publicId, title: song.title },
    };
  }),

  playPrevious: () => set((state) => {
    if (state.queue.length === 0) return {};
    let prevIndex = state.currentSongIndex - 1;
    if (prevIndex < 0) {
      prevIndex = state.queue.length - 1; // Loop back to end
    }
    const song = state.queue[prevIndex];
    return {
      currentSongIndex: prevIndex,
      currentSongPublicId: song.publicId,
      song: { publicId: song.publicId, title: song.title },
    };
  }),

  setQueueAndPlay: (queue, publicId) => set({
    queue,
    currentSongIndex: queue.findIndex(song => song.publicId === publicId),
    currentSongPublicId: publicId,
    source: PlaybackSource.LIBRARY,
  }),

  setPlaybackSource: (source) => set({ source }),
});
