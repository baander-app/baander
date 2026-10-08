import { KVRow } from '@/shared/components/kv-row'
import type { RedisStats } from '../../api/server-stats-api'
import { DiagnosticsCard } from './DiagnosticsCard'
import { formatCount } from './format'

interface RedisCardProps {
  redis: RedisStats
}

function formatMegabytes(value: number | undefined): string {
  return value == null ? '—' : `${value} MB`
}

export function RedisCard({ redis }: RedisCardProps) {
  if (!redis.connected) {
    return (
      <DiagnosticsCard title="Redis">
        <KVRow label="Status" value="Disconnected" muted />
        {redis.error && <KVRow label="Error" value={redis.error} />}
      </DiagnosticsCard>
    )
  }

  return (
    <DiagnosticsCard title="Redis">
      <KVRow label="Ping" value={redis.ping ? 'PONG' : 'Failed'} muted={!redis.ping} />
      <KVRow label="DB size" value={formatCount(redis.db_size)} />
      <KVRow label="Connected clients" value={formatCount(redis.connected_clients)} />
      <KVRow label="Used memory" value={formatMegabytes(redis.used_memory)} />
      <KVRow label="Max memory" value={formatMegabytes(redis.maxmemory)} />
    </DiagnosticsCard>
  )
}
