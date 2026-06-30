import { useState } from 'react'
import styled, { keyframes } from 'styled-components'
import { toast } from 'sonner'
import { X } from 'lucide-react'
import { useJobDetail, useRetryJob, useCancelJob } from '../../hooks/use-job-monitor'

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

interface JobDetailPanelProps {
  jobId: string | null
  onClose: () => void
}

function formatTimestamp(iso: string | null): string {
  if (!iso) return '--'
  const date = new Date(iso)
  return date.toLocaleString()
}

function formatDuration(ms: number | null): string {
  if (ms === null) return '--'
  if (ms < 1000) return `${ms}ms`
  const seconds = ms / 1000
  if (seconds < 60) return `${seconds.toFixed(1)}s`
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = (seconds % 60).toFixed(0)
  return `${minutes}m ${remainingSeconds}s`
}

// --- Styled Components ---

const Backdrop = styled.div`
  position: fixed;
  inset: 0;
  z-index: 40;
  background-color: rgba(0, 0, 0, 0.5);
`

const Panel = styled.div`
  position: fixed;
  right: 0;
  top: 0;
  z-index: 50;
  height: 100%;
  width: 100%;
  max-width: 32rem;
  overflow-y: auto;
  border-left: 1px solid var(--color-border);
  background-color: var(--color-card);
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
`

const PanelContent = styled.div`
  padding: 1.5rem;
`

const Header = styled.div`
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
`

const TitleGroup = styled.div`
  min-width: 0;
  flex: 1;
`

const Title = styled.h2`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--color-card-foreground);
`

const BadgeWrapper = styled.div`
  margin-top: 0.25rem;
`

const colorMap: Record<string, { bg: string; text: string }> = {
  pending: { bg: 'rgba(234, 179, 8, 0.2)', text: '#facc15' },
  queued: { bg: 'rgba(59, 130, 246, 0.2)', text: '#60a5fa' },
  running: { bg: 'rgba(139, 92, 246, 0.2)', text: '#a78bfa' },
  finished: { bg: 'rgba(34, 197, 94, 0.2)', text: '#4ade80' },
  failed: { bg: 'rgba(239, 68, 68, 0.2)', text: '#f87171' },
  cancelled: { bg: 'rgba(156, 163, 175, 0.2)', text: '#9ca3af' },
}

const Badge = styled.span<{ $bg: string; $text: string }>`
  display: inline-flex;
  align-items: center;
  border-radius: 50px;
  padding: 0.125rem 0.625rem;
  font-size: 0.75rem;
  font-weight: 500;
  background-color: ${({ $bg }) => $bg};
  color: ${({ $text }) => $text};
`

function StatusBadge({ status }: { status: string }) {
  const colors = colorMap[status] ?? { bg: 'var(--color-muted)', text: 'var(--color-muted-foreground)' }
  return (
    <Badge $bg={colors.bg} $text={colors.text}>{status}</Badge>
  )
}

const CloseButton = styled.button`
  margin-left: 1rem;
  display: flex;
  height: 2rem;
  width: 2rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.375rem;
  color: var(--color-muted-foreground);
  transition: background-color 0.15s, color 0.15s;

  &:hover {
    background-color: var(--color-muted);
    color: var(--color-foreground);
  }
`

const MetadataGrid = styled.div`
  margin-top: 1.5rem;
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  column-gap: 1rem;
  row-gap: 1rem;
`

const MetadataItem = styled.div`
  ${({ style }) => style?.gridColumn ? `grid-column: ${style.gridColumn};` : ''}
`

const MetadataLabel = styled.p`
  font-size: 0.75rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const MetadataValue = styled.div`
  margin-top: 0.25rem;
`

const CodeBlock = styled.code`
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  padding: 0.25rem 0.5rem;
  font-family: monospace;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const TextValue = styled.span`
  font-size: 0.875rem;
  color: var(--color-foreground);
`

const ExceptionBox = styled.div`
  margin-top: 1.5rem;
  border-radius: 0.375rem;
  border: 1px solid var(--color-destructive);
  background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
  padding: 0.75rem;
`

const ExceptionTitle = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-destructive);
`

const ExceptionMessage = styled.p`
  margin-top: 0.25rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const ExceptionLocation = styled.p`
  margin-top: 0.25rem;
  font-family: monospace;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const DetailsBlock = styled.details`
  margin-top: 1.5rem;
`

const DetailsSummary = styled.summary`
  cursor: pointer;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-card-foreground);
`

const TruncatedWarning = styled.p`
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: #eab308;
`

const PreBlock = styled.pre`
  margin-top: 0.5rem;
  max-height: 16rem;
  overflow: auto;
  border-radius: 0.375rem;
  background-color: var(--color-muted);
  padding: 0.75rem;
  font-family: monospace;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const RetryButton = styled.button`
  margin-top: 1.5rem;
  width: 100%;
  border-radius: 0.375rem;
  background-color: #7c3aed;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: white;
  transition: background-color 0.15s;

  &:hover { background-color: #6d28d9; }
  &:disabled { opacity: 0.5; }
`

const CancelJobButton = styled.button`
  margin-top: 1.5rem;
  width: 100%;
  border-radius: 0.375rem;
  background-color: var(--color-destructive);
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-destructive-foreground);
  transition: opacity 0.15s;

  &:hover { opacity: 0.9; }
  &:disabled { opacity: 0.5; }
`

const ConfirmBackdrop = styled.div`
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: rgba(0, 0, 0, 0.5);
`

const ConfirmDialog = styled.div`
  margin: 0 1rem;
  width: 100%;
  max-width: 24rem;
  border-radius: var(--radius-lg, 0.5rem);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1.5rem;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
`

const ConfirmText = styled.p`
  font-size: 0.875rem;
  color: var(--color-card-foreground);
`

const ConfirmActions = styled.div`
  margin-top: 1rem;
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
`

const CancelBtn = styled.button`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  color: var(--color-foreground);
  transition: background-color 0.15s;

  &:hover { background-color: var(--color-muted); }
`

const DestructiveBtn = styled.button`
  border-radius: 0.375rem;
  background-color: var(--color-destructive);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-destructive-foreground);
  transition: opacity 0.15s;

  &:hover { opacity: 0.9; }
  &:disabled { opacity: 0.5; }
`

const SkeletonContainer = styled.div`
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const SkeletonLine = styled.div`
  border-radius: 0.25rem;
  background-color: var(--color-muted);
`

const ErrorBox = styled.div`
  border-radius: 0.375rem;
  border: 1px solid var(--color-destructive);
  background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
  padding: 1rem;
`

const ErrorTitle = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-destructive);
`

const ErrorDetail = styled.p`
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ErrorCloseBtn = styled.button`
  margin-top: 0.75rem;
  border-radius: 0.375rem;
  background-color: var(--color-secondary);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  color: var(--color-secondary-foreground);
`

const NotFoundText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

function SkeletonRow() {
  return (
    <SkeletonContainer>
      <SkeletonLine style={{ height: '1rem', width: '33%' }} />
      <SkeletonLine style={{ height: '2rem', width: '100%' }} />
      <SkeletonLine style={{ height: '1rem', width: '66%' }} />
      <SkeletonLine style={{ height: '1rem', width: '50%' }} />
    </SkeletonContainer>
  )
}

export function JobDetailPanel({ jobId, onClose }: JobDetailPanelProps) {
  const { data: job, isLoading, isError, error } = useJobDetail(jobId)
  const retryMutation = useRetryJob()
  const cancelMutation = useCancelJob()
  const [confirmAction, setConfirmAction] = useState<'retry' | 'cancel' | null>(null)

  if (!jobId) {
    return null
  }

  function handleRetry() {
    if (!jobId) return
    retryMutation.mutate(jobId, {
      onSuccess: (result) => {
        toast.success(`Job retried. New job ID: ${result.newJobId}`)
        setConfirmAction(null)
        onClose()
      },
      onError: () => {
        toast.error('Failed to retry job.')
        setConfirmAction(null)
      },
    })
  }

  function handleCancel() {
    if (!jobId) return
    cancelMutation.mutate(jobId, {
      onSuccess: () => {
        toast.success('Job cancelled.')
        setConfirmAction(null)
        onClose()
      },
      onError: () => {
        toast.error('Failed to cancel job.')
        setConfirmAction(null)
      },
    })
  }

  function handleConfirm() {
    if (confirmAction === 'retry') {
      handleRetry()
    } else if (confirmAction === 'cancel') {
      handleCancel()
    }
  }

  function parseJobData(data: string | null): string {
    if (!data) return '{}'
    try {
      return JSON.stringify(JSON.parse(data), null, 2)
    } catch {
      return data
    }
  }

  return (
    <>
      <Backdrop onClick={onClose} aria-hidden="true" />
      <Panel role="dialog" aria-label="Job details">
        {isLoading && (
          <PanelContent>
            <SkeletonRow />
          </PanelContent>
        )}

        {isError && (
          <PanelContent>
            <ErrorBox>
              <ErrorTitle>Failed to load job details</ErrorTitle>
              <ErrorDetail>{error instanceof Error ? error.message : 'An unknown error occurred.'}</ErrorDetail>
              <ErrorCloseBtn onClick={onClose}>Close</ErrorCloseBtn>
            </ErrorBox>
          </PanelContent>
        )}

        {!isLoading && !isError && !job && (
          <PanelContent>
            <NotFoundText>Job not found.</NotFoundText>
            <ErrorCloseBtn onClick={onClose}>Close</ErrorCloseBtn>
          </PanelContent>
        )}

        {job && (
          <PanelContent>
            <Header>
              <TitleGroup>
                <Title>{job.name ?? 'Unnamed Job'}</Title>
                <BadgeWrapper><StatusBadge status={job.status} /></BadgeWrapper>
              </TitleGroup>
              <CloseButton onClick={onClose} aria-label="Close panel">
                <X style={{ height: '1.25rem', width: '1.25rem' }} />
              </CloseButton>
            </Header>

            <MetadataGrid>
              <MetadataItem>
                <MetadataLabel>Status</MetadataLabel>
                <MetadataValue><StatusBadge status={job.status} /></MetadataValue>
              </MetadataItem>

              <MetadataItem>
                <MetadataLabel>Queue</MetadataLabel>
                <MetadataValue><TextValue>{job.queue ?? '--'}</TextValue></MetadataValue>
              </MetadataItem>

              <MetadataItem style={{ gridColumn: 'span 2' }}>
                <MetadataLabel>Job ID</MetadataLabel>
                <MetadataValue><CodeBlock>{job.jobId}</CodeBlock></MetadataValue>
              </MetadataItem>

              <MetadataItem>
                <MetadataLabel>Attempt</MetadataLabel>
                <MetadataValue><TextValue>{job.attempt}</TextValue></MetadataValue>
              </MetadataItem>

              <MetadataItem>
                <MetadataLabel>Data Truncated</MetadataLabel>
                <MetadataValue><TextValue>{job.dataTruncated ? 'Yes' : 'No'}</TextValue></MetadataValue>
              </MetadataItem>

              <MetadataItem>
                <MetadataLabel>Created At</MetadataLabel>
                <MetadataValue><TextValue>{formatTimestamp(job.createdAt)}</TextValue></MetadataValue>
              </MetadataItem>

              <MetadataItem>
                <MetadataLabel>Started At</MetadataLabel>
                <MetadataValue><TextValue>{formatTimestamp(job.startedAt)}</TextValue></MetadataValue>
              </MetadataItem>

              <MetadataItem>
                <MetadataLabel>Finished At</MetadataLabel>
                <MetadataValue><TextValue>{formatTimestamp(job.finishedAt)}</TextValue></MetadataValue>
              </MetadataItem>

              <MetadataItem>
                <MetadataLabel>Duration</MetadataLabel>
                <MetadataValue><TextValue>{formatDuration(job.duration)}</TextValue></MetadataValue>
              </MetadataItem>
            </MetadataGrid>

            {job.exception && (
              <ExceptionBox>
                <ExceptionTitle>Exception</ExceptionTitle>
                <ExceptionMessage>{job.exception.message}</ExceptionMessage>
                <ExceptionLocation>{job.exception.file}:{job.exception.line}</ExceptionLocation>
              </ExceptionBox>
            )}

            <DetailsBlock>
              <DetailsSummary>Message Payload</DetailsSummary>
              {job.dataTruncated ? (
                <TruncatedWarning>Payload was too large to store.</TruncatedWarning>
              ) : (
                <PreBlock>{parseJobData(job.data)}</PreBlock>
              )}
            </DetailsBlock>

            {(job.status === 'failed' && !job.retried) && (
              <RetryButton
                onClick={() => setConfirmAction('retry')}
                disabled={retryMutation.isPending}
              >
                {retryMutation.isPending ? 'Retrying...' : 'Retry Job'}
              </RetryButton>
            )}

            {(job.status === 'running' || job.status === 'queued') && (
              <CancelJobButton
                onClick={() => setConfirmAction('cancel')}
                disabled={cancelMutation.isPending}
              >
                {cancelMutation.isPending ? 'Cancelling...' : 'Cancel Job'}
              </CancelJobButton>
            )}
          </PanelContent>
        )}
      </Panel>

      {confirmAction && (
        <ConfirmBackdrop>
          <ConfirmDialog>
            <ConfirmText>
              {confirmAction === 'retry' && 'Re-dispatch this job? A new job will be created.'}
              {confirmAction === 'cancel' && 'Cancel this job? This cannot be undone.'}
            </ConfirmText>
            <ConfirmActions>
              <CancelBtn onClick={() => setConfirmAction(null)}>Cancel</CancelBtn>
              <DestructiveBtn
                onClick={handleConfirm}
                disabled={retryMutation.isPending || cancelMutation.isPending}
              >
                {confirmAction === 'retry' ? 'Retry' : 'Cancel Job'}
              </DestructiveBtn>
            </ConfirmActions>
          </ConfirmDialog>
        </ConfirmBackdrop>
      )}
    </>
  )
}
