import styled from 'styled-components'

interface ThroughputChartProps {
  throughput: number
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

const Value = styled.p`
  margin-top: 0.25rem;
  font-size: 1.875rem;
  font-weight: 700;
`

const Unit = styled.span`
  font-size: 1.125rem;
  font-weight: 400;
  color: var(--color-muted-foreground);
`

export function ThroughputChart({ throughput }: ThroughputChartProps) {
  return (
    <Card>
      <Title>Throughput</Title>
      <Value>
        {throughput.toFixed(1)}{' '}
        <Unit>jobs/hr</Unit>
      </Value>
    </Card>
  )
}
