import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

// --- Coroutine Stats ---

export interface CoroutineStats {
  num: number
  peak: number
  active: number
  channels?: Record<string, unknown>
}

export async function getCoroutineStats(): Promise<CoroutineStats> {
  const { data } = await AXIOS_INSTANCE.get('/api/debug/coroutines')
  return data.data ?? data
}

// --- Worker Stats ---

export interface WorkerStats {
  http_workers?: {
    total: number
    active: number
    idle: number
    max_request?: number
    dispatch_count?: number
  }
  task_workers?: {
    total: number
    active: number
    idle: number
    dispatch_count?: number
  }
  cpu_pool?: {
    workers: number
    busy: number
    idle: number
  }
  [key: string]: unknown
}

export async function getWorkerStats(): Promise<WorkerStats> {
  const { data } = await AXIOS_INSTANCE.get('/api/debug/workers')
  return data.data ?? data
}

// --- Span Stats ---

export interface Span {
  name: string
  kind: number
  start: number
  end: number
  duration_ms: number
  attributes?: Record<string, unknown>
  status?: string
  parent_span_id?: string
}

export async function getSpans(limit = 50): Promise<Span[]> {
  const { data } = await AXIOS_INSTANCE.get('/api/debug/spans', { params: { limit } })
  return data.data ?? data
}

export async function clearSpans(): Promise<void> {
  await AXIOS_INSTANCE.delete('/api/debug/spans')
}
