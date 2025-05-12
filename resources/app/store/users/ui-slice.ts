import { createAppSlice } from '@/store/create-app-slice.ts';
import { PayloadAction } from '@reduxjs/toolkit';

export type Theme = 'light' | 'dark';

export interface UiSliceState {
  theme: Theme;
}

const initialState: UiSliceState = {
  theme: 'light',
};

export const uiSlice = createAppSlice({
  name: 'ui',
  initialState,
  reducers: {
    setTheme: (state, action: PayloadAction<Theme>) => {
      state.theme = action.payload;
    },
  },
});

export const {
  setTheme,
} = uiSlice.actions;