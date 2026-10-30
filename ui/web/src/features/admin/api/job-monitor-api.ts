import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance';

// Types

export interface JobListItem {
  jobId: string;
  name: string | null;
  queue: string | null;
  status: string;
  progress: number | null;
  attempt: number;
  retried: boolean;
  startedAt: string | null;
  finishedAt: string | null;
  createdAt: string;
  updatedAt: string;
  exceptionClass: string | null;
}

export interface JobDetail extends JobListItem {
  duration: number | null;
  /** Running or queued, or finished with queued work, such as lyrics fetches, still waiting. */
  cancellable: boolean;
}

export interface JobListResponse {
  items: JobListItem[];
  next_cursor: string | null;
  has_next_page: boolean;
  per_page: number;
}

export interface JobFilters {
  status?: string;
  name?: string;
  queue?: string;
  sort?: 'createdAt' | 'startedAt' | 'finishedAt' | 'duration';
  direction?: 'asc' | 'desc';
  limit?: number;
  cursor?: string;
}

export interface StatusOverview {
  counts: Record<string, number>;
  running: Array<{
    jobId: string
    name: string
    queue: string
    startedAt: string | null
    progress: number | null
  }>;
}

export interface AnalyticsSummary {
  statusCounts: Record<string, number>;
  jobTypeBreakdown: Array<{ name: string; count: number }>;
  successRate: number;
  throughputPerHour: number;
}

export interface AnalyticsTiming {
  executionTimes: Array<{ name: string; avg: number; median: number; p95: number }>;
  queueLatency: Array<{ name: string; avg: number }>;
}

export interface AnalyticsFailures {
  topFailingTypes: Array<{ name: string; count: number }>;
  topExceptionClasses: Array<{ class: string; count: number }>;
  retryFrequency: { retried: number; total: number; rate: number };
  recentFailures: Array<{
    jobId: string
    name: string
    exceptionClass: string | null
    exceptionMessage: string | null
    failedAt: string | null
  }>;
}

export interface TransportStatus {
  asyncQueueDepth: number;
  failedQueueDepth: number;
  consumerName: string;
  consumerRunning: boolean;
}

// API Functions

export async function getStatusOverview(signal?: AbortSignal): Promise<StatusOverview> {
  const {data} = await AXIOS_INSTANCE.get('/api/monitor/status', { signal });
  return data.data;
}

export async function getJobList(params: JobFilters = {}, signal?: AbortSignal): Promise<JobListResponse> {
  const {data} = await AXIOS_INSTANCE.get('/api/monitor/jobs', { signal, params});
  return data.data;
}

export async function getJobDetail(jobId: string, signal?: AbortSignal): Promise<JobDetail> {
  const {data} = await AXIOS_INSTANCE.get(`/api/monitor/jobs/${jobId}`, { signal });
  return data.data;
}

export async function retryJob(jobId: string): Promise<{ newJobId: string }> {
  const {data} = await AXIOS_INSTANCE.post(`/api/monitor/jobs/${jobId}/retry`);
  return data.data;
}

export async function cancelJob(jobId: string): Promise<{ cancelled: boolean }> {
  const {data} = await AXIOS_INSTANCE.post(`/api/monitor/jobs/${jobId}/cancel`);
  return data.data;
}

export async function getAnalyticsSummary(from: string, to: string, signal?: AbortSignal): Promise<AnalyticsSummary> {
  const {data} = await AXIOS_INSTANCE.get('/api/monitor/analytics/summary', { signal, params: {from, to}});
  return data.data;
}

export async function getAnalyticsTiming(from: string, to: string, signal?: AbortSignal): Promise<AnalyticsTiming> {
  const {data} = await AXIOS_INSTANCE.get('/api/monitor/analytics/timing', { signal, params: {from, to}});
  return data.data;
}

export async function getAnalyticsFailures(
  from: string,
  to: string,
  limit = 50,
  signal?: AbortSignal,
): Promise<AnalyticsFailures> {
  const {data} = await AXIOS_INSTANCE.get('/api/monitor/analytics/failures', {
      signal,
    params: {from, to, limit},
  });
  return data.data;
}

export async function getTransportStatus(signal?: AbortSignal): Promise<TransportStatus> {
  const {data} = await AXIOS_INSTANCE.get('/api/monitor/transport/status', { signal });
  return data.data;
}

export async function flushFailedQueue(): Promise<{ flushed: number }> {
  const {data} = await AXIOS_INSTANCE.post(
    '/api/monitor/transport/failed/flush',
    null,
    {params: {confirm: 'true'}},
  );
  return data.data;
}

export async function retryFailedMessage(id: string): Promise<{ retried: string }> {
  const {data} = await AXIOS_INSTANCE.post(`/api/monitor/transport/failed/${id}/retry`);
  return data.data;
}
