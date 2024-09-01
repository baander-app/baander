import { createAppSlice } from '@/store/create-app-slice.ts';
import { PayloadAction } from '@reduxjs/toolkit';
import { Token } from '@/services/auth/token.ts';
import { NewAccessTokenResource } from '@/api-client/requests';

export interface UserModel {
  name: string;
  email: string;
  isAdmin: boolean;
}

export interface UserSliceState {
  authenticated: boolean;
  accessToken: NewAccessTokenResource | null;
  refreshToken: NewAccessTokenResource | null;
  streamToken: NewAccessTokenResource | null;
  user: UserModel | null;
}

const initialState: UserSliceState = {
  authenticated: false,
  accessToken: null,
  refreshToken: null,
  streamToken: null,
  user: null,
};

export const authSlice = createAppSlice({
  name: 'users-auth',
  initialState,
  reducers: {
    setIsAuthenticated(state, action: PayloadAction<boolean>) {
      state.authenticated = action.payload;
    },
    setAccessToken(state, action: PayloadAction<NewAccessTokenResource>) {
      state.accessToken = action.payload;
    },
    setRefreshToken(state, action: PayloadAction<NewAccessTokenResource>) {
      state.refreshToken = action.payload;
    },
    setStreamToken(state, action: PayloadAction<NewAccessTokenResource>) {
      state.streamToken = action.payload;
    },
    setUser(state, action: PayloadAction<UserModel>) {
      state.user = action.payload;
    },
    removeUser(state) {
      state.user = null;
    },
    logoutUser(state) {
      state.authenticated = false;
      state.accessToken = null;
      state.refreshToken = null;
      state.streamToken = null;
      state.user = null;
      Token.clear();
    },
  },
  selectors: {
    selectIsAuthenticated: state => state.authenticated,
    selectRefreshToken: state => state.refreshToken,
    selectStreamToken: state => state.streamToken,
    selectUser: state => state.user,
  },
});

export const {
  setIsAuthenticated,
  setAccessToken,
  setRefreshToken,
  setStreamToken,
  setUser,
  removeUser,
  logoutUser,
} = authSlice.actions;

export const {
  selectIsAuthenticated,
  selectRefreshToken,
  selectStreamToken,
  selectUser,
} = authSlice.selectors;