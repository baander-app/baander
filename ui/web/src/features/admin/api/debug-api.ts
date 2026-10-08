import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { WorkerError } from './server-stats-api'

// --- Coroutine Stats (per worker) ---

/** Swoole\Coroutine::stats() of one worker; a figure Swoole did not report is null. */
export interface CoroutineCounters {
  event_num: number | null
  signal_listener_num: number | null
  aio_task_num: number | null
  aio_worker_num: number | null
  aio_queue_size: number | null
  c_stack_size: number | null
  coroutine_num: number | null
  coroutine_peak_num: number | null
  coroutine_last_cid: number | null
}

export interface ChannelStats {
  name: string
  consumer_num: number
  producer_num: number
  queue_num: number
  capacity: number
  closed: boolean
}

export interface WorkerCoroutines {
  worker_id: number
  coroutines: CoroutineCounters
  active_cids: number[]
  channels: ChannelStats[]
}

export interface CoroutineStats {
  workers: WorkerCoroutines[]
  missing_workers: number[]
  worker_errors: WorkerError[]
}

export async function getCoroutineStats(): Promise<CoroutineStats> {
  const { data } = await AXIOS_INSTANCE.get<CoroutineStats>('/api/debug/coroutines')
  return data
}

// --- Worker Stats (server-wide) ---

export interface HttpWorkerStats {
  total: number | null
  idle: number | null
  active: number | null
  request_count: number | null
  dispatch_count: number | null
  concurrency: number | null
  connection_num: number | null
  max_connection: number | null
  coroutine_num: number | null
  coroutine_peak: number | null
  start_time: number | null
  total_recv_bytes: number | null
  total_send_bytes: number | null
}

export interface TaskWorkerStats {
  total: number | null
  idle: number | null
  active: number | null
  tasking_num: number | null
  task_count: number | null
}

export interface UserWorkerStats {
  total: number | null
}

export interface TranscodePoolStats {
  available: boolean
  running?: boolean
  worker_count?: number
  result_table_size?: number
  boot_pending?: boolean
}

export interface WorkerStats {
  http_workers: HttpWorkerStats
  task_workers: TaskWorkerStats
  user_workers: UserWorkerStats
  transcode_pool: TranscodePoolStats
}

export async function getWorkerStats(): Promise<WorkerStats> {
  const { data } = await AXIOS_INSTANCE.get<WorkerStats>('/api/debug/workers')
  return data
}

// --- Spans ---

export interface Span {
  trace_id: string
  span_id: string
  parent_span_id: string | null
  operation_name: string
  service: string
  kind: string
  start_time_us: number
  duration_us: number
  attributes: Record<string, unknown>
  status_code: string
  status_message: string
  file_path: string | null
  line_number: number | null
}

/** Recorded spans, newest first. */
export async function getSpans(limit = 50): Promise<Span[]> {
  const { data } = await AXIOS_INSTANCE.get<Span[]>('/api/debug/spans', { params: { limit } })
  return data
}

export async function clearSpans(): Promise<void> {
  await AXIOS_INSTANCE.delete('/api/debug/spans')
}
