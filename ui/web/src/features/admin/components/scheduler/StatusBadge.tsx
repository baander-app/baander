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
    active: { dot: '#10b981', label: 'Active', text: '#34d399' },
    paused: { dot: '#eab308', label: 'Paused', text: '#facc15' },
    disabled: { dot: '#ef4444', label: 'Disabled', text: '#f87171' },
  }[status]

  return (
    <Wrapper>
      <Dot $color={config.dot} />
      <Label $color={config.text}>{config.label}</Label>
    </Wrapper>
  )
}
