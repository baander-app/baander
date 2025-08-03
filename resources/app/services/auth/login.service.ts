import { authLogin } from '@/libs/api-client/gen/endpoints/auth/auth.ts';
import { Token } from '@/services/auth/token.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service.ts';

export async function login(credentials: { email: string; password: string }) {
  try {
    // Use the login endpoint with _skipAuth to avoid auth headers
    const response = await authLogin(credentials, {
      _skipAuth: true, // This prevents the interceptor from adding auth headers
    });

    const { accessToken, refreshToken, sessionId } = response;

    // Store the session ID for token binding
    if (sessionId) {
      tokenBindingService.setSessionId(sessionId);
    }

    // Store the tokens
    Token.set({
      accessToken: accessToken,
      refreshToken: refreshToken,
      sessionId,
    });

    return response;
  } catch (error) {
    // Clear any partial data on login failure
    tokenBindingService.clear();
    Token.clear();
    throw error;
  }
}

export async function logout() {
  try {
    // Call logout endpoint if needed
    // await authLogout();
  } catch (error) {
    console.warn('Logout API call failed:', error);
  } finally {
    // Always clear local data
    Token.clear();
    tokenBindingService.clear();
  }
}