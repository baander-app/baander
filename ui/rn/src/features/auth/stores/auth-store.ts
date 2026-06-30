/**
 * RN Auth store -- Zustand with AsyncStorage persist.
 *
 * Mirrors the web auth-store interface but uses:
 * - AsyncStorage for token persistence (not IndexedDB)
 * - react-native-keychain for DPoP key storage (not IndexedDB structured clone)
 * - @baander/shared crypto (with RN quick-crypto backend)
 * - RN axios instance (not web AXIOS_INSTANCE)
 */

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import AsyncStorage from '@react-native-async-storage/async-storage';
import Axios from 'axios';
import {
  generateDpopKeyPair,
  getDpopKeyPair,
  setDpopKeyPair,
  clearDpopKeyPair,
  getDpopNonce,
  setDpopNonce,
  createDpopProof,
} from '@baander/shared';
import {
  saveDpopKeyPair,
  loadDpopKeyPair,
  clearDpopKeyPair as clearKeychainKey,
} from '@/shared/crypto/keychain-storage';

export interface User {
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
  serverUrl: string | null;

  login: (email: string, password: string, totpCode?: string) => Promise<void>;
  register: (email: string, password: string, name?: string) => Promise<void>;
  logout: () => Promise<void>;
  setTokens: (accessToken: string, refreshToken: string) => void;
  clearAuth: () => void;
  initAuth: () => Promise<void>;
  setServerUrl: (url: string) => void;
}

/**
 * Build the htu for DPoP proof. Strips query/fragment, normalizes to https.
 */
function buildHtu(url: string, serverUrl: string): string {
  try {
    const parsed = new URL(url, serverUrl);
    return `https://${parsed.host}${parsed.pathname}`;
  } catch {
    return url;
  }
}

/**
 * Create a DPoP proof for a request.
 */
async function createRequestProof(method: string, url: string, serverUrl: string, accessToken?: string): Promise<string> {
  const keyPair = getDpopKeyPair();
  if (!keyPair) throw new Error('No DPoP key pair');

  const htu = buildHtu(url, serverUrl);
  return createDpopProof(keyPair, method, htu, {
    accessToken,
    nonce: getDpopNonce() ?? undefined,
  });
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      accessToken: null,
      refreshToken: null,
      user: null,
      isAuthenticated: false,
      isLoading: false,
      serverUrl: null,

      setServerUrl: (url: string) => {
        set({ serverUrl: url.replace(/\/+$/, '') });
      },

      login: async (email: string, password: string, totpCode?: string) => {
        if (get().isLoading) throw new Error('Login already in progress');
        const serverUrl = get().serverUrl;
        if (!serverUrl) throw new Error('Server URL not configured');

        set({ isLoading: true });
        try {
          // Generate DPoP key pair before login
          const keyPair = await generateDpopKeyPair();
          setDpopKeyPair(keyPair);

          // Create DPoP proof for login (no access token yet)
          const proof = await createRequestProof('POST', '/api/auth/login', serverUrl);

          const response = await Axios.post(`${serverUrl}/api/auth/login`, {
            email,
            password,
            totpCode: totpCode ?? '',
          }, {
            headers: {
              'Content-Type': 'application/json',
              'DPoP': proof,
            },
          });

          // Extract nonce from response
          const nonce = response.headers?.['dpop-nonce'];
          if (typeof nonce === 'string' && nonce !== '') {
            setDpopNonce(nonce);
          }

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

          // Persist DPoP key pair to device keychain
          await saveDpopKeyPair(keyPair);
        } finally {
          set({ isLoading: false });
        }
      },

      register: async (email: string, password: string, name?: string) => {
        const serverUrl = get().serverUrl;
        if (!serverUrl) throw new Error('Server URL not configured');

        set({ isLoading: true });
        try {
          await Axios.post(`${serverUrl}/api/auth/register`, {
            email,
            password,
            name: name ?? '',
          });
        } finally {
          set({ isLoading: false });
        }
      },

      logout: async () => {
        set({ isLoading: true });
        try {
          const serverUrl = get().serverUrl;
          const accessToken = get().accessToken;

          if (serverUrl && accessToken) {
            try {
              const proof = await createRequestProof('POST', '/api/auth/logout', serverUrl, accessToken);
              await Axios.post(`${serverUrl}/api/auth/logout`, {}, {
                headers: {
                  Authorization: `DPoP ${accessToken}`,
                  DPoP: proof,
                },
              });
            } catch {
              // Logout API call failed -- clear auth anyway
            }
          }
          get().clearAuth();
        } finally {
          set({ isLoading: false });
        }
      },

      setTokens: (accessToken: string, refreshToken: string) => {
        set({ accessToken, refreshToken, isAuthenticated: true });
      },

      clearAuth: () => {
        clearDpopKeyPair();
        set({
          accessToken: null,
          refreshToken: null,
          user: null,
          isAuthenticated: false,
        });
        clearKeychainKey().catch(() => {});
      },

      initAuth: async () => {
        try {
          // Load DPoP key pair from keychain
          const keyPair = await loadDpopKeyPair();
          if (!keyPair) return;

          setDpopKeyPair(keyPair);

          // If tokens exist in persisted state, we're authenticated
          // (Zustand persist handles token restoration via AsyncStorage)
        } catch {
          // Keychain unavailable or corrupted -- start unauthenticated
        }
      },
    }),
    {
      name: 'baander-auth',
      storage: createJSONStorage(() => AsyncStorage),
      // Only persist tokens and user, not loading state or functions
      partialize: (state) => ({
        accessToken: state.accessToken,
        refreshToken: state.refreshToken,
        user: state.user,
        isAuthenticated: state.isAuthenticated,
        serverUrl: state.serverUrl,
      }),
      // After rehydration, load keychain keys
      onRehydrateStorage: () => (state) => {
        if (state?.isAuthenticated) {
          state.initAuth();
        }
      },
    },
  ),
);

/** Read auth state outside React -- used by interceptors. */
export function getAuthSnapshot() {
  return useAuthStore.getState();
}
