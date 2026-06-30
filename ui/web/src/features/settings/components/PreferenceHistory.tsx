import { useQuery } from '@tanstack/react-query'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Undo2, Loader2 } from 'lucide-react'
import type { PreferenceVersion } from '../hooks/use-preference-sync'

const SpinningIcon = styled(Loader2)`
  margin-right: 0.25rem;
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`

const UndoIcon = styled(Undo2)`
  margin-right: 0.25rem;
`

const HistoryContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const HistoryHeader = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const HistoryLabel = styled.span`
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-muted-foreground);
`

const HistoryList = styled.div`
  max-height: 8rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const HistoryEntry = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
`

const RestoreIcon = styled(Undo2)`
  margin-right: 0.25rem;
`

const SmallSpinner = styled(Loader2)`
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`

interface PreferenceHistoryProps {
  fetchHistory: () => Promise<PreferenceVersion[]>
  onRollback: (version: number) => Promise<boolean>
  currentVersion: number
  label: string
}

export function PreferenceHistory({
  fetchHistory,
  onRollback,
  currentVersion,
  label,
}: PreferenceHistoryProps) {
  const {
    data: history = [],
    isLoading,
    refetch,
  } = useQuery({
    queryKey: ['preference-history', label],
    queryFn: fetchHistory,
    enabled: false,
  })

  if (history.length === 0) {
    return (
      <Button
        size="sm"
        variant="ghost"
        onClick={() => refetch()}
        disabled={isLoading}
      >
        {isLoading ? (
          <SpinningIcon size={14} />
        ) : (
          <UndoIcon size={14} />
        )}
        History
      </Button>
    )
  }

  return (
    <HistoryContainer>
      <HistoryHeader>
        <HistoryLabel>History</HistoryLabel>
        <Button
          size="sm"
          variant="ghost"
          style={{ height: 24, padding: '0 0.5rem', fontSize: '0.75rem' }}
          onClick={() => refetch()}
          disabled={isLoading}
        >
          {isLoading ? <SmallSpinner size={12} /> : 'Refresh'}
        </Button>
      </HistoryHeader>
      <HistoryList>
        {history.map((entry) => (
          <HistoryEntry key={entry.version}>
            <span>
              v{entry.version}
              {entry.version === currentVersion ? ' (current)' : ''}
              {' — '}
              {new Date(entry.createdAt).toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
              })}
            </span>
            {entry.version !== currentVersion && (
              <Button
                size="sm"
                variant="ghost"
                style={{ height: 20, padding: '0 0.375rem', fontSize: '0.75rem' }}
                onClick={() => onRollback(entry.version)}
              >
                <RestoreIcon size={10} />
                Restore
              </Button>
            )}
          </HistoryEntry>
        ))}
      </HistoryList>
    </HistoryContainer>
  )
}
