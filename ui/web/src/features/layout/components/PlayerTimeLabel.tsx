import styled from 'styled-components'
import { PlaybackClock } from '@/features/player/components/PlaybackRecoveryStatus'
import { formatDuration } from '@/shared/utils/format-duration'
import { textRole } from '@/shared/theme'

const TimeLabel = styled.span`
  display: block;
  min-width: 2rem;
  white-space: nowrap;
  ${textRole('timecode')}
  color: var(--color-muted-foreground);
`

export function PlayerTimeLabel() {
  return (
    <TimeLabel>
      <PlaybackClock format={formatDuration} />
    </TimeLabel>
  )
}
