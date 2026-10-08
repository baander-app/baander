import { KVRow } from '@/shared/components/kv-row'
import { useWorkerStats } from '../../hooks/use-debug-stats'
import type { TranscodePoolStats } from '../../api/debug-api'
import { DiagnosticsCard } from './DiagnosticsCard'
import { SectionError, SectionSkeleton } from './SectionStates'
import { formatCount } from './format'

function transcodePoolStatus(pool: TranscodePoolStats): string {
  if (!pool.available) {
    return 'Unavailable'
  }

  if (pool.boot_pending) {
    return 'Starting'
  }

  return pool.running ? 'Running' : 'Stopped'
}

export function WorkerPoolsSection() {
  const { data, isLoading, error, refetch } = useWorkerStats()

  if (isLoading) {
    return (
      <DiagnosticsCard title="Worker pools">
        <SectionSkeleton rows={6} />
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

  const { http_workers: http, task_workers: task, transcode_pool: transcode } = data
  const transcodeStatus = transcodePoolStatus(transcode)

  return (
    <DiagnosticsCard title="Worker pools">
      <KVRow label="HTTP total" value={formatCount(http.total)} />
      <KVRow label="HTTP active" value={formatCount(http.active)} />
      <KVRow label="HTTP idle" value={formatCount(http.idle)} />
      <KVRow label="Task total" value={formatCount(task.total)} />
      <KVRow label="Task active" value={formatCount(task.active)} />
      <KVRow label="Task idle" value={formatCount(task.idle)} />
      <KVRow label="Transcode pool" value={transcodeStatus} muted={transcodeStatus !== 'Running'} />
      {transcode.available && (
        <KVRow label="Transcode pool workers" value={formatCount(transcode.worker_count)} />
      )}
    </DiagnosticsCard>
  )
}
