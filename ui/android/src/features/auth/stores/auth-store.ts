/**
 * Android Auth store -- Zustand with AsyncStorage persist.
 *
 * Supports three auth methods:
 * 1. loginViaQR(qrData) — scans QR, sends pairing code to discovery endpoint, gets server URL + token
 * 2. login(email, password, serverUrl) — direct email + URL login
 * 3. loginViaServerCode(pairingCode, serverPublicId) — server code + ID auth via discovery endpoint
 *
 * Uses:
 * - AsyncStorage for token persistence
 * - react-native-keychain for DPoP key storage
 * - @baander/shared crypto (with RN quick-crypto backend)
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

/**
 * Parsed QR code data.
 * Format: baander://pair?server={publicId}&code={pairingCode}
 */
export interface QRPairingData {
  serverPublicId: string;
  pairingCode: string;
}

/**
 * Parse a QR code payload into its components.
 */
function parseQrPayload(qrData: string): QRPairingData {
  // Expected format: baander://pair?server={publicId}&code={pairingCode}
  const url = new URL(qrData);
  const serverPublicId = url.searchParams.get('server');
  const pairingCode = url.searchParams.get('code');

  if (!serverPublicId || !pairingCode) {
    throw new Error('Invalid QR code format. Expected baander://pair?server=...&code=...');
  }

  return { serverPublicId, pairingCode };
}

interface AuthState {
  accessToken: string | null;
  refreshToken: string | null;
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  serverUrl: string | null;

  /** Direct email + password login to a known server */
  login: (email: string, password: string, totpCode?: string) => Promise<void>;
  /** QR code scan login — discovers server from QR payload */
  loginViaQR: (qrData: string) => Promise<void>;
  /** Server code + ID login via discovery endpoint */
  loginViaServerCode: (pairingCode: string, serverPublicId: string) => Promise<void>;
  logout: () => Promise<void>;
  setTokens: (accessToken: string, refreshToken: string) => void;
  clearAuth: () => void;
  initAuth: () => Promise<void>;
  setServerUrl: (url: string) => void;
}

/**
 * Build the htu for DPoP proof.
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

      /** Method 1: Direct email + password login */
      login: async (email: string, password: string, totpCode?: string) => {
        if (get().isLoading) throw new Error('Login already in progress');
        const serverUrl = get().serverUrl;
        if (!serverUrl) throw new Error('Server URL not configured');

        set({ isLoading: true });
        try {
          const keyPair = await generateDpopKeyPair();
          setDpopKeyPair(keyPair);

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

          await saveDpopKeyPair(keyPair);
        } finally {
          set({ isLoading: false });
        }
      },

      /** Method 2: QR code scan — discovers server from QR payload */
      loginViaQR: async (qrData: string) => {
        if (get().isLoading) throw new Error('Login already in progress');

        set({ isLoading: true });
        try {
          const { serverPublicId, pairingCode } = parseQrPayload(qrData);

          // Complete pairing via discovery endpoint to get server URL
          const discoveryUrl = get().serverUrl || 'https://discovery.baander.com';
          const response = await Axios.post(`${discoveryUrl}/api/discovery/complete-pairing`, {
            pairingCode,
            serverPublicId,
          });

          const serverUrl = response.data?.data?.serverUrl;
          if (!serverUrl) {
            throw new Error('Discovery did not return a server URL');
          }

          // Now authenticate with the discovered server
          set({ serverUrl: serverUrl.replace(/\/+$/, '') });

          const keyPair = await generateDpopKeyPair();
          setDpopKeyPair(keyPair);

          // Use the pairing code as a device authorization grant
          const proof = await createRequestProof('POST', '/api/auth/device/authorize', serverUrl);
          const authResponse = await Axios.post(`${serverUrl}/api/auth/device/authorize`, {
            deviceCode: pairingCode,
          }, {
            headers: {
              'Content-Type': 'application/json',
              'DPoP': proof,
            },
          });

          const nonce = authResponse.headers?.['dpop-nonce'];
          if (typeof nonce === 'string' && nonce !== '') {
            setDpopNonce(nonce);
          }

          const tokenData = authResponse.data?.data;
          if (!tokenData?.accessToken || !tokenData?.refreshToken) {
            throw new Error('Authorization response missing required token fields');
          }

          set({
            accessToken: tokenData.accessToken,
            refreshToken: tokenData.refreshToken,
            user: tokenData.user as User,
            isAuthenticated: true,
          });

          await saveDpopKeyPair(keyPair);
        } finally {
          set({ isLoading: false });
        }
      },

      /** Method 3: Server code + public ID login via discovery endpoint */
      loginViaServerCode: async (pairingCode: string, serverPublicId: string) => {
        if (get().isLoading) throw new Error('Login already in progress');

        set({ isLoading: true });
        try {
          // Complete pairing via discovery endpoint to get server URL
          const discoveryUrl = get().serverUrl || 'https://discovery.baander.com';
          const response = await Axios.post(`${discoveryUrl}/api/discovery/complete-pairing`, {
            pairingCode,
            serverPublicId,
          });

          const serverUrl = response.data?.data?.serverUrl;
          if (!serverUrl) {
            throw new Error('Discovery did not return a server URL');
          }

          // Now authenticate with the discovered server
          set({ serverUrl: serverUrl.replace(/\/+$/, '') });

          const keyPair = await generateDpopKeyPair();
          setDpopKeyPair(keyPair);

          const proof = await createRequestProof('POST', '/api/auth/device/authorize', serverUrl);
          const authResponse = await Axios.post(`${serverUrl}/api/auth/device/authorize`, {
            deviceCode: pairingCode,
          }, {
            headers: {
              'Content-Type': 'application/json',
              'DPoP': proof,
            },
          });

          const nonce = authResponse.headers?.['dpop-nonce'];
          if (typeof nonce === 'string' && nonce !== '') {
            setDpopNonce(nonce);
          }

          const tokenData = authResponse.data?.data;
          if (!tokenData?.accessToken || !tokenData?.refreshToken) {
            throw new Error('Authorization response missing required token fields');
          }

          set({
            accessToken: tokenData.accessToken,
            refreshToken: tokenData.refreshToken,
            user: tokenData.user as User,
            isAuthenticated: true,
          });

          await saveDpopKeyPair(keyPair);
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
          const keyPair = await loadDpopKeyPair();
          if (!keyPair) return;

          setDpopKeyPair(keyPair);
        } catch {
          // Keychain unavailable or corrupted -- start unauthenticated
        }
      },
    }),
    {
      name: 'baander-android-auth',
      storage: createJSONStorage(() => AsyncStorage),
      partialize: (state) => ({
        accessToken: state.accessToken,
        refreshToken: state.refreshToken,
        user: state.user,
        isAuthenticated: state.isAuthenticated,
        serverUrl: state.serverUrl,
      }),
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
