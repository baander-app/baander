/**
 * useAdminStats -- fetch server statistics for admin dashboard.
 */

import { useState, useEffect } from 'react';
import { getAuthSnapshot } from '@/features/auth/stores/auth-store';
import Axios from 'axios';

export interface ServerStats {
  trackCount: number;
  albumCount: number;
  artistCount: number;
  userCount: number;
  storageUsed: number;
  storageTotal: number;
}

export interface UseAdminStatsResult {
  data: ServerStats | null;
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;
}

export function useAdminStats(): UseAdminStatsResult {
  const [data, setData] = useState<ServerStats | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [key, setKey] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function fetch() {
      setIsLoading(true);
      setError(null);

      const auth = getAuthSnapshot();
      if (!auth.serverUrl || !auth.isAuthenticated) {
        setIsLoading(false);
        return;
      }

      try {
        const { data: response } = await Axios.get<{ data: ServerStats }>(
          `${auth.serverUrl}/api/debug/stats`,
          {
            headers: auth.accessToken ? { Authorization: `Bearer ${auth.accessToken}` } : {},
          },
        );

        if (!cancelled) {
          setData(response.data);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch stats'));
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    }

    fetch();

    return () => {
      cancelled = true;
    };
  }, [key]);

  return {
    data,
    isLoading,
    error,
    refetch: () => setKey((k) => k + 1),
  };
}
