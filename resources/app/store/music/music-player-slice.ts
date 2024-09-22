import { createSlice, PayloadAction } from '@reduxjs/toolkit';
import { SongResource } from '@/api-client/requests';

interface MusicPlayerSlice {
  queue: SongResource[];
  currentSongIndex: number;
  progress: number;
  isPlaying: boolean;
}

const initialState: MusicPlayerSlice = {
  queue: [],
  currentSongIndex: -1,
  progress: 0,
  isPlaying: false,
};

export const musicPlayerSlice = createSlice({
  name: 'musicPlayer',
  initialState,
  reducers: {
    addSongToQueue: (state, action: PayloadAction<SongResource>) => {
      state.queue.push(action.payload);
    },
    addSongsToQueue: (state, action: PayloadAction<SongResource[]>) => {
      state.queue.push(...action.payload);
    },
    setQueue(state, action: PayloadAction<SongResource[]>) {
      state.queue = action.payload;
    },
    removeSongFromQueue: (state, action: PayloadAction<number>) => {
      state.queue.splice(action.payload, 1);
    },
    playNextSong: (state) => {
      if (state.currentSongIndex < state.queue.length - 1) {
        state.currentSongIndex += 1;
      } else {
        state.currentSongIndex = 0; // Loop back to the start if at the end
      }
    },
    playPreviousSong: (state) => {
      if (state.currentSongIndex > 0) {
        state.currentSongIndex -= 1;
      } else {
        state.currentSongIndex = state.queue.length - 1; // Loop back to the end if at the start
      }
    },
    setCurrentSongIndex: (state, action: PayloadAction<number>) => {
      if (action.payload >= 0 && action.payload < state.queue.length) {
        state.currentSongIndex = action.payload;
      }
    },
    setProgress: (state, action: PayloadAction<number>) => {
      state.progress = action.payload;
    },
    setIsPlaying: (state, action: PayloadAction<boolean>) => {
      state.isPlaying = action.payload;
    },
  },
  selectors: {
    selectSong: (sliceState) =>
      sliceState.queue.length > 0 && sliceState.currentSongIndex >= 0
      ? sliceState.queue[sliceState.currentSongIndex]
      : null,
  },
});

export const {
  addSongToQueue,
  addSongsToQueue,
  removeSongFromQueue,
  playNextSong,
  playPreviousSong,
  setCurrentSongIndex,
  setProgress,
  setIsPlaying,
  setQueue,
} = musicPlayerSlice.actions;

export const {
  selectSong,
} = musicPlayerSlice.selectors;