import styled from 'styled-components'
import { Star, Play, Radio } from 'lucide-react'
import type { RadioStation } from '@/features/radio/api/radio-api'
import { useRadioPlayback } from '@/features/radio/hooks/use-radio-playback'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { Button } from '@/shared/components/ui/button'

const CardContainer = styled.div<{ $active: boolean }>`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border-radius: var(--radius-md);
  padding: 0.5rem;
  transition: background-color 0.15s;

  &:hover {
    background-color: var(--color-muted);
  }

  background-color: ${({ $active }) =>
    $active ? 'color-mix(in srgb, var(--color-accent) 50%, transparent)' : 'transparent'};
`

const LogoContainer = styled.div`
  display: flex;
  height: 2.5rem;
  width: 2.5rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
  background-color: var(--color-muted);
`

const StationLogo = styled.img`
  height: 2.5rem;
  width: 2.5rem;
  border-radius: var(--radius-md);
  object-fit: cover;
`

const StationInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const StationName = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
`

const StationMeta = styled.div`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const MetaText = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const GenreList = styled.div`
  margin-top: 0.125rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
`

const GenreTag = styled.span`
  border-radius: 9999px;
  background-color: var(--color-muted);
  padding: 0.125rem 0.375rem;
  font-size: 10px;
  color: var(--color-muted-foreground);
`

const Actions = styled.div`
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.25rem;
`

interface StationCardProps {
  station: RadioStation
  isStarred?: boolean
  onStar: (stationId: string) => void
  onUnstar: (stationId: string) => void
}

function formatBitrate(bitrate: number): string {
  if (bitrate >= 1000) return `${(bitrate / 1000).toFixed(1)} Mbps`
  return `${bitrate} kbps`
}

export function StationCard({ station, isStarred, onStar, onUnstar }: StationCardProps) {
  const { start } = useRadioPlayback()
  const activeStation = useRadioStore((s) => s.activeStation)
  const isPlaying = useRadioStore((s) => s.isPlaying)
  const isActive = activeStation?.id === station.id

  const handlePlay = () => {
    start(station)
  }

  const handleStar = () => {
    if (isStarred) {
      onUnstar(station.id)
    } else {
      onStar(station.id)
    }
  }

  return (
    <CardContainer $active={isActive}>
      {/* Logo or placeholder */}
      <LogoContainer>
        {station.logo ? (
          <StationLogo src={station.logo} alt="" />
        ) : (
          <Radio size={18} style={{ color: 'var(--color-muted-foreground)' }} />
        )}
      </LogoContainer>

      {/* Station info */}
      <StationInfo>
        <StationName>{station.name}</StationName>
        <StationMeta>
          {station.language && (
            <MetaText>{station.language}</MetaText>
          )}
          {station.streams.length > 0 && (
            <MetaText>
              {station.streams[0].format.toUpperCase()} · {formatBitrate(station.streams[0].bitrate)}
            </MetaText>
          )}
        </StationMeta>
        {station.genres.length > 0 && (
          <GenreList>
            {station.genres.slice(0, 3).map((genre) => (
              <GenreTag key={genre}>{genre}</GenreTag>
            ))}
          </GenreList>
        )}
      </StationInfo>

      {/* Actions */}
      <Actions>
        <Button
          variant="ghost"
          size="icon"
          style={{ height: '2rem', width: '2rem' }}
          onClick={handleStar}
          aria-label={isStarred ? 'Unstar station' : 'Star station'}
        >
          <Star size={16} style={isStarred ? { fill: '#facc15', color: '#facc15' } : undefined} />
        </Button>
        <Button
          variant={isActive && isPlaying ? 'secondary' : 'ghost'}
          size="icon"
          style={{ height: '2rem', width: '2rem' }}
          onClick={handlePlay}
          aria-label="Play station"
        >
          <Play size={16} fill={isActive && isPlaying ? 'currentColor' : 'none'} />
        </Button>
      </Actions>
    </CardContainer>
  )
}
