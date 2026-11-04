import type { TextRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import type { Track } from '@/features/player/stores/player-types'

interface TrackCreditsProps {
  track: Pick<Track, 'artistName' | 'albumName' | 'albumLanguage'>
  textRole: TextRole
}

/**
 * A track's artist and album, each in its own element with its own language, joined by a
 * plain separator. The album carries its stored release language; the artist has none, so
 * its language comes from its own text.
 */
export function TrackCredits({ track, textRole }: TrackCreditsProps) {
  return (
    <>
      {track.artistName && <ContentText textRole={textRole} text={track.artistName} />}
      {track.artistName && track.albumName ? ' · ' : null}
      {track.albumName && <ContentText textRole={textRole} text={track.albumName} language={track.albumLanguage} />}
    </>
  )
}
