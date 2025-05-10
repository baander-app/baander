import { AuthSliceState } from '@/store/users/auth-slice.ts';
import { OpenAPI, PostApiAuthLoginResponse } from '@/api-client/requests';
import { Token } from '@/services/auth/token.ts';

export const clearAuthState = (state: AuthSliceState) => {
  state.loading = false;
  state.authenticated = false;
  state.accessToken = null;
  state.refreshToken = null;
  Token.clear();
  OpenAPI.TOKEN = undefined;
}

export const setAuthState = (state: AuthSliceState, payload: PostApiAuthLoginResponse) => {
  state.loading = false;
  state.authenticated = true;
  state.accessToken = payload.accessToken;
  state.refreshToken = payload.refreshToken;
  Token.set({
    accessToken: payload.accessToken,
    refreshToken: payload.refreshToken,
  });
  OpenAPI.TOKEN = payload.accessToken.token;

  return state;
}