import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

/** Memory figures in MB. */
export interface MemoryStats {
  usage: number
  peak: number
  real: number
  real_peak: number
}

export interface ProcessStats {
  pid: number
  uid: number
  gid: number
  user: string
  /** Seconds since the worker started. */
  uptime: number
}

export interface WorkerCoroutineCounts {
  coroutine_num: number | null
  coroutine_peak_num: number | null
}

export interface ConnectionPoolStats {
  active: number
  free: number
  limit: number
}

/** One HTTP worker's answer to the diagnostics fan-out. */
export interface WorkerSnapshot {
  worker_id: number
  memory: MemoryStats
  process: ProcessStats
  swoole: Record<string, unknown> | null
  coroutines: WorkerCoroutineCounts
  pools: ConnectionPoolStats[]
}

export interface WorkerError {
  worker_id: number
  error: string
}

export interface RedisStats {
  connected: boolean
  ping?: boolean
  db_size?: number
  connected_clients?: number
  used_memory?: number
  maxmemory?: number
  error?: string
}

export interface SseStats {
  active_connections: number
}

export interface ServerStats {
  /** One snapshot per HTTP worker that answered, sorted by worker ID. */
  workers: WorkerSnapshot[]
  /** Workers that did not answer in time. */
  missing_workers: number[]
  worker_errors: WorkerError[]
  redis: RedisStats
  sse: SseStats
}

export async function getServerStats(): Promise<ServerStats> {
  const { data } = await AXIOS_INSTANCE.get<{ data: ServerStats }>('/api/debug/stats')
  return data.data
}
