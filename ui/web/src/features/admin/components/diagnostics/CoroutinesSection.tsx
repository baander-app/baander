import styled from 'styled-components'
import { Table, TableBody, TableHeader, TableRow } from '@/shared/components/ui/table'
import { useCoroutineStats } from '../../hooks/use-debug-stats'
import { DiagnosticsCard } from './DiagnosticsCard'
import { HeadCell, MutedLine, NumberCell } from './diagnostics-styles'
import { SectionError, SectionSkeleton } from './SectionStates'
import { WorkerFailureNotice } from './WorkerFailureNotice'
import { formatCount } from './format'

const Stack = styled.div`
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
`

export function CoroutinesSection() {
  const { data, isLoading, error, refetch } = useCoroutineStats()

  if (isLoading) {
    return (
      <DiagnosticsCard title="Coroutines">
        <SectionSkeleton />
      </DiagnosticsCard>
    )
  }

  if (error || !data) {
    return (
      <DiagnosticsCard title="Coroutines">
        <SectionError message="Failed to load coroutine statistics." onRetry={() => refetch()} />
      </DiagnosticsCard>
    )
  }

  return (
    <Stack>
      <WorkerFailureNotice
        title="Coroutine statistics incomplete"
        missingWorkers={data.missing_workers}
        workerErrors={data.worker_errors}
      />
      <DiagnosticsCard title="Coroutines">
        {data.workers.length === 0 ? (
          <MutedLine>No worker answered.</MutedLine>
        ) : (
          <Table aria-label="Coroutines per worker">
            <TableHeader>
              <TableRow>
                <HeadCell>Worker</HeadCell>
                <HeadCell>Coroutines</HeadCell>
                <HeadCell>Peak</HeadCell>
                <HeadCell>Active CIDs</HeadCell>
                <HeadCell>Channels</HeadCell>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.workers.map((worker) => (
                <TableRow key={worker.worker_id}>
                  <NumberCell>{worker.worker_id}</NumberCell>
                  <NumberCell>{formatCount(worker.coroutines.coroutine_num)}</NumberCell>
                  <NumberCell>{formatCount(worker.coroutines.coroutine_peak_num)}</NumberCell>
                  <NumberCell>{worker.active_cids.length}</NumberCell>
                  <NumberCell>{worker.channels.length}</NumberCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </DiagnosticsCard>
    </Stack>
  )
}
