import styled from 'styled-components'

interface HealthIndicatorProps {
  component: string
  status: string
  responseTimeMs: number
}

const statusColors: Record<string, string> = {
  healthy: '#10b981',
  unhealthy: '#ef4444',
  not_available: '#f59e0b',
}

const componentLabels: Record<string, string> = {
  postgresql: 'PostgreSQL',
  redis: 'Redis',
  swoole: 'Swoole',
  memory: 'Memory',
}

const Wrapper = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const Dot = styled.span<{ $color: string }>`
  display: inline-block;
  height: 0.5rem;
  width: 0.5rem;
  border-radius: 50%;
  background-color: ${({ $color }) => $color};
`

const Label = styled.span`
  font-size: 13px;
`

const Time = styled.span`
  font-size: 11px;
  color: var(--color-muted-foreground);
  font-variant-numeric: tabular-nums;
`

export function HealthIndicator({ component, status, responseTimeMs }: HealthIndicatorProps) {
  const color = statusColors[status] ?? 'color-mix(in srgb, var(--color-muted-foreground) 30%, transparent)'
  const label = componentLabels[component] ?? component

  return (
    <Wrapper>
      <Dot $color={color} />
      <Label>{label}</Label>
      <Time>{responseTimeMs.toFixed(1)}ms</Time>
    </Wrapper>
  )
}
