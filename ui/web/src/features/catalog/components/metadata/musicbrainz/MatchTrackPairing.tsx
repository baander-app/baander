import { useRef, type CSSProperties, type Ref } from 'react'
import styled from 'styled-components'
import { useVirtualizer } from '@tanstack/react-virtual'
import { ArrowRight } from 'lucide-react'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { CreditsTickRow, FieldTickRow } from './MatchRows'
import { CREDITS_LABEL, formatMilliseconds, formatSeconds, matchFieldLabel, songRowLabel } from './match-format'
import type { ComparedReleaseTrack, ComparedSong, MatchTicks, MatchTrack } from './types'

/** Longer pairings render only the tracks in view. */
const VIRTUALIZE_ABOVE = 50

/** Estimated heights of a pairing line and of each row under it; rendered tracks are measured. */
const TRACK_LINE_HEIGHT = 40
const CHANGE_ROW_HEIGHT = 32

const Header = styled.div`
  display: grid;
  grid-template-columns: minmax(0, 1fr) 1rem minmax(0, 1fr);
  column-gap: 0.75rem;
  padding-bottom: 0.25rem;
  border-bottom: 1px solid var(--color-border);
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const List = styled.ul`
  margin: 0;
  padding: 0;
  list-style: none;
`

const Scroller = styled.div`
  max-height: 24rem;
  overflow-y: auto;
`

const Item = styled.li`
  padding: 0.5rem 0;

  & + & {
    border-top: 1px solid var(--color-border);
  }
`

const Line = styled.div`
  display: grid;
  grid-template-columns: minmax(0, 1fr) 1rem minmax(0, 1fr);
  align-items: baseline;
  column-gap: 0.75rem;
  ${textRole('body-sm')}
`

const Side = styled.span`
  display: flex;
  min-width: 0;
  align-items: baseline;
  gap: 0.5rem;
`

const Position = styled.span`
  min-width: 2rem;
  flex-shrink: 0;
  ${textRole('mono-sm')}
  color: var(--color-muted-foreground);
`

const Title = styled(ContentText)`
  min-width: 0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const Length = styled.span`
  flex-shrink: 0;
  ${textRole('mono-sm')}
  color: var(--color-muted-foreground);
`

const Missing = styled.span`
  color: var(--color-muted-foreground);
`

const Arrow = styled(ArrowRight)`
  width: 0.875rem;
  height: 0.875rem;
  align-self: center;
  color: var(--color-muted-foreground);
`

const Changes = styled.div`
  padding-left: 2.5rem;
`

const NoChanges = styled.p`
  margin: 0.25rem 0 0 2.5rem;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

interface MatchTrackPairingProps {
  readonly tracks: readonly MatchTrack[]
  readonly ticks: MatchTicks
  readonly applying: boolean
  readonly onTick: (key: string, ticked: boolean) => void
  /** The album's stored language, which its song titles take. */
  readonly language?: string | null
}

/**
 * The album's songs beside the release's tracks: each pair with the song's rows that would change,
 * then the tracks and songs left unpaired on either side, which an apply leaves alone.
 */
export function MatchTrackPairing(props: MatchTrackPairingProps) {
  const multiDisc = props.tracks.some((track) => (track.song?.disc ?? 1) > 1 || (track.release?.disc ?? 1) > 1)

  return (
    <div>
      <Header aria-hidden>
        <span>Your files</span>
        <span />
        <span>MusicBrainz</span>
      </Header>
      {props.tracks.length > VIRTUALIZE_ABOVE ? <VirtualPairing {...props} multiDisc={multiDisc} /> : <PlainPairing {...props} multiDisc={multiDisc} />}
    </div>
  )
}

interface PairingListProps extends MatchTrackPairingProps {
  readonly multiDisc: boolean
}

function PlainPairing({ tracks, ...rest }: PairingListProps) {
  return (
    <List aria-label="Track pairing">
      {tracks.map((track) => (
        <TrackItem key={trackKey(track)} track={track} {...rest} />
      ))}
    </List>
  )
}

function VirtualPairing({ tracks, ...rest }: PairingListProps) {
  const scrollRef = useRef<HTMLDivElement>(null)
  const virtualizer = useVirtualizer({
    count: tracks.length,
    getScrollElement: () => scrollRef.current,
    estimateSize: (index) => estimatedHeight(tracks[index]),
    overscan: 8,
    getItemKey: (index) => trackKey(tracks[index]),
  })

  return (
    <Scroller ref={scrollRef}>
      <List aria-label="Track pairing" style={{ position: 'relative', height: `${virtualizer.getTotalSize()}px` }}>
        {virtualizer.getVirtualItems().map((item) => (
          <TrackItem
            key={item.key}
            ref={virtualizer.measureElement}
            index={item.index}
            style={{ position: 'absolute', top: 0, left: 0, width: '100%', transform: `translateY(${item.start}px)` }}
            track={tracks[item.index]}
            {...rest}
          />
        ))}
      </List>
    </Scroller>
  )
}

interface TrackItemProps extends Omit<PairingListProps, 'tracks'> {
  readonly track: MatchTrack
  readonly ref?: Ref<HTMLLIElement>
  /** The track's position in a virtual list, which measures it. */
  readonly index?: number
  readonly style?: CSSProperties
}

function TrackItem({ track, ticks, applying, onTick, language, multiDisc, ref, index, style }: TrackItemProps) {
  const { song, release } = track
  const pairedSong = release === null ? null : song
  const fields = track.fields.filter((row) => row.changes)
  const credits = track.credits?.changes === true ? track.credits : null

  return (
    <Item ref={ref} data-index={index} style={style}>
      <Line>
        {song === null ? <Missing>Not in your library</Missing> : <SongSide song={song} multiDisc={multiDisc} language={language} />}
        <Arrow aria-hidden />
        {release === null ? <Missing>No track on this release</Missing> : <ReleaseSide release={release} multiDisc={multiDisc} language={language} />}
      </Line>
      {pairedSong !== null && fields.length === 0 && credits === null && <NoChanges>No changes</NoChanges>}
      {pairedSong !== null && (fields.length > 0 || credits !== null) && (
        <Changes>
          {fields.map((row) => {
            const label = matchFieldLabel(row.field, 'song')

            return (
              <FieldTickRow
                key={row.field}
                row={row}
                label={label}
                accessibleName={songRowLabel(label, pairedSong.title)}
                language={language}
                ticked={ticks[row.key] === true}
                applying={applying}
                onTick={(ticked) => onTick(row.key, ticked)}
              />
            )
          })}
          {credits !== null && (
            <CreditsTickRow
              row={credits}
              label={CREDITS_LABEL}
              accessibleName={songRowLabel(CREDITS_LABEL, pairedSong.title)}
              ticked={ticks[credits.key] === true}
              applying={applying}
              onTick={(ticked) => onTick(credits.key, ticked)}
            />
          )}
        </Changes>
      )}
    </Item>
  )
}

interface SideProps {
  readonly multiDisc: boolean
  readonly language?: string | null
}

function SongSide({ song, multiDisc, language }: SideProps & { readonly song: ComparedSong }) {
  const track = song.track === null ? '—' : String(song.track)

  return (
    <Side>
      <Position>{multiDisc && song.disc !== null ? `${song.disc}.${track}` : track}</Position>
      <Title textRole="body-sm" text={song.title} language={language} />
      <Length>{formatSeconds(song.lengthSeconds)}</Length>
    </Side>
  )
}

function ReleaseSide({ release, multiDisc, language }: SideProps & { readonly release: ComparedReleaseTrack }) {
  return (
    <Side>
      <Position>{multiDisc ? `${release.disc}.${release.number}` : release.number}</Position>
      <Title textRole="body-sm" text={release.title} language={language} />
      <Length>{formatMilliseconds(release.lengthMs)}</Length>
    </Side>
  )
}

function trackKey(track: MatchTrack): string {
  return track.song?.publicId ?? `release:${track.release?.disc}:${track.release?.position}`
}

function estimatedHeight(track: MatchTrack): number {
  const fields = track.fields.filter((row) => row.changes).length
  const credits = track.credits?.changes === true ? 1 : 0

  return TRACK_LINE_HEIGHT + (fields + credits) * CHANGE_ROW_HEIGHT
}
