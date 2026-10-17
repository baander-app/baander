import { KVRow } from '@/shared/components/kv-row'
import { useWorkerStats } from '../../hooks/use-debug-stats'
import { DiagnosticsCard } from './DiagnosticsCard'
import { SectionError, SectionSkeleton } from './SectionStates'
import { formatCount } from './format'

export function WorkerPoolsSection() {
  const { data, isLoading, error, refetch } = useWorkerStats()

  if (isLoading) {
    return (
      <DiagnosticsCard title="Worker pools">
        <SectionSkeleton rows={3} />
      </DiagnosticsCard>
    )
  }

  if (error || !data) {
    return (
      <DiagnosticsCard title="Worker pools">
        <SectionError message="Failed to load worker pool statistics." onRetry={() => refetch()} />
      </DiagnosticsCard>
    )
  }

  const { http_workers: http } = data

  return (
    <DiagnosticsCard title="Worker pools">
      <KVRow label="HTTP total" value={formatCount(http.total)} />
      <KVRow label="HTTP active" value={formatCount(http.active)} />
      <KVRow label="HTTP idle" value={formatCount(http.idle)} />
    </DiagnosticsCard>
  )
}
