/**
 * Android Axios instance with DPoP interceptors.
 *
 * Same pattern as the RN axios-instance, but:
 * - Uses useAuthStore (Android version)
 * - Gets baseURL from auth store (serverUrl)
 * - No window references
 * - No service worker bridge
 */

import Axios, { AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios';
import { useAuthStore } from '@/features/auth/stores/auth-store';
import { getDpopKeyPair, getDpopNonce, setDpopNonce } from '@baander/shared';
import { createDpopProof } from '@baander/shared';

const getAuthStore = () => useAuthStore.getState();

interface CustomAxiosRequestConfig extends AxiosRequestConfig {
  _skipAuth?: boolean;
  _didRetry?: boolean;
  _dpopRetryCount?: number;
}

const MAX_DPOP_NONCE_RETRIES = 1;

export const AXIOS_INSTANCE = Axios.create({
  withCredentials: false,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'User-Agent': 'Baander/Client(android)/1.0',
  },
});

function buildHtu(url: string): string {
  try {
    const serverUrl = getAuthStore().serverUrl || '';
    const parsed = new URL(url, serverUrl);
    return `https://${parsed.host}${parsed.pathname}`;
  } catch {
    return url;
  }
}

// Set baseURL dynamically per request
AXIOS_INSTANCE.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  config.baseURL = getAuthStore().serverUrl || '';
  return config;
});

// Attach DPoP proof + access token
AXIOS_INSTANCE.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  const customConfig = config as CustomAxiosRequestConfig;
  const { accessToken } = getAuthStore();
  const keyPair = getDpopKeyPair();

  if (keyPair) {
    if (!customConfig._skipAuth && accessToken) {
      config.headers.Authorization = `DPoP ${accessToken}`;
    }

    const htu = buildHtu(config.url ?? '');
    const proof = await createDpopProof(keyPair, config.method ?? 'GET', htu, {
      accessToken: !customConfig._skipAuth ? (accessToken ?? undefined) : undefined,
      nonce: getDpopNonce() ?? undefined,
    });
    config.headers.DPoP = proof;
  }

  return config;
});

// Extract DPoP-Nonce from responses
AXIOS_INSTANCE.interceptors.response.use((response) => {
  const nonce = response.headers?.['dpop-nonce'];
  if (typeof nonce === 'string' && nonce !== '') {
    setDpopNonce(nonce);
  }
  return response;
});

// Token refresh: queue concurrent 401s
let isRefreshing = false;
let pendingRequests: Array<{
  resolve: (token: string) => void;
  reject: (error: unknown) => void;
}> = [];

function processPendingRequests(token: string | null, error?: unknown) {
  pendingRequests.forEach(({ resolve, reject }) => {
    if (token) resolve(token);
    else reject(error);
  });
  pendingRequests = [];
}

AXIOS_INSTANCE.interceptors.response.use(undefined, async (error) => {
  const originalRequest = error.config as CustomAxiosRequestConfig;

  if (error.response?.status !== 401 || originalRequest._skipAuth || originalRequest._didRetry) {
    // Handle use_dpop_nonce retry
    if (
      error.response?.status === 400 &&
      error.response?.data?.error === 'use_dpop_nonce' &&
      !originalRequest._didRetry &&
      (originalRequest._dpopRetryCount ?? 0) < MAX_DPOP_NONCE_RETRIES
    ) {
      const nonce = error.response?.headers?.['dpop-nonce'] ?? error.response?.headers?.['DPoP-Nonce'];
      if (typeof nonce === 'string' && nonce !== '') {
        setDpopNonce(nonce);
        originalRequest._dpopRetryCount = (originalRequest._dpopRetryCount ?? 0) + 1;
        return AXIOS_INSTANCE(originalRequest);
      }
    }

    return Promise.reject(error);
  }

  if (isRefreshing) {
    return new Promise<string>((resolve, reject) => {
      pendingRequests.push({ resolve, reject });
    }).then((token) => {
      originalRequest.headers!.Authorization = `DPoP ${token}`;
      return AXIOS_INSTANCE(originalRequest);
    });
  }

  originalRequest._didRetry = true;
  isRefreshing = true;

  try {
    const { refreshToken } = getAuthStore();
    if (!refreshToken) throw new Error('No refresh token');

    const keyPair = getDpopKeyPair();
    if (!keyPair) throw new Error('No DPoP key pair');

    const refreshHtu = buildHtu('/api/auth/refresh');
    const proof = await createDpopProof(keyPair, 'POST', refreshHtu, {
      nonce: getDpopNonce() ?? undefined,
    });

    const serverUrl = getAuthStore().serverUrl || '';
    const refreshResponse = await Axios.post(`${serverUrl}/api/auth/refresh`, {
      refreshToken,
    }, {
      headers: { DPoP: proof },
    });

    const data = refreshResponse.data;
    const newAccessToken = data?.data?.accessToken ?? data?.accessToken;
    if (!newAccessToken) throw new Error('Refresh returned no access token');

    const nonce = refreshResponse.headers?.['dpop-nonce'] ?? refreshResponse.headers?.['DPoP-Nonce'];
    if (typeof nonce === 'string' && nonce !== '') {
      setDpopNonce(nonce);
    }

    getAuthStore().setTokens(newAccessToken, data?.data?.refreshToken ?? refreshToken);

    processPendingRequests(newAccessToken);
    originalRequest.headers!.Authorization = `DPoP ${newAccessToken}`;
    return AXIOS_INSTANCE(originalRequest);
  } catch (refreshError) {
    processPendingRequests(null, refreshError);
    getAuthStore().clearAuth();
    return Promise.reject(refreshError);
  } finally {
    isRefreshing = false;
  }
});

export const customInstance = <T>(
  url: string,
  options: RequestInit,
): Promise<T> => {
  const { body, signal, headers, ...rest } = options;
  return AXIOS_INSTANCE({
    url,
    method: rest.method as AxiosRequestConfig['method'],
    data: body as AxiosRequestConfig['data'],
    headers: headers as AxiosRequestConfig['headers'],
    signal: signal as unknown as AxiosRequestConfig['signal'],
  }).then((response) => ({
    ...response.data,
    status: response.status,
    headers: response.headers,
  })) as Promise<T>;
};

export type ErrorType<Error> = AxiosError<Error>;
export type BodyType<BodyData> = BodyData;
