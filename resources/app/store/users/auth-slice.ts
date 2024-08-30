import { createSlice, PayloadAction } from '@reduxjs/toolkit';
import { RootState } from '@/store';
import { Token } from '@/services/auth/token.ts';

export interface UserModel {
  name: string;
  email: string;
  isAdmin: boolean;
}

export interface UserSliceState {
  authenticated: boolean;
  user: UserModel | null;
}

const initialState: UserSliceState = {
  authenticated: false,
  user: null,
};

export const authSlice = createSlice({
  name: 'users-auth',
  initialState,
  reducers: {
    setIsAuthenticated(state, action: PayloadAction<boolean>) {
      state.authenticated = action.payload;
    },
    setUser(state, action: PayloadAction<UserModel>) {
      state.user = action.payload;
    },
    removeUser(state) {
      state.user = null;
    },
    logoutUser(state) {
      state.authenticated = false;
      state.user = null;
      Token.clear();
    }
  },
});

export const {
  setIsAuthenticated,
  setUser,
  removeUser,
  logoutUser
} = authSlice.actions;

export const selectIsAuthenticated = (state: RootState) => state.authSlice.authenticated;
export const selectUser = (state: RootState) => state.authSlice.user;

export default authSlice.reducer;
