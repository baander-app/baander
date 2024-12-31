import { createAppSlice } from '@/store/create-app-slice.ts';
import { PayloadAction } from '@reduxjs/toolkit';

export interface MusicPlayerSlice {
  currentSongId: string | null;
  isPlaying: boolean;
}

const initialState: MusicPlayerSlice = {
  currentSongId: null,
  isPlaying: false,
};

export const musicPlayerSlice = createAppSlice({
  name: 'music-player',
  initialState,
  reducers: {
    setCurrentSongId(state, action: PayloadAction<string>) {
      state.currentSongId = action.payload;
    },
    setIsPlaying(state, action: PayloadAction<boolean>) {
      state.isPlaying = action.payload;
    },
  },
  selectors: {
    selectCurrentSongId: state => state.currentSongId,
    selectIsPlaying: state => state.isPlaying,
  },
});

export const {
  setCurrentSongId,
  setIsPlaying,
} = musicPlayerSlice.actions;

export const {
  selectCurrentSongId,
  selectIsPlaying,
} = musicPlayerSlice.selectors;