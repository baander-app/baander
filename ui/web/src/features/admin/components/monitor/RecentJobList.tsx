import styled from 'styled-components'
import { RotateCcw } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { getStatusIcon } from './ActiveJobCard'
import { interactiveTransition } from '@/shared/theme'

interface RecentJob {
  public_id: string
  status: string
  is_full: boolean
  completed_songs: number
  total_songs: number
  fail_reason?: string | null
  created_at: string
}

const List = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const Row = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: 0.375rem;
  border: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
  padding: 0.5rem 0.75rem;
  font-size: 13px;
  ${interactiveTransition(['background-color'])}

  &:hover { background-color: color-mix(in srgb, var(--color-accent) 20%, transparent); }
`

const LeftGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const IconHolder = styled.span`
  color: var(--color-muted-foreground);
`

const JobType = styled.span`
  font-weight: 500;
`

const SongCount = styled.span`
  color: var(--color-muted-foreground);
`

const FailReason = styled.span`
  color: var(--color-destructive);
  font-size: 11px;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const RightGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const TimeStamp = styled.span`
  color: var(--color-muted-foreground);
  font-size: 12px;
`

export function RecentJobList({
  jobs,
  onRequeue,
  isRequeuing,
}: {
  jobs: RecentJob[]
  onRequeue: (publicId: string) => void
  isRequeuing: boolean
}) {
  return (
    <List>
      {jobs.map((job) => (
        <Row key={job.public_id}>
          <LeftGroup>
            <IconHolder>{getStatusIcon(job.status)}</IconHolder>
            <JobType>{job.is_full ? 'Full' : 'Incremental'}</JobType>
            <SongCount>
              {job.completed_songs.toLocaleString()} / {job.total_songs.toLocaleString()} songs
            </SongCount>
            {(job.status === 'failed' || job.status === 'cancelled') && job.fail_reason && (
              <FailReason title={job.fail_reason}>{job.fail_reason}</FailReason>
            )}
          </LeftGroup>
          <RightGroup>
            <TimeStamp>{new Date(job.created_at).toLocaleTimeString()}</TimeStamp>
            {(job.status === 'failed' || job.status === 'cancelled') && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => onRequeue(job.public_id)}
                disabled={isRequeuing}
                style={{ height: '1.75rem', fontSize: '11px', padding: '0 0.5rem' }}
              >
                <RotateCcw size={11} style={{ marginRight: '0.25rem' }} />
                Retry
              </Button>
            )}
          </RightGroup>
        </Row>
      ))}
    </List>
  )
}
