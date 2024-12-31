import { createSlice, PayloadAction } from '@reduxjs/toolkit';
import { SongResource } from '@/api-client/requests';
import { PlaybackSource } from '@/lib/models/playback-source.ts';

interface MusicPlayerSlice {
  queue: SongResource[];
  currentSongIndex: number;
  progress: number;
  isPlaying: boolean;
  isMuted: boolean;
  source: PlaybackSource;
  mode: {
    isShuffleEnabled: boolean;
    isRepeatEnabled: boolean;
  };
  volume: {
    level: number;
    isMuted: boolean;
  };
  analysis: {
    leftChannel: number;
    rightChannel: number;
    frequencies: number[];
    bufferSize: number;
  };
}

const initialState: MusicPlayerSlice = {
  queue: [],
  currentSongIndex: -1,
  progress: 0,
  isPlaying: false,
  isMuted: false,
  source: PlaybackSource.NONE,
  mode: {
    isRepeatEnabled: false,
    isShuffleEnabled: false,
  },
  volume: {
    level: 100,
    isMuted: false,
  },
  analysis: {
    leftChannel: 0,
    rightChannel: 0,
    frequencies: [],
    bufferSize: 0,
  },
};

export const musicPlayerSlice = createSlice({
  name: 'musicPlayer',
  initialState,
  reducers: {
    addSongToQueue: (state, action: PayloadAction<SongResource>) => {
      state.source = PlaybackSource.LIBRARY;
      state.queue.push(action.payload);
    },
    addSongsToQueue: (state, action: PayloadAction<SongResource[]>) => {
      state.source = PlaybackSource.LIBRARY;
      state.queue.push(...action.payload);
    },
    setQueue(state, action: PayloadAction<SongResource[]>) {
      state.source = PlaybackSource.LIBRARY;
      state.queue = action.payload;
    },
    setQueueAndSong(state, action: PayloadAction<{ queue: SongResource[], playPublicId: string }>) {
      state.source = PlaybackSource.LIBRARY;
      state.queue = action.payload.queue;
      state.currentSongIndex = state.queue.findIndex(song => song.public_id === action.payload.playPublicId);
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
    setIsShuffleEnabled: (state, action: PayloadAction<boolean>) => {
      state.mode.isShuffleEnabled = action.payload;
      state.mode.isRepeatEnabled = false;
    },
    setIsRepeatEnabled: (state, action: PayloadAction<boolean>) => {
      state.mode.isRepeatEnabled = action.payload;
      state.mode.isShuffleEnabled = false;
    },
    setIsMuted: (state, action: PayloadAction<boolean>) => {
      state.volume.isMuted = action.payload;
    },
    setVolume: (state, action: PayloadAction<number>) => {
      state.volume.level = action.payload;
    },
    setProgress: (state, action: PayloadAction<number>) => {
      state.progress = action.payload;
    },
    setIsPlaying: (state, action: PayloadAction<boolean>) => {
      state.isPlaying = action.payload;
    },
    setLeftChannel: (state, action: PayloadAction<number>) => {
      state.analysis.leftChannel = action.payload;
    },
    setRightChannel: (state, action: PayloadAction<number>) => {
      state.analysis.rightChannel = action.payload;
    },
    setFrequencies: (state, action: PayloadAction<number[]>) => {
      state.analysis.frequencies = action.payload;
    },
    setBufferSize: (state, action: PayloadAction<number>) => {
      state.analysis.bufferSize = action.payload;
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
  setIsShuffleEnabled,
  setIsRepeatEnabled,
  setIsMuted,
  setVolume,
  setProgress,
  setIsPlaying,
  setQueue,
  setQueueAndSong,
  setLeftChannel,
  setRightChannel,
  setFrequencies,
  setBufferSize,
} = musicPlayerSlice.actions;

export const {
  selectSong,
} = musicPlayerSlice.selectors;