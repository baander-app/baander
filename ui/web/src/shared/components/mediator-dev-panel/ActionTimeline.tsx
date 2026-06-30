import { useState, useEffect, useRef } from 'react'
import styled, { css } from 'styled-components'
import { focusVisibleRing } from '@/shared/theme'
import { filterActionLog } from '@/shared/lib/mediator/devtools'
import type { ActionLogEntry } from '@/shared/lib/mediator/types'

interface ActionTimelineProps {
  log?: ActionLogEntry[]
}

const Container = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
`

const FilterBar = styled.div`
  padding: 0.5rem;
  border-bottom: 1px solid var(--color-border);
`

const FilterInput = styled.input`
  width: 100%;
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
  font-family: var(--font-mono);
  background-color: var(--color-muted);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  outline: none;

  &:focus {
    ${focusVisibleRing}
  }
`

const ScrollArea = styled.div`
  flex: 1;
  overflow-y: auto;
`

const EntryRow = styled.div`
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.375rem 0.75rem;
  font-size: 0.75rem;
  font-family: var(--font-mono);
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);

  &:hover {
    background-color: color-mix(in srgb, var(--color-muted) 50%, transparent);
  }
`

const Timestamp = styled.span`
  color: var(--color-muted-foreground);
  flex-shrink: 0;
`

const ActionType = styled.span`
  font-weight: 600;
  color: var(--color-primary);
  flex-shrink: 0;
`

const Source = styled.span`
  color: var(--color-muted-foreground);
  flex-shrink: 0;
`

const Payload = styled.span`
  color: var(--color-muted-foreground);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
`

const ErrorCount = styled.span`
  color: var(--color-destructive);
  flex-shrink: 0;
`

const EmptyState = styled.div`
  padding: 1rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
  text-align: center;
`

export function ActionTimeline({ log: externalLog }: ActionTimelineProps) {
  const [filter, setFilter] = useState('')
  const scrollRef = useRef<HTMLDivElement>(null)

  // Use external log if provided, otherwise show nothing
  const log = externalLog ?? []

  // Auto-scroll to bottom when log changes
  useEffect(() => {
    if (scrollRef.current) {
      scrollRef.current.scrollTop = scrollRef.current.scrollHeight
    }
  }, [log])

  const filtered = filter
    ? filterActionLog(log, { typePrefix: filter }).length > 0
      ? filterActionLog(log, { typePrefix: filter })
      : filterActionLog(log, { source: filter })
    : log

  if (filtered.length === 0 && !filter) {
    return <EmptyState>No actions dispatched yet.</EmptyState>
  }

  return (
    <Container>
      <FilterBar>
        <FilterInput
          type="text"
          placeholder="Filter by action type or source..."
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
        />
      </FilterBar>
      <ScrollArea ref={scrollRef}>
        {filtered.map((entry) => (
          <EntryRow key={entry.id}>
            <Timestamp>{new Date(entry.timestamp).toLocaleTimeString()}</Timestamp>
            <ActionType>{entry.type}</ActionType>
            <Source>&larr; {entry.source}</Source>
            <Payload>{JSON.stringify(entry.payload)}</Payload>
            {entry.errors.length > 0 && (
              <ErrorCount>
                {entry.errors.length} error{entry.errors.length > 1 ? 's' : ''}
              </ErrorCount>
            )}
          </EntryRow>
        ))}
        {filtered.length === 0 && filter && (
          <EmptyState>No actions matching &ldquo;{filter}&rdquo;</EmptyState>
        )}
      </ScrollArea>
    </Container>
  )
}
