import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
import {
  getJobList,
  getJobDetail,
  retryJob,
  cancelJob,
  getStatusOverview,
  getAnalyticsSummary,
  getAnalyticsTiming,
  getAnalyticsFailures,
  getTransportStatus,
  flushFailedQueue,
  retryFailedMessage,
} from '../api/job-monitor-api'
import type { JobFilters } from '../api/job-monitor-api'

export function useJobList(filters: JobFilters) {
  return useQuery({
    queryKey: ['job-list', filters],
    queryFn: ({ signal }) => getJobList(filters, signal),

    retry: false,
  })
}

export function useJobDetail(jobId: string | null) {
  return useQuery({
    queryKey: ['job-detail', jobId],
    queryFn: ({ signal }) => getJobDetail(jobId!, signal),
    enabled: !!jobId,
  })
}

export function useRetryJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof retryJob>[0]) => run(() => retryJob(variables)),
    onSuccess: () => {
      invalidate(['job-list'])
      invalidate(['status-overview'])
    },
  })
}

export function useCancelJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof cancelJob>[0]) => run(() => cancelJob(variables)),
    onSuccess: (_result, jobId) => {
      // A finished job becomes cancelled at once and is no longer cancellable.
      invalidate(['job-detail', jobId])
      invalidate(['job-list'])
      invalidate(['status-overview'])
    },
  })
}

export function useStatusOverview() {
  return useQuery({
    queryKey: ['status-overview'],
    queryFn: ({ signal }) => getStatusOverview(signal),
    retry: false,
  })
}

export function useAnalyticsSummary(from: string, to: string) {
  return useQuery({
    queryKey: ['analytics-summary', from, to],
    queryFn: ({ signal }) => getAnalyticsSummary(from, to, signal),

    retry: false,
  })
}

export function useAnalyticsTiming(from: string, to: string) {
  return useQuery({
    queryKey: ['analytics-timing', from, to],
    queryFn: ({ signal }) => getAnalyticsTiming(from, to, signal),

    retry: false,
  })
}

export function useAnalyticsFailures(from: string, to: string, limit = 50) {
  return useQuery({
    queryKey: ['analytics-failures', from, to, limit],
    queryFn: ({ signal }) => getAnalyticsFailures(from, to, limit, signal),

    retry: false,
  })
}

export function useTransportStatus() {
  return useQuery({
    queryKey: ['transport-status'],
    queryFn: ({ signal }) => getTransportStatus(signal),

    retry: false,
  })
}

export function useFlushFailedQueue() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: () => run(() => flushFailedQueue()),
    onSuccess: () => {
      invalidate(['transport-status'])
    },
  })
}

export function useRetryFailedMessage() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof retryFailedMessage>[0]) => run(() => retryFailedMessage(variables)),
    onSuccess: () => {
      invalidate(['transport-status'])
      invalidate(['job-list'])
    },
  })
}
