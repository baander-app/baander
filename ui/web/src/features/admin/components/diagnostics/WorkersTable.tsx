import { Table, TableBody, TableHeader, TableRow } from '@/shared/components/ui/table'
import { formatUptime } from '@/shared/utils/format-human'
import type { WorkerSnapshot } from '../../api/server-stats-api'
import { DiagnosticsCard } from './DiagnosticsCard'
import { HeadCell, MutedLine, NumberCell } from './diagnostics-styles'
import { formatPair } from './format'

interface WorkersTableProps {
  workers: WorkerSnapshot[]
}

export function WorkersTable({ workers }: WorkersTableProps) {
  return (
    <DiagnosticsCard title="HTTP workers">
      {workers.length === 0 ? (
        <MutedLine>No worker answered.</MutedLine>
      ) : (
        <Table aria-label="HTTP workers">
          <TableHeader>
            <TableRow>
              <HeadCell>Worker</HeadCell>
              <HeadCell>PID</HeadCell>
              <HeadCell>Uptime</HeadCell>
              <HeadCell>Memory / peak</HeadCell>
              <HeadCell>Real / peak</HeadCell>
              <HeadCell>Coroutines / peak</HeadCell>
            </TableRow>
          </TableHeader>
          <TableBody>
            {workers.map((worker) => (
              <TableRow key={worker.worker_id}>
                <NumberCell>{worker.worker_id}</NumberCell>
                <NumberCell>{worker.process.pid}</NumberCell>
                <NumberCell>{formatUptime(worker.process.uptime)}</NumberCell>
                <NumberCell>{formatPair(worker.memory.usage, worker.memory.peak, 'MB')}</NumberCell>
                <NumberCell>{formatPair(worker.memory.real, worker.memory.real_peak, 'MB')}</NumberCell>
                <NumberCell>
                  {formatPair(worker.coroutines.coroutine_num, worker.coroutines.coroutine_peak_num)}
                </NumberCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </DiagnosticsCard>
  )
}
