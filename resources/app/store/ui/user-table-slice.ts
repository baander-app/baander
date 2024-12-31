import { createSlice, PayloadAction } from '@reduxjs/toolkit';
import { MRT_DensityState } from 'mantine-react-table';

export interface UserTableSlice {
  density: MRT_DensityState;
}

const initialState: UserTableSlice = {
  density: 'md',
};

export const userTableSlice = createSlice({
  name: 'ui-user-table',
  initialState,
  reducers: {
    setDensity: (state, action: PayloadAction<MRT_DensityState>) => {
      state.density = action.payload;
    },
  },
  selectors: {
    selectDensity: state => state.density,
  },
});

export const {
  setDensity,
} = userTableSlice.actions;

export const {
  selectDensity,
} = userTableSlice.selectors;