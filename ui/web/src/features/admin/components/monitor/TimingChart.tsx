import styled from 'styled-components'

interface TimingEntry {
  name: string
  avg: number
  median: number
  p95: number
}

interface TimingChartProps {
  executionTimes: TimingEntry[]
}

function formatMs(ms: number): string {
  if (ms < 1000) return `${ms.toFixed(0)}ms`
  if (ms < 60_000) return `${(ms / 1000).toFixed(1)}s`
  return `${(ms / 60_000).toFixed(1)}m`
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

const Entries = styled.div`
  margin-top: 1rem;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
`

const EntryName = styled.p`
  margin-bottom: 0.5rem;
  font-size: 0.875rem;
  font-weight: 500;
`

const Bars = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
`

const BarRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const BarLabel = styled.span`
  width: 9rem;
  flex-shrink: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const BarTrack = styled.div`
  height: 1rem;
  flex: 1;
  overflow: hidden;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
`

const BarFill = styled.div<{ $color: string }>`
  height: 1rem;
  border-radius: 0.25rem;
  background-color: ${({ $color }) => $color};
  transition: all 0.15s;
`

const BarValue = styled.span`
  width: 4rem;
  flex-shrink: 0;
  text-align: right;
  font-size: 0.875rem;
  font-weight: 500;
`

const STAT_CONFIG = [
  { key: 'p95' as const, label: 'P95', color: '#f59e0b' },
  { key: 'median' as const, label: 'Median', color: 'var(--color-primary)' },
  { key: 'avg' as const, label: 'Average', color: '#10b981' },
] as const

function HorizontalBar({
  label,
  value,
  max,
  color,
  formatValue,
}: {
  label: string
  value: number
  max: number
  color: string
  formatValue: (v: number) => string
}) {
  const width = max > 0 ? (value / max) * 100 : 0

  return (
    <BarRow>
      <BarLabel>{label}</BarLabel>
      <BarTrack>
        <BarFill $color={color} style={{ width: `${width}%` }} />
      </BarTrack>
      <BarValue>{formatValue(value)}</BarValue>
    </BarRow>
  )
}

export function TimingChart({ executionTimes }: TimingChartProps) {
  if (executionTimes.length === 0) return null

  const globalMax = Math.max(...executionTimes.flatMap((e) => [e.p95, e.median, e.avg]))

  return (
    <Card>
      <Title>Execution Times</Title>
      <Entries>
        {executionTimes.map((entry) => (
          <div key={entry.name}>
            <EntryName>{entry.name}</EntryName>
            <Bars>
              {STAT_CONFIG.map((stat) => (
                <HorizontalBar
                  key={stat.key}
                  label={stat.label}
                  value={entry[stat.key]}
                  max={globalMax}
                  color={stat.color}
                  formatValue={formatMs}
                />
              ))}
            </Bars>
          </div>
        ))}
      </Entries>
    </Card>
  )
}
