import { ThreeBandEq } from '@/modules/dsp/equalizer/models/three-band-eq.ts';
import { createSlice, PayloadAction } from '@reduxjs/toolkit';
import { BarsMode } from '@/modules/dsp/equalizer/models/bars-mode.ts';

export interface EqualizerState {
  isEnabled: boolean;
  isStereoEnabled: boolean;
  isMicrophoneEnabled: boolean;
  isKaraokeEnabled: boolean;
  balance: number;
  boostThreeBands: ThreeBandEq;
  microphoneBoost: number;
  barsMode: BarsMode;
}

const initialState: EqualizerState = {
  isEnabled: true,
  isStereoEnabled: true,
  isMicrophoneEnabled: false,
  isKaraokeEnabled: false,
  balance: 50,
  microphoneBoost: 0,
  barsMode: 'bars',
  boostThreeBands: {
    bass: 0,
    middle: 0,
    treble: 0,
  },
};

export const equalizerSlice = createSlice({
  name: 'equalizer',
  initialState,
  reducers: {
    setIsEnabled: (state, action: PayloadAction<boolean>) => {
      state.isEnabled = action.payload;
    },
    setIsStereoEnabled: (state, action: PayloadAction<boolean>) => {
      state.isStereoEnabled = action.payload;
    },
    setIsKaraokeEnabled: (state, action: PayloadAction<boolean>) => {
      state.isKaraokeEnabled = action.payload;
    },
    setBalance: (state, action: PayloadAction<number>) => {
      state.balance = action.payload;
    },
    setIsMicrophoneEnabled: (state, action: PayloadAction<boolean>) => {
      state.isMicrophoneEnabled = action.payload;
    },
    setBassBooster: (state, action: PayloadAction<number>) => {
      state.boostThreeBands.bass = action.payload;
    },
    setMiddleBooster: (state, action: PayloadAction<number>) => {
      state.boostThreeBands.middle = action.payload;
    },
    setTrebleBooster: (state, action: PayloadAction<number>) => {
      state.boostThreeBands.treble = action.payload;
    },
    setMicrophoneBooster: (state, action: PayloadAction<number>) => {
      state.microphoneBoost = action.payload;
    },
    setBarsMode: (state, action: PayloadAction<BarsMode>) => {
      state.barsMode = action.payload;
    },
  }
});

export const {
  setIsEnabled,
  setIsStereoEnabled,
  setIsKaraokeEnabled,
  setIsMicrophoneEnabled,
  setBassBooster,
  setMiddleBooster,
  setTrebleBooster,
  setBalance,
  setMicrophoneBooster,
  setBarsMode,
} = equalizerSlice.actions;
