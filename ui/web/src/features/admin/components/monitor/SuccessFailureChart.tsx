import styled from 'styled-components'

interface SuccessFailureChartProps {
  statusCounts: Record<string, number>
}

function getSegmentColor(status: string): string {
  switch (status) {
    case 'finished':
      return '#10b981'
    case 'failed':
      return 'var(--color-destructive)'
    case 'cancelled':
      return 'var(--color-muted-foreground)'
    default:
      return 'var(--color-muted)'
  }
}

function getSegmentLabel(status: string): string {
  switch (status) {
    case 'finished':
      return 'Finished'
    case 'failed':
      return 'Failed'
    case 'cancelled':
      return 'Cancelled'
    default:
      return status
  }
}

const Card = styled.div`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
`

const Title = styled.h3`
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-muted-foreground);
`

const StackedBar = styled.div`
  margin-top: 0.75rem;
  display: flex;
  height: 1.25rem;
  overflow: hidden;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
`

const Segment = styled.div<{ $color: string }>`
  background-color: ${({ $color }) => $color};
  transition: all 0.15s;
`

const Legend = styled.div`
  margin-top: 0.75rem;
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
`

const LegendItem = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
`

const LegendDot = styled.div<{ $color: string }>`
  height: 0.75rem;
  width: 0.75rem;
  border-radius: 0.125rem;
  background-color: ${({ $color }) => $color};
`

const LegendLabel = styled.span`
  color: var(--color-muted-foreground);
`

const LegendValue = styled.span`
  font-weight: 500;
`

export function SuccessFailureChart({ statusCounts }: SuccessFailureChartProps) {
  const entries = Object.entries(statusCounts).filter(([, count]) => count > 0)
  const total = entries.reduce((sum, [, count]) => sum + count, 0)

  if (total === 0) return null

  const orderedStatuses = ['finished', 'failed', 'cancelled']
  const sortedEntries = [...entries].sort(([a], [b]) => {
    const ai = orderedStatuses.indexOf(a)
    const bi = orderedStatuses.indexOf(b)
    if (ai !== -1 && bi !== -1) return ai - bi
    if (ai !== -1) return -1
    if (bi !== -1) return 1
    return b.localeCompare(a)
  })

  return (
    <Card>
      <Title>Job Status Distribution</Title>

      <StackedBar>
        {sortedEntries.map(([status, count]) => {
          const pct = (count / total) * 100
          return (
            <Segment
              key={status}
              $color={getSegmentColor(status)}
              style={{ width: `${pct}%` }}
              title={`${getSegmentLabel(status)}: ${count} (${pct.toFixed(1)}%)`}
            />
          )
        })}
      </StackedBar>

      <Legend>
        {sortedEntries.map(([status, count]) => {
          const pct = ((count / total) * 100).toFixed(1)
          return (
            <LegendItem key={status}>
              <LegendDot $color={getSegmentColor(status)} />
              <LegendLabel>{getSegmentLabel(status)}</LegendLabel>
              <LegendValue>
                {count.toLocaleString()} ({pct}%)
              </LegendValue>
            </LegendItem>
          )
        })}
      </Legend>
    </Card>
  )
}
