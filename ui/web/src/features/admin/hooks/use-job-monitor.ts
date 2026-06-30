import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
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
    queryFn: () => getJobList(filters),
    refetchInterval: (query) => (query.state.data ? 15_000 : false),
    retry: false,
  })
}

export function useJobDetail(jobId: string | null) {
  return useQuery({
    queryKey: ['job-detail', jobId],
    queryFn: () => getJobDetail(jobId!),
    enabled: !!jobId,
  })
}

export function useRetryJob() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: retryJob,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['job-list'] })
      queryClient.invalidateQueries({ queryKey: ['status-overview'] })
    },
  })
}

export function useCancelJob() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: cancelJob,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['job-list'] })
      queryClient.invalidateQueries({ queryKey: ['status-overview'] })
    },
  })
}

export function useStatusOverview() {
  return useQuery({
    queryKey: ['status-overview'],
    queryFn: getStatusOverview,
    refetchInterval: (query) => (query.state.data ? 15_000 : false),
    retry: false,
  })
}

export function useAnalyticsSummary(from: string, to: string) {
  return useQuery({
    queryKey: ['analytics-summary', from, to],
    queryFn: () => getAnalyticsSummary(from, to),
    refetchInterval: (query) => (query.state.data ? 30_000 : false),
    retry: false,
  })
}

export function useAnalyticsTiming(from: string, to: string) {
  return useQuery({
    queryKey: ['analytics-timing', from, to],
    queryFn: () => getAnalyticsTiming(from, to),
    refetchInterval: (query) => (query.state.data ? 30_000 : false),
    retry: false,
  })
}

export function useAnalyticsFailures(from: string, to: string, limit = 50) {
  return useQuery({
    queryKey: ['analytics-failures', from, to, limit],
    queryFn: () => getAnalyticsFailures(from, to, limit),
    refetchInterval: (query) => (query.state.data ? 30_000 : false),
    retry: false,
  })
}

export function useTransportStatus() {
  return useQuery({
    queryKey: ['transport-status'],
    queryFn: getTransportStatus,
    refetchInterval: (query) => (query.state.data ? 15_000 : false),
    retry: false,
  })
}

export function useFlushFailedQueue() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: flushFailedQueue,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['transport-status'] })
    },
  })
}

export function useRetryFailedMessage() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: retryFailedMessage,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['transport-status'] })
      queryClient.invalidateQueries({ queryKey: ['job-list'] })
    },
  })
}
