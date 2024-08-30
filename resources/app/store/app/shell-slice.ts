import { createSlice, PayloadAction } from '@reduxjs/toolkit';
import { RootState } from '@/store';

export interface ShellSliceState {
  appName: string;
}

const initialState: ShellSliceState = {
  appName: import.meta.env.VITE_APP_NAME,
};

export const shellSlice = createSlice({
  name: 'shell-slice',
  initialState,
  reducers: {
    setAppName: (state, action: PayloadAction<string>) => {
      state.appName = action.payload;
    },
  },
});

export const { setAppName } = shellSlice.actions;

export const selectAppName = (state: RootState) => state.shellReducer.appName;

export default shellSlice.reducer;
