/**
 * Catalog API -- platform-agnostic API client.
 *
 * Uses Axios with DPoP auth from auth-store.
 * All endpoints return data suitable for Zustand stores or direct consumption.
 */

import Axios from 'axios';
import { getAuthSnapshot } from '@/features/auth/stores/auth-store';
import { createDpopProof, getDpopNonce, setDpopNonce } from '@baander/shared';

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
 * Create DPoP proof for a request.
 */
async function createRequestProof(method: string, url: string, serverUrl: string, accessToken?: string): Promise<string> {
  const keyPair = await import('@baander/shared').then((m) => m.getDpopKeyPair());
  if (!keyPair) throw new Error('No DPoP key pair');

  const htu = buildHtu(url, serverUrl);
  return createDpopProof(keyPair, method, htu, {
    accessToken,
    nonce: getDpopNonce() ?? undefined,
  });
}

/**
 * Axios instance with DPoP interceptor.
 */
const catalogApi = Axios.create();

// Request interceptor: add DPoP proof
catalogApi.interceptors.request.use(async (config) => {
  const auth = getAuthSnapshot();
  if (!auth.serverUrl) return config;

  config.baseURL = auth.serverUrl;

  // Add DPoP proof
  const proof = await createRequestProof(
    config.method?.toUpperCase() ?? 'GET',
    config.url ?? '',
    auth.serverUrl,
    auth.accessToken ?? undefined,
  );
  config.headers['DPoP'] = proof;

  // Add auth token if available
  if (auth.accessToken) {
    config.headers['Authorization'] = `DPoP ${auth.accessToken}`;
  }

  return config;
});

// Response interceptor: extract DPoP nonce
catalogApi.interceptors.response.use(
  (response) => {
    const nonce = response.headers?.['dpop-nonce'];
    if (typeof nonce === 'string' && nonce !== '') {
      setDpopNonce(nonce);
    }
    return response;
  },
  async (error) => {
    // Token refresh on 401
    if (error.response?.status === 401 && error.config && !error.config._retry) {
      error.config._retry = true;
      // Token refresh logic would go here
      // For now, just clear auth
      const auth = getAuthSnapshot();
      if (auth.isAuthenticated) {
        auth.clearAuth();
      }
    }
    return Promise.reject(error);
  },
);

/**
 * API response wrapper.
 */
export interface ApiResponse<T> {
  data: T;
  meta?: {
    total: number;
    page: number;
    limit: number;
  };
}

/**
 * Album types.
 */
export interface Album {
  uuid: string;
  publicId: string;
  title: string;
  artistName: string | null;
  coverImageBlurhash: string | null;
  releaseYear: number | null;
  songCount: number;
  duration: number;
}

/**
 * Artist types.
 */
export interface Artist {
  uuid: string;
  publicId: string;
  name: string;
  imageBlurhash: string | null;
  albumCount: number;
  songCount: number;
}

/**
 * Song types.
 */
export interface Song {
  uuid: string;
  publicId: string;
  title: string;
  artistName: string | null;
  albumName: string | null;
  albumPublicId: string | null;
  duration: number | null;
  trackNumber: number | null;
  discNumber: number | null;
  coverImageBlurhash: string | null;
}

/**
 * Genre types.
 */
export interface Genre {
  uuid: string;
  publicId: string;
  name: string;
  albumCount: number;
}

/**
 * Fetch albums.
 */
export async function getAlbums(params: { page?: number; limit?: number } = {}): Promise<ApiResponse<Album[]>> {
  const { data } = await catalogApi.get<ApiResponse<Album[]>>('/api/albums', {
    params: { page: params.page ?? 1, limit: params.limit ?? 20 },
  });
  return data.data;
}

/**
 * Fetch album by public ID.
 */
export async function getAlbum(publicId: string): Promise<ApiResponse<Album>> {
  const { data } = await catalogApi.get<ApiResponse<Album>>(`/api/albums/${publicId}`);
  return data.data;
}

/**
 * Fetch album tracks.
 */
export async function getAlbumTracks(publicId: string): Promise<ApiResponse<Song[]>> {
  const { data } = await catalogApi.get<ApiResponse<Song[]>>(`/api/albums/${publicId}/tracks`);
  return data.data;
}

/**
 * Fetch artists.
 */
export async function getArtists(params: { page?: number; limit?: number } = {}): Promise<ApiResponse<Artist[]>> {
  const { data } = await catalogApi.get<ApiResponse<Artist[]>>('/api/artists', {
    params: { page: params.page ?? 1, limit: params.limit ?? 20 },
  });
  return data.data;
}

/**
 * Fetch artist by public ID.
 */
export async function getArtist(publicId: string): Promise<ApiResponse<Artist>> {
  const { data } = await catalogApi.get<ApiResponse<Artist>>(`/api/artists/${publicId}`);
  return data.data;
}

/**
 * Fetch artist albums.
 */
export async function getArtistAlbums(publicId: string): Promise<ApiResponse<Album[]>> {
  const { data } = await catalogApi.get<ApiResponse<Album[]>>(`/api/artists/${publicId}/albums`);
  return data.data;
}

/**
 * Fetch genres.
 */
export async function getGenres(): Promise<ApiResponse<Genre[]>> {
  const { data } = await catalogApi.get<ApiResponse<Genre[]>>('/api/genres');
  return data.data;
}

/**
 * Search catalog.
 */
export interface SearchParams {
  query: string;
  type?: 'album' | 'artist' | 'song';
  page?: number;
  limit?: number;
}

export interface SearchResult {
  albums: Album[];
  artists: Artist[];
  songs: Song[];
}

export async function search(params: SearchParams): Promise<ApiResponse<SearchResult>> {
  const { data } = await catalogApi.get<ApiResponse<SearchResult>>('/api/search', {
    params: {
      query: params.query,
      type: params.type,
      page: params.page ?? 1,
      limit: params.limit ?? 20,
    },
  });
  return data.data;
}
