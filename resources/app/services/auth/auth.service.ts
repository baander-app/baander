import { authLogin, authLogout, authTokensRevokeAll } from '@/app/libs/api-client/gen/endpoints/auth/auth.ts';
import { Token } from '@/app/services/auth/token.ts';
import { tokenBindingService } from '@/app/services/auth/token-binding.service.ts';
import { eventBridge } from '../event-bridge/bridge';
import { LOCAL_STORAGE_KEY } from '@/app/common/constants.ts';

export async function login(credentials: { email: string; password: string }) {
  try {
    const response = await authLogin(credentials, {
      _skipAuth: true,
    });

    const { access_token, refresh_token, expires_in, session_id } = response;

    // Store session ID for request headers
    if (session_id) {
      tokenBindingService.setSessionId(session_id);
    }

    // Store tokens
    Token.set({
      access_token,
      refresh_token,
      session_id,
      expires_in,
    });

    // Notify other parts of the app
    eventBridge.emit('auth:login', {
      tokens: { accessToken: access_token, refreshToken: refresh_token },
      session_id,
      expires_in
    });

    return response;
  } catch (error) {
    // Clean up on login failure
    clearAuthData();
    throw error;
  }
}

export async function logout() {
  try {
    await authLogout({});
  } catch (error) {
    console.warn('Logout API call failed:', error);
  } finally {
    clearAuthData();
    eventBridge.emit('auth:logout', undefined);
  }
}

export function revokeAllTokensExceptCurrent() {
  return authTokensRevokeAll();
}

function clearAuthData() {
  window.BaanderElectron?.config.clearUser(LOCAL_STORAGE_KEY.USER_NAME);
  localStorage.clear();
  Token.clear();
  tokenBindingService.clear();
}
