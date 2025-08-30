import { create } from 'zustand';
import { persist, subscribeWithSelector } from 'zustand/middleware';
import {
  login as loginFn,
  logout as logoutFn,
  revokeAllTokensExceptCurrent as revokeOthersFn,
} from '@/services/auth/auth.service.ts';
import { Token } from '@/services/auth/token.ts';
import { tokenBindingService } from '@/services/auth/token-binding.service.ts';

type AuthStatus = 'idle' | 'loading' | 'authenticated' | 'unauthenticated';

type AuthState = {
  // State
  status: AuthStatus;
  isAuthenticated: boolean;
  sessionId: string | null;

  // Actions
  hydrateFromStorage: () => void;
  login: (credentials: { email: string; password: string }) => Promise<void>;
  logout: () => Promise<void>;
  revokeAllTokensExceptCurrent: () => Promise<void>;
};

export const useAuthStore = create<AuthState>()(
  subscribeWithSelector(
    persist(
      (set) => ({
        status: 'idle',
        isAuthenticated: false,
        sessionId: null,

        hydrateFromStorage: () => {
          try {
            const stored = Token.get();
            const sid = tokenBindingService.getSessionId();
            const authed = !!stored?.accessToken && !!sid;
            set({
              status: authed ? 'authenticated' : 'unauthenticated',
              isAuthenticated: authed,
              sessionId: sid ?? null,
            });
          } catch {
            set({ status: 'unauthenticated', isAuthenticated: false, sessionId: null });
          }
        },

        login: async (credentials) => {
          set({ status: 'loading' });
          try {
            const res = await loginFn(credentials);
            // Tokens and sessionId are persisted by the service/Token helpers.
            const sid = res?.sessionId ?? tokenBindingService.getSessionId() ?? null;
            set({
              status: 'authenticated',
              isAuthenticated: true,
              sessionId: sid,
            });
          } catch (e) {
            set({ status: 'unauthenticated', isAuthenticated: false, sessionId: null });
            throw e;
          }
        },

        logout: async () => {
          set({ status: 'loading' });
          try {
            await logoutFn();
          } finally {
            // Service clears Token + binding; reflect in UI state
            set({ status: 'unauthenticated', isAuthenticated: false, sessionId: null });
          }
        },

        revokeAllTokensExceptCurrent: async () => {
          await revokeOthersFn();
        },
      }),
      {
        name: 'auth-store',
        // Persist only UI-affecting auth state. Tokens/session binding are handled elsewhere.
        partialize: (s) => ({
          status: s.status,
          isAuthenticated: s.isAuthenticated,
          sessionId: s.sessionId,
        }),
      }
    )
  )
);

// Convenience selectors for minimal re-renders
export const useIsAuthenticated = () => useAuthStore((s) => s.isAuthenticated);
export const useAuthStatus = () => useAuthStore((s) => s.status);
export const useSessionId = () => useAuthStore((s) => s.sessionId);

// Optional one-time initializer you can call in app bootstrap
export function initAuthStore() {
  useAuthStore.getState().hydrateFromStorage();
}
