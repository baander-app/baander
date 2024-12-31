import { createSlice, PayloadAction } from "@reduxjs/toolkit";
import { RootState } from "@/store";

export interface MusicPlayerSlice {
  currentSongId: string | null;
  isPlaying: boolean;
}

const initialState: MusicPlayerSlice = {
  currentSongId: null,
  isPlaying: false,
}

export const musicPlayerSlice = createSlice({
  name: 'music-player',
  initialState,
  reducers: {
    setCurrentSongId(state, action: PayloadAction<string>) {
      state.currentSongId = action.payload;
    },
    setIsPlaying(state, action: PayloadAction<boolean>) {
      state.isPlaying = action.payload;
    },
  }
});

export const {
  setCurrentSongId,
  setIsPlaying,
} = musicPlayerSlice.actions;


export const selectCurrentSongId = (state: RootState) => state.musicPlayerSlice.currentSongId;
export const selectIsPlaying = (state: RootState) => state.musicPlayerSlice.isPlaying;

export default musicPlayerSlice.reducer;
