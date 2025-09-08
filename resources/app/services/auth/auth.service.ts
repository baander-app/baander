import { authLogin, authLogout, authTokensRevokeAll } from '@/libs/api-client/gen/endpoints/auth/auth.ts';
import { Token } from '@/services/auth/token.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service.ts';
import { eventBridge } from '../event-bridge/bridge';
import { LOCAL_STORAGE_KEY } from '@/common/constants.ts';

export async function login(credentials: { email: string; password: string }) {
  try {
    const response = await authLogin(credentials, {
      _skipAuth: true,
    });

    const { accessToken, refreshToken, sessionId } = response;

    // Store session ID for request headers
    if (sessionId) {
      tokenBindingService.setSessionId(sessionId);
    }

    // Store tokens
    Token.set({
      accessToken,
      refreshToken,
      sessionId,
    });

    // Notify other parts of the app
    eventBridge.emit('auth:login', {
      tokens: { accessToken, refreshToken },
      sessionId,
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
  window.BaanderElectron.config.clearUser(LOCAL_STORAGE_KEY.USER_NAME);
  localStorage.clear();
  Token.clear();
  tokenBindingService.clear();
}
