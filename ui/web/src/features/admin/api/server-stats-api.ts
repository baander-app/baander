import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

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
  uptime: number
}

export interface DoctrineStats {
  identity_map_size: number
  scheduled_inserts: number
  scheduled_updates: number
  scheduled_deletes: number
  is_open: boolean
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
  memory: MemoryStats
  process: ProcessStats
  swoole: Record<string, unknown> | null
  doctrine: DoctrineStats
  redis: RedisStats | null
  sse: SseStats
}

export async function getServerStats(): Promise<ServerStats> {
  const { data } = await AXIOS_INSTANCE.get('/api/debug/stats')
  return data.data
}
