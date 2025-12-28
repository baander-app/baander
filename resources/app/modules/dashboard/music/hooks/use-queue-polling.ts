import { useEffect, useRef, useState, useCallback } from 'react';
import { QueueMetrics, QueueJob } from '../types/metadata.types';

interface UseQueuePollingOptions {
  enabled?: boolean;
  interval?: number; // in milliseconds
  jobNames?: string[]; // Filter by specific job names
  onData?: (metrics: QueueMetrics) => void;
  onComplete?: (job: QueueJob) => void;
  onError?: (error: Error) => void;
}

interface UseQueuePollingReturn {
  metrics: QueueMetrics | null;
  isPolling: boolean;
  error: Error | null;
  startPolling: () => void;
  stopPolling: () => void;
  refetch: () => Promise<void>;
}

/**
 * Custom hook for polling queue metrics from the backend
 *
 * @param options - Configuration options for the polling hook
 * @returns Queue metrics and control functions
 *
 * @example
 * ```tsx
 * const { metrics, isPolling, startPolling, stopPolling } = useQueuePolling({
 *   enabled: true,
 *   interval: 2000,
 *   jobNames: ['SyncAlbumJob', 'SyncArtistJob'],
 *   onData: (metrics) => console.log('Metrics updated:', metrics),
 *   onComplete: (job) => toast.success(`Job ${job.payload.displayName} completed`),
 * });
 * ```
 */
export function useQueuePolling({
  enabled = true,
  interval = 2000,
  jobNames,
  onData,
  onComplete,
  onError,
}: UseQueuePollingOptions = {}): UseQueuePollingReturn {
  const [metrics, setMetrics] = useState<QueueMetrics | null>(null);
  const [isPolling, setIsPolling] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const intervalRef = useRef<NodeJS.Timeout | null>(null);
  const previousJobsRef = useRef<Map<string, string>>(new Map());

  /**
   * Fetch queue metrics from the API
   */
  const fetchMetrics = useCallback(async (): Promise<void> => {
    try {
      const params = new URLSearchParams();
      if (jobNames && jobNames.length > 0) {
        jobNames.forEach(name => params.append('job_names[]', name));
      }

      const response = await fetch(`/api/queue-metrics?${params.toString()}`);

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data: QueueMetrics = await response.json();

      // Filter jobs if jobNames are specified
      let filteredData = data;
      if (jobNames && jobNames.length > 0 && data.jobs) {
        filteredData = {
          ...data,
          jobs: data.jobs.filter(job =>
            jobNames.some(name => job.payload.commandName?.includes(name))
          ),
        };
      }

      setMetrics(filteredData);
      setError(null);
      onData?.(filteredData);

      // Check for newly completed jobs
      if (filteredData.jobs) {
        filteredData.jobs.forEach(job => {
          const previousStatus = previousJobsRef.current.get(job.id);
          if (previousStatus !== 'completed' && job.status === 'completed') {
            onComplete?.(job);
          }
          previousJobsRef.current.set(job.id, job.status);
        });
      }
    } catch (err) {
      const errorObj = err instanceof Error ? err : new Error('Unknown error');
      setError(errorObj);
      onError?.(errorObj);
    }
  }, [jobNames, onData, onComplete, onError]);

  /**
   * Start polling
   */
  const startPolling = useCallback(() => {
    if (intervalRef.current) {
      return; // Already polling
    }

    setIsPolling(true);

    // Initial fetch
    fetchMetrics();

    // Set up interval
    intervalRef.current = setInterval(() => {
      fetchMetrics();
    }, interval);
  }, [interval, fetchMetrics]);

  /**
   * Stop polling
   */
  const stopPolling = useCallback(() => {
    if (intervalRef.current) {
      clearInterval(intervalRef.current);
      intervalRef.current = null;
      setIsPolling(false);
    }
  }, []);

  /**
   * Manually trigger a refetch
   */
  const refetch = useCallback(async () => {
    await fetchMetrics();
  }, [fetchMetrics]);

  /**
   * Start/stop polling based on enabled state
   */
  useEffect(() => {
    if (enabled) {
      startPolling();
    } else {
      stopPolling();
    }

    // Cleanup on unmount
    return () => {
      stopPolling();
    };
  }, [enabled, startPolling, stopPolling]);

  return {
    metrics,
    isPolling,
    error,
    startPolling,
    stopPolling,
    refetch,
  };
}
