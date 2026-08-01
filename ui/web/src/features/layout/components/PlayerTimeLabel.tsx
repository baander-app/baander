import styled from 'styled-components'
import { useCurrentTime } from '@/features/player/stores/player-time-tracker'
import { formatDuration } from '@/shared/utils/format-duration'

const TimeLabel = styled.span`
  display: block;
  width: 2rem;
  font-size: 10px;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

export function PlayerTimeLabel() {
  const currentTime = useCurrentTime()
  return (
    <TimeLabel>
      {formatDuration(currentTime)}
    </TimeLabel>
  )
}
