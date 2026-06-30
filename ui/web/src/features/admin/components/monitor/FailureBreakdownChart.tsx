import styled from 'styled-components'

interface FailureEntry {
  class: string
  count: number
}

interface FailureBreakdownChartProps {
  topExceptionClasses: FailureEntry[]
}

function shortClassName(fullClass: string): string {
  const parts = fullClass.split('\\')
  return parts[parts.length - 1] || fullClass
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

const Bars = styled.div`
  margin-top: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const BarRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const BarLabel = styled.span`
  width: 14rem;
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

const BarFill = styled.div`
  height: 1rem;
  border-radius: 0.25rem;
  background-color: var(--color-destructive);
  transition: all 0.15s;
`

const BarValue = styled.span`
  width: 3rem;
  flex-shrink: 0;
  text-align: right;
  font-size: 0.875rem;
  font-weight: 500;
`

export function FailureBreakdownChart({ topExceptionClasses }: FailureBreakdownChartProps) {
  if (topExceptionClasses.length === 0) return null

  const maxCount = Math.max(...topExceptionClasses.map((e) => e.count))

  return (
    <Card>
      <Title>Top Failure Types</Title>
      <Bars>
        {topExceptionClasses.map((entry) => {
          const width = maxCount > 0 ? (entry.count / maxCount) * 100 : 0
          return (
            <BarRow key={entry.class} title={shortClassName(entry.class)}>
              <BarLabel>{shortClassName(entry.class)}</BarLabel>
              <BarTrack>
                <BarFill style={{ width: `${width}%` }} />
              </BarTrack>
              <BarValue>{entry.count.toLocaleString()}</BarValue>
            </BarRow>
          )
        })}
      </Bars>
    </Card>
  )
}
