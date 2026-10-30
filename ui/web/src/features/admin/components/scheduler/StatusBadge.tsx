import styled from 'styled-components'
import type { ScheduledJob } from '../../api/scheduler-admin-api'

const Wrapper = styled.span`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const Dot = styled.span<{ $color: string }>`
  display: inline-block;
  height: 0.375rem;
  width: 0.375rem;
  border-radius: 50%;
  background-color: ${({ $color }) => $color};
`

const Label = styled.span<{ $color: string }>`
  font-size: 13px;
  color: ${({ $color }) => $color};
`

export function StatusBadge({ status }: { status: ScheduledJob['status'] }) {
  const config = {
    active: { dot: 'var(--color-success)', label: 'Active', text: 'var(--color-success)' },
    paused: { dot: 'var(--color-warning)', label: 'Paused', text: 'var(--color-warning)' },
    disabled: { dot: 'var(--color-destructive)', label: 'Disabled', text: 'var(--color-destructive)' },
  }[status]

  return (
    <Wrapper>
      <Dot $color={config.dot} />
      <Label $color={config.text}>{config.label}</Label>
    </Wrapper>
  )
}
