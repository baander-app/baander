/**
 * useActiveJobs -- fetch active background jobs for admin dashboard.
 */

import { useState, useEffect } from 'react';
import { getAuthSnapshot } from '@/features/auth/stores/auth-store';
import Axios from 'axios';

export interface Job {
  id: string;
  type: string;
  status: 'running' | 'completed' | 'failed';
  progress: number;
  createdAt: string;
}

export interface UseActiveJobsResult {
  data: Job[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;
}

export function useActiveJobs(): UseActiveJobsResult {
  const [data, setData] = useState<Job[]>([]);
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
        const { data: response } = await Axios.get<{ data: Job[] }>(
          `${auth.serverUrl}/api/admin/jobs/active`,
          {
            headers: auth.accessToken ? { Authorization: `Bearer ${auth.accessToken}` } : {},
          },
        );

        if (!cancelled) {
          setData(response.data ?? []);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err : new Error('Failed to fetch jobs'));
          setData([]);
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
