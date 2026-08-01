import Axios, { AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios';
import { useAuthStore } from '@/features/auth/stores/auth-store';
import { getDpopKeyPair, getDpopNonce, setDpopNonce } from '@/shared/crypto/dpop-store';
import { createDpopProof } from '@/shared/crypto/dpop-proof';

const getAuthStore = () => useAuthStore.getState();

interface CustomAxiosRequestConfig extends AxiosRequestConfig {
  _skipAuth?: boolean;
  _didRetry?: boolean;
  _dpopRetryCount?: number;
}

const MAX_DPOP_NONCE_RETRIES = 1;

export const AXIOS_INSTANCE = Axios.create({
  baseURL: window.__BAANDER_API_URL__,
  withCredentials: false,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

/**
 * Build the htu (HTTP URI) for a DPoP proof.
 * Strips query and fragment, normalizes to https scheme.
 */
function buildHtu(url: string): string {
  try {
    const baseUrl = window.__BAANDER_API_URL__;
    const parsed = new URL(url, baseUrl);
    // Normalize http/https to https per RFC 9449 §4.3
    return `https://${parsed.host}${parsed.pathname}`;
  } catch {
    return url;
  }
}

// --- Request interceptor: attach DPoP proof + sender-constrained token ---
AXIOS_INSTANCE.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  const customConfig = config as CustomAxiosRequestConfig;
  const {accessToken} = getAuthStore();
  const keyPair = getDpopKeyPair();

  if (keyPair) {
    // Attach DPoP proof whenever a key pair exists (including login).
    // Only attach the Authorization header when _skipAuth is false.
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

// --- Response interceptor: extract DPoP-Nonce from responses ---
AXIOS_INSTANCE.interceptors.response.use((response) => {
  const nonce = response.headers?.['dpop-nonce'];
  if (typeof nonce === 'string' && nonce !== '') {
    setDpopNonce(nonce);
  }
  return response;
});

// --- Token refresh: queue concurrent 401s, retry after refresh ---
let isRefreshing = false;
let pendingRequests: Array<{
  resolve: (token: string) => void
  reject: (error: unknown) => void
}> = [];

function processPendingRequests(token: string | null, error?: unknown) {
  pendingRequests.forEach(({resolve, reject}) => {
    if (token) resolve(token);
    else reject(error);
  });
  pendingRequests = [];
}

AXIOS_INSTANCE.interceptors.response.use(undefined, async (error) => {
  const originalRequest = error.config as CustomAxiosRequestConfig;

  if (error.response?.status !== 401 || originalRequest._skipAuth || originalRequest._didRetry) {
    // Handle use_dpop_nonce error from token endpoint: retry with nonce
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
      pendingRequests.push({resolve, reject});
    }).then((token) => {
      originalRequest.headers!.Authorization = `DPoP ${token}`;
      return AXIOS_INSTANCE(originalRequest);
    });
  }

  originalRequest._didRetry = true;
  isRefreshing = true;

  try {
    const {refreshToken} = getAuthStore();
    if (!refreshToken) throw new Error('No refresh token');

    const keyPair = getDpopKeyPair();
    if (!keyPair) throw new Error('No DPoP key pair');

    const refreshHtu = buildHtu('/api/auth/refresh');
    const proof = await createDpopProof(keyPair, 'POST', refreshHtu, {
      nonce: getDpopNonce() ?? undefined,
    });

    const baseUrl = window.__BAANDER_API_URL__;
    const refreshResponse = await Axios.post(`${baseUrl}/api/auth/refresh`, {
      refreshToken,
    }, {
      headers: {
        DPoP: proof,
      },
    });

    const data = refreshResponse.data;
    const newAccessToken = data?.data?.accessToken ?? data?.accessToken;
    if (!newAccessToken) {
      throw new Error('Refresh returned no access token');
    }

    // Extract DPoP-Nonce from refresh response before persisting so the
    // latest nonce is written to IndexedDB via setTokens.
    const nonce = refreshResponse.headers?.['dpop-nonce'] ?? refreshResponse.headers?.['DPoP-Nonce'];
    if (typeof nonce === 'string' && nonce !== '') {
      setDpopNonce(nonce);
    }

    getAuthStore().setTokens(
      newAccessToken,
      data?.data?.refreshToken ?? refreshToken,
    );

    processPendingRequests(newAccessToken);
    originalRequest.headers!.Authorization = `DPoP ${newAccessToken}`;
    return AXIOS_INSTANCE(originalRequest);
  } catch (refreshError) {
    processPendingRequests(null, refreshError);
    getAuthStore().clearAuth();
    window.location.href = '/login';
    return Promise.reject(refreshError);
  } finally {
    isRefreshing = false;
  }
});

export const customInstance = <T>(
  url: string,
  options: RequestInit,
): Promise<T> => {
  const {body, signal, headers, ...rest} = options;
  return AXIOS_INSTANCE({
    url,
    method: rest.method as AxiosRequestConfig['method'],
    data: body as AxiosRequestConfig['data'],
    headers: headers as AxiosRequestConfig['headers'],
    signal: signal as AxiosRequestConfig['signal'],
  }).then((response) => ({
    ...response.data,
    status: response.status,
    headers: response.headers,
  })) as Promise<T>;
};

export type ErrorType<Error> = AxiosError<Error>

export type BodyType<BodyData> = BodyData
