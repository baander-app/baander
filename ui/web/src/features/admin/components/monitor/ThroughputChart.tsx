import styled from 'styled-components'
import { textRole } from '@/shared/theme'

interface ThroughputChartProps {
  throughput: number
}

const Card = styled.div`
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
`

const Title = styled.h3`
  ${textRole('body')}
  font-weight: 500;
  color: var(--color-muted-foreground);
`

const Value = styled.p`
  margin-top: 0.25rem;
  ${textRole('title-lg')}
  font-variant-numeric: tabular-nums;
`

const Unit = styled.span`
  ${textRole('subtitle')}
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
