import { createAppSlice } from '@/store/create-app-slice.ts';
import { createAsyncThunk, PayloadAction } from '@reduxjs/toolkit';
import { Token } from '@/services/auth/token.ts';
import { AuthService, NewAccessTokenResource, RegisterRequest } from '@/api-client/requests';
import { clearAuthState, setAuthState } from '@/store/users/auth-slice.utils.ts';

export const logoutUser = createAsyncThunk('auth/logout', async () => {
  const token = Token.get();

  if (!token) {
    return;
  }

  return AuthService.postApiAuthLogout({ requestBody: { refreshToken: token.refreshToken?.token } });
});

export const createUser = createAsyncThunk('auth/register', async (options: RegisterRequest) => {
  return AuthService.postApiAuthRegister({
    requestBody: options,
  });
});

export const loginUser = createAsyncThunk('auth/login', (options: { email: string, password: string }) => {
  return AuthService.postApiAuthLogin({
    requestBody: options,
  });
});

export interface UserModel {
  name: string;
  email: string;
  isAdmin: boolean;
}

export interface AuthSliceState {
  authenticated: boolean;
  accessToken: NewAccessTokenResource | null;
  refreshToken: NewAccessTokenResource | null;
  streamToken: NewAccessTokenResource | null;
  user: UserModel | null;
  loading: boolean;
}

const initialState: AuthSliceState = {
  authenticated: false,
  accessToken: null,
  refreshToken: null,
  streamToken: null,
  user: null,
  loading: false,
};

export const authSlice = createAppSlice({
  name: 'auth',
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
  },
  extraReducers: builder => {
    builder.addCase(logoutUser.pending, (state) => {
      state.loading = true;
    });
    builder.addCase(logoutUser.fulfilled, (state) => {
      clearAuthState(state);
    });
    builder.addCase(logoutUser.rejected, (state) => {
      clearAuthState(state);
    });
    builder.addCase(createUser.pending, (state) => {
      state.loading = true;
    });
    builder.addCase(createUser.fulfilled, (state, action) => {
      setAuthState(state, action.payload);
    });
    builder.addCase(loginUser.pending, (state) => {
      state.loading = true;
    });
    builder.addCase(loginUser.fulfilled, (state, action) => {
      setAuthState(state, action.payload);
    });
  },
  selectors: {
    selectIsAuthenticated: auth => auth.authenticated,
    selectAccessToken: auth => auth.accessToken,
    selectRefreshToken: auth => auth.refreshToken,
    selectStreamToken: auth => auth.streamToken,
    selectUser: auth => auth.user,
  },
});

export const {
  setIsAuthenticated,
  setAccessToken,
  setRefreshToken,
  setStreamToken,
  setUser,
  removeUser,
} = authSlice.actions;

export const {
  selectIsAuthenticated,
  selectAccessToken,
  selectRefreshToken,
  selectStreamToken,
  selectUser,
} = authSlice.selectors;
