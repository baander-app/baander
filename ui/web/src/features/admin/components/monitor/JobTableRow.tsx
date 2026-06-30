import styled, { css } from 'styled-components'
import type { JobListItem } from '../../api/job-monitor-api'
import { interactiveTransition } from '@/shared/theme'

interface JobTableRowProps {
  job: JobListItem
  onClick: () => void
}

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  queued: { bg: '#dbeafe', text: '#1d4ed8' },
  running: { bg: '#fef9c3', text: '#a16207' },
  finished: { bg: '#dcfce7', text: '#15803d' },
  failed: { bg: '#fee2e2', text: '#b91c1c' },
  cancelled: { bg: '#f3f4f6', text: '#374151' },
}

function formatDuration(startedAt: string | null, finishedAt: string | null): string {
  if (!startedAt) return '-'
  const start = new Date(startedAt).getTime()
  const end = finishedAt ? new Date(finishedAt).getTime() : Date.now()
  const seconds = Math.floor((end - start) / 1000)

  if (seconds < 60) return `${seconds}s`

  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60

  if (minutes < 60) return `${minutes}m ${remainingSeconds}s`

  const hours = Math.floor(minutes / 60)
  const remainingMinutes = minutes % 60
  return `${hours}h ${remainingMinutes}m`
}

function formatRelativeTime(dateString: string): string {
  const date = new Date(dateString).getTime()
  const now = Date.now()
  const seconds = Math.floor((now - date) / 1000)

  if (seconds < 60) return 'just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  return `${Math.floor(seconds / 86400)}d ago`
}

const Row = styled.div`
  display: grid;
  grid-template-columns: 1fr 100px 100px 60px 120px 100px;
  align-items: center;
  gap: 0.5rem;
  border-radius: 0.375rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  cursor: pointer;
  ${interactiveTransition(['background-color'])}

  &:hover { background-color: color-mix(in srgb, var(--color-accent) 50%, transparent); }
`

const Name = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
`

const StatusBadge = styled.span<{ $bg: string; $text: string }>`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50px;
  padding: 0.125rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 500;
  background-color: ${({ $bg }) => $bg};
  color: ${({ $text }) => $text};
`

const MutedText = styled.span`
  color: var(--color-muted-foreground);
`

const MutedTruncate = styled(MutedText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const CenterText = styled(MutedText)`
  text-align: center;
`

const ProgressRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const ProgressTrack = styled.div`
  height: 0.375rem;
  flex: 1;
  border-radius: 50px;
  background-color: var(--color-muted);
`

const ProgressFill = styled.div`
  height: 0.375rem;
  border-radius: 50px;
  background-color: var(--color-primary);
  transition: all 0.15s;
`

const ProgressLabel = styled.span`
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TabularText = styled(MutedText)`
  font-variant-numeric: tabular-nums;
`

const SmallMuted = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

export function JobTableRow({ job, onClick }: JobTableRowProps) {
  const statusColor = STATUS_COLORS[job.status] ?? STATUS_COLORS.queued

  return (
    <Row onClick={onClick}>
      <Name title={job.name ?? undefined}>{job.name ?? job.jobId.slice(0, 8)}</Name>

      <StatusBadge $bg={statusColor.bg} $text={statusColor.text}>
        {job.status}
      </StatusBadge>

      <MutedTruncate title={job.queue ?? undefined}>{job.queue ?? '-'}</MutedTruncate>

      <CenterText>{job.attempt}</CenterText>

      {job.status === 'running' && job.progress !== null ? (
        <ProgressRow>
          <ProgressTrack>
            <ProgressFill style={{ width: `${Math.min(100, Math.max(0, job.progress))}%` }} />
          </ProgressTrack>
          <ProgressLabel>{Math.round(job.progress)}%</ProgressLabel>
        </ProgressRow>
      ) : (
        <MutedText>-</MutedText>
      )}

      <TabularText>{formatDuration(job.startedAt, job.finishedAt)}</TabularText>

      <SmallMuted title={new Date(job.createdAt).toLocaleString()}>
        {formatRelativeTime(job.createdAt)}
      </SmallMuted>
    </Row>
  )
}
