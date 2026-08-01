/**
 * useFavorites -- hook for user's favorites with pagination + toggle helper.
 *
 * Usage:
 *   const { favorites, isLoading, error, refetch, toggleFavorite } = useFavorites();
 */

import { useState, useEffect, useCallback } from 'react';
import type { Favorite } from '../api/favorites-api';
import { getFavorites, addFavorite, removeFavorite } from '../api/favorites-api';

export function useFavorites(entityType?: string) {
  const [favorites, setFavorites] = useState<Favorite[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  const loadMore = useCallback(() => {
    if (favorites.length < total) {
      setPage((p) => p + 1);
    }
  }, [favorites.length, total]);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);
      try {
        const result = await getFavorites({ entityType, page, limit: 50 });
        if (!cancelled) {
          if (page === 1) {
            setFavorites(result.data);
          } else {
            setFavorites((prev) => [...prev, ...result.data]);
          }
          setTotal(result.meta.total);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch favorites'));
        }
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    }

    fetch();
    return () => { cancelled = true; };
  }, [entityType, page, key]);

  const toggleFavorite = useCallback(
    async (entityType: string, entityPublicId: string) => {
      const existing = favorites.find(
        (f) => f.entityType === entityType && f.entityPublicId === entityPublicId,
      );

      try {
        if (existing) {
          await removeFavorite(existing.publicId);
          setFavorites((prev) => prev.filter((f) => f.publicId !== existing.publicId));
          setTotal((t) => t - 1);
        } else {
          const added = await addFavorite(entityType, entityPublicId);
          setFavorites((prev) => [...prev, added]);
          setTotal((t) => t + 1);
        }
      } catch (err) {
        setError(err instanceof Error ? err : new Error('Failed to toggle favorite'));
      }
    },
    [favorites],
  );

  return {
    favorites,
    total,
    isLoading,
    error,
    refetch: () => {
      setPage(1);
      setKey((k) => k + 1);
    },
    loadMore,
    toggleFavorite,
  };
}
