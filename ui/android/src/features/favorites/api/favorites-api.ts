/**
 * Favorites API -- API client for favorites endpoints.
 *
 * Uses shared Axios instance with DPoP auth.
 */

import { catalogApi } from '@/features/catalog/api/catalog-api';

/**
 * Favorite types.
 */
export interface Favorite {
  uuid: string;
  publicId: string;
  entityType: 'song' | 'album' | 'artist';
  entityPublicId: string;
  createdAt: string;
}

/**
 * Fetch user's favorites (paginated).
 */
export async function getFavorites(
  params: { entityType?: string; page?: number; limit?: number } = {},
): Promise<{ data: Favorite[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }> {
  const { data } = await catalogApi.get<{
    data: Favorite[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
  }>('/api/favorites', {
    params: {
      entityType: params.entityType,
      page: params.page ?? 1,
      limit: params.limit ?? 50,
    },
  });
  return data;
}

/**
 * Add a favorite.
 */
export async function addFavorite(
  entityType: string,
  entityPublicId: string,
): Promise<Favorite> {
  const { data } = await catalogApi.post<{ data: Favorite }>('/api/favorites', {
    entityType,
    entityPublicId,
  });
  return data.data;
}

/**
 * Remove a favorite.
 */
export async function removeFavorite(publicId: string): Promise<void> {
  await catalogApi.delete(`/api/favorites/${publicId}`);
}
