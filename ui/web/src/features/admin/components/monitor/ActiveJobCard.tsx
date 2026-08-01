import styled, { keyframes } from 'styled-components'
import { XCircle, Clock, CheckCircle, AlertCircle, Loader2 } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { ProgressBar } from '@/shared/components/progress-bar'

const spin = keyframes`
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
`

const SpinningLoader = styled(Loader2)`
  animation: ${spin} 1s linear infinite;
`

const STATUS_ICONS = {
  pending: Clock,
  in_progress: Loader2,
  completed: CheckCircle,
  failed: AlertCircle,
  cancelled: XCircle,
} as const

export function getStatusIcon(status: string) {
  const Icon = STATUS_ICONS[status as keyof typeof STATUS_ICONS] || Clock
  const isSpinning = status === 'in_progress'
  if (isSpinning) return <SpinningLoader size={14} />
  return <Icon size={14} />
}

interface ActiveJob {
  public_id: string
  status: string
  is_full: boolean
  completed_songs: number
  total_songs: number
  progress_percentage: number
  current_strategy?: string | null
}

const Section = styled.section`
  border-radius: var(--radius-lg, 0.5rem);
  border: 1px solid var(--color-border);
  background-color: color-mix(in srgb, var(--color-muted) 30%, transparent);
  padding: 1rem;
`

const Header = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
`

const HeaderTitle = styled.h2`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const StatusLabel = styled.span`
  font-size: 12px;
  color: var(--color-muted-foreground);
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const Body = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const InfoRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
`

const InfoLabel = styled.span`
  color: var(--color-muted-foreground);
`

const InfoValue = styled.span`
  font-weight: 500;
`

const CancelButtonWrapper = styled.div`
  display: flex;
  justify-content: flex-end;
`

export function ActiveJobCard({
  job,
  onCancel,
  isCancelling,
}: {
  job: ActiveJob
  onCancel: (publicId: string) => void
  isCancelling: boolean
}) {
  return (
    <Section>
      <Header>
        <HeaderTitle>Active Job</HeaderTitle>
        <StatusLabel>
          {getStatusIcon(job.status)}
          {job.status.replace('_', ' ')}
        </StatusLabel>
      </Header>
      <Body>
        <InfoRow>
          <InfoLabel>Mode:</InfoLabel>
          <InfoValue>{job.is_full ? 'Full' : 'Incremental'}</InfoValue>
        </InfoRow>
        <InfoRow>
          <InfoLabel>Progress:</InfoLabel>
          <InfoValue style={{ fontVariantNumeric: 'tabular-nums' }}>
            {job.completed_songs.toLocaleString()} / {job.total_songs.toLocaleString()} songs
          </InfoValue>
        </InfoRow>
        {job.current_strategy && (
          <InfoRow>
            <InfoLabel>Current strategy:</InfoLabel>
            <InfoValue style={{ textTransform: 'capitalize' }}>{job.current_strategy}</InfoValue>
          </InfoRow>
        )}
        <ProgressBar value={job.progress_percentage} size="md" style={{ marginTop: '0.5rem' }} />
        <CancelButtonWrapper>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => onCancel(job.public_id)}
            disabled={isCancelling}
            style={{ height: '1.75rem', fontSize: '12px' }}
          >
            <XCircle size={12} style={{ marginRight: '0.25rem' }} />
            Cancel
          </Button>
        </CancelButtonWrapper>
      </Body>
    </Section>
  )
}
