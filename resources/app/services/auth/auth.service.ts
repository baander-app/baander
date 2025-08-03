// services/auth/auth.service.ts
import { authLogin, authLogout, authTokensRevokeAll } from '@/libs/api-client/gen/endpoints/auth/auth.ts';
import { Token } from '@/services/auth/token.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service.ts';
import { eventBridge } from '../event-bridge/bridge';

export async function login(credentials: { email: string; password: string }) {
  try {
    const response = await authLogin(credentials, {
      _skipAuth: true,
    });

    const { accessToken, refreshToken, sessionId } = response;

    if (sessionId) {
      tokenBindingService.setSessionId(sessionId);
    }

    Token.set({
      accessToken: accessToken,
      refreshToken: refreshToken,
      sessionId,
    });

    eventBridge.emit('auth:login', {
      tokens: { accessToken, refreshToken },
      sessionId,
    });

    return response;
  } catch (error) {
    tokenBindingService.clear();
    Token.clear();
    throw error;
  }
}

export async function logout() {
  try {
    await authLogout({});
  } catch (error) {
    console.warn('Logout API call failed:', error);
  } finally {
    Token.clear();
    tokenBindingService.clear();

    // Emit logout event for external systems
    eventBridge.emit('auth:logout', undefined);
  }
}

export function revokeAllTokensExceptCurrent() {
  return authTokensRevokeAll();
}