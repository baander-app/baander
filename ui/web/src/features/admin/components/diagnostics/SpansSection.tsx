import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { useClearSpans, useSpans } from '../../hooks/use-debug-stats'
import type { Span } from '../../api/debug-api'
import { DiagnosticsCard } from './DiagnosticsCard'
import { MutedLine } from './diagnostics-styles'
import { SectionError, SectionSkeleton } from './SectionStates'
import { formatDurationMs } from './format'

const SPAN_LIMIT = 30

const SpanList = styled.ul`
  list-style: none;

  & > li + li {
    border-top: 1px solid var(--color-border);
  }
`

const SpanRow = styled.li`
  display: flex;
  align-items: center;
  gap: var(--space-md);
  padding: 0.375rem var(--space-md);
  font-size: 0.8125rem;
`

const SpanName = styled.span`
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-muted-foreground);
`

const SpanMeta = styled.span`
  flex-shrink: 0;
  font-family: var(--font-mono);
  font-size: 0.75rem;
`

const ClearError = styled.p`
  padding: 0.625rem var(--space-md);
  font-size: 0.875rem;
  color: var(--color-destructive);
`

function responseStatus(span: Span): string | null {
  const status = span.attributes['http.response.status_code']

  return typeof status === 'number' || typeof status === 'string' ? String(status) : null
}

export function SpansSection() {
  const { data: spans, isLoading, error, refetch } = useSpans(SPAN_LIMIT)
  const clearSpans = useClearSpans()

  const clearButton = (
    <Button
      variant="ghost"
      size="xs"
      disabled={clearSpans.isPending}
      onClick={() => clearSpans.mutate()}
    >
      Clear
    </Button>
  )

  return (
    <DiagnosticsCard title="Recent spans" action={clearButton}>
      {clearSpans.isError && <ClearError>Failed to clear spans.</ClearError>}
      {isLoading ? (
        <SectionSkeleton />
      ) : error || !spans ? (
        <SectionError message="Failed to load spans." onRetry={() => refetch()} />
      ) : spans.length === 0 ? (
        <MutedLine>No spans recorded.</MutedLine>
      ) : (
        <SpanList>
          {spans.map((span) => {
            const status = responseStatus(span)

            return (
              <SpanRow key={span.span_id}>
                <SpanName title={span.operation_name}>{span.operation_name}</SpanName>
                {status !== null && <SpanMeta>{status}</SpanMeta>}
                <SpanMeta>{formatDurationMs(span.duration_us)}</SpanMeta>
              </SpanRow>
            )
          })}
        </SpanList>
      )}
    </DiagnosticsCard>
  )
}
