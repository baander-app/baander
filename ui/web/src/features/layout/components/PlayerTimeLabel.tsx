import styled from 'styled-components'
import { PlaybackClock } from '@/features/player/components/PlaybackRecoveryStatus'
import { formatDuration } from '@/shared/utils/format-duration'

const TimeLabel = styled.span`
  display: block;
  min-width: 2rem;
  white-space: nowrap;
  font-size: 10px;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

export function PlayerTimeLabel() {
  return (
    <TimeLabel>
      <PlaybackClock format={formatDuration} />
    </TimeLabel>
  )
}
