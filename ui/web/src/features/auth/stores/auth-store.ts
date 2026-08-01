import { create } from 'zustand';
import { devtools } from 'zustand/middleware';
import {
  type LoginRequest,
  postAuthLogout,
  postAuthRegister,
} from '@/shared/api-client/gen/endpoints';
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance';
import type { AxiosRequestConfig } from 'axios';
import { postTokenToWorker } from '@/features/player/services/service-worker-bridge';
import { generateDpopKeyPair } from '@/shared/crypto/dpop-key-pair';
import { getDpopKeyPair, setDpopKeyPair, clearDpopKeyPair, getDpopNonce, setDpopNonce } from '@/shared/crypto/dpop-store';
import { loadStoredAuth, saveStoredAuth, clearStoredAuth } from '@/shared/crypto/auth-db';
import { createLogger } from '@/shared/lib/logger';

interface User {
  uuid: string;
  email: string;
  publicId: string;
  name: string | null;
  roles: string[];
}

interface AuthState {
  accessToken: string | null;
  refreshToken: string | null;
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (email: string, password: string, totpCode?: string, honeypot?: string) => Promise<void>;
  register: (email: string, password: string, name?: string) => Promise<void>;
  logout: () => Promise<void>;
  setTokens: (accessToken: string, refreshToken: string) => void;
  clearAuth: () => void;
  initAuth: () => Promise<void>;
}

const logger = createLogger('AuthStore')

function isMockAuth() {
  return import.meta.env.VITE_MOCK_AUTH === 'true';
}

export const useAuthStore = create<AuthState>()(
  devtools(
    (set, get) => ({
  accessToken: null,
  refreshToken: null,
  user: null,
  isAuthenticated: false,
  isLoading: false,

  login: async (email: string, password: string, totpCode?: string, honeypot?: string) => {
    if (get().isLoading) throw new Error('Login already in progress');
    set({isLoading: true});
    try {
      if (isMockAuth()) {
        await new Promise((r) => setTimeout(r, 300));
        set({
          accessToken: 'mock-access-token',
          refreshToken: 'mock-refresh-token',
          user: {uuid: '1', email, publicId: 'usr_abc123', name: null, roles: ['ROLE_USER']},
          isAuthenticated: true,
        });
        return;
      }

      // Generate DPoP key pair before login (per-session ephemeral key)
      const keyPair = await generateDpopKeyPair();
      setDpopKeyPair(keyPair);

      const loginRequest = {email, password, totpCode: totpCode ?? '', ...(honeypot ? {username: honeypot} : {})} satisfies LoginRequest;
      // Use AXIOS_INSTANCE directly to get full AxiosResponse with headers.
      // DPoP proof is attached automatically by the request interceptor.
      const response = await AXIOS_INSTANCE.post('/api/auth/login', loginRequest, {
        headers: { 'Content-Type': 'application/json' },
        _skipAuth: true,
      } as AxiosRequestConfig);

      // Backend wraps in {data: {...}} envelope; Axios stores HTTP body in response.data
      const tokenData = response.data?.data;
      if (!tokenData?.accessToken || !tokenData?.refreshToken) {
        throw new Error('Login response missing required token fields');
      }

      set({
        accessToken: tokenData.accessToken,
        refreshToken: tokenData.refreshToken,
        user: tokenData.user as User,
        isAuthenticated: true,
      });

      // Persist to IndexedDB (non-exportable CryptoKeyPair survives structured clone)
      await saveStoredAuth({
        cryptoKeyPair: { publicKey: keyPair.publicKey, privateKey: keyPair.privateKey },
        publicJwk: keyPair.jwk,
        jkt: keyPair.jkt,
        accessToken: tokenData.accessToken,
        refreshToken: tokenData.refreshToken,
        user: tokenData.user as User,
        nonce: getDpopNonce(),
      });

      // DPoP nonce is extracted by the response interceptor automatically.
      postTokenToWorker(tokenData.accessToken).catch((err) => {
        logger.warn('Failed to push token to service worker:', err);
      });
    } finally {
      set({isLoading: false});
    }
  },

  register: async (email: string, password: string, name?: string) => {
    set({isLoading: true});
    try {
      if (isMockAuth()) {
        await new Promise((r) => setTimeout(r, 300));
        return;
      }

      await postAuthRegister({email, password, name: name ?? ''});
    } finally {
      set({isLoading: false});
    }
  },

  logout: async () => {
    set({isLoading: true});
    try {
      if (isMockAuth()) {
        await new Promise((r) => setTimeout(r, 100));
        set({
          accessToken: null,
          refreshToken: null,
          user: null,
          isAuthenticated: false,
        });
        return;
      }

      try {
        await postAuthLogout();
      } finally {
        get().clearAuth();
      }
    } finally {
      set({isLoading: false});
    }
  },

  setTokens: (accessToken, refreshToken) => {
    set({
      accessToken,
      refreshToken,
      isAuthenticated: true,
    });
    const keyPair = getDpopKeyPair();
    const user = get().user;

    // Persist updated tokens to IndexedDB (fire-and-forget)
    if (keyPair && user) {
      saveStoredAuth({
        cryptoKeyPair: { publicKey: keyPair.publicKey, privateKey: keyPair.privateKey },
        publicJwk: keyPair.jwk,
        jkt: keyPair.jkt,
        accessToken,
        refreshToken,
        user,
        nonce: getDpopNonce(),
      }).catch((err) => {
        logger.warn('Failed to persist updated tokens:', err);
      });
    }

    postTokenToWorker(accessToken).catch((err) => {
      logger.warn('Failed to push token to service worker:', err);
    });
  },

  clearAuth: () => {
    clearDpopKeyPair();
    set({
      accessToken: null,
      refreshToken: null,
      user: null,
      isAuthenticated: false,
    });
    clearStoredAuth().catch((err) => { logger.warn('Failed to clear stored auth:', err) });
  },

  initAuth: async () => {
    try {
      const stored = await loadStoredAuth();
      if (!stored) return;

      // Reconstruct DpopKeyPair from persisted CryptoKeyPair + derived values
      setDpopKeyPair({
        publicKey: stored.cryptoKeyPair.publicKey,
        privateKey: stored.cryptoKeyPair.privateKey,
        jwk: stored.publicJwk,
        jkt: stored.jkt,
      });

      if (stored.nonce) {
        setDpopNonce(stored.nonce);
      }

      set({
        accessToken: stored.accessToken,
        refreshToken: stored.refreshToken,
        user: stored.user as User,
        isAuthenticated: true,
      });
    } catch (err) {
      logger.warn('Failed to load stored auth, starting unauthenticated:', err)
    }
  },
}),
    { name: 'AuthStore', enabled: import.meta.env.DEV },
  ),
);

/** Read tokens outside of React — used by interceptors and service worker messaging. */
export function getAuthSnapshot() {
  return useAuthStore.getState();
}
