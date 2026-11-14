import { formatDuration } from '@/shared/utils/format-duration'
import { creditsRowKey, fieldRowKey, isPairedTrack } from './match-ticks'
import type { MatchComparison, MatchField, MatchItemType } from './types'

const FIELD_LABELS: Readonly<Record<MatchField, string>> = {
  mbid: 'MusicBrainz ID',
  releaseMbid: 'Release ID',
  title: 'Title',
  year: 'Year',
  label: 'Label',
  catalogNumber: 'Catalog number',
  barcode: 'Barcode',
  country: 'Country',
  disambiguation: 'Disambiguation',
  genres: 'Genres',
  cover: 'Cover',
  track: 'Track',
  disc: 'Disc',
  name: 'Name',
  sortName: 'Sort name',
  type: 'Type',
  lifeSpanBegin: 'Began',
  lifeSpanEnd: 'Ended',
}

const MBID_LABELS: Readonly<Record<MatchItemType, string>> = {
  album: 'Release group ID',
  song: 'Recording ID',
  artist: 'Artist ID',
}

export const CREDITS_LABEL = 'Artist credits'

/** The label of a field of an album, song or artist; a MusicBrainz ID names what it identifies. */
export function matchFieldLabel(field: MatchField, owner: MatchItemType): string {
  return field === 'mbid' ? MBID_LABELS[owner] : FIELD_LABELS[field]
}

/** The name of a song's row, such as `Year for “Pour Me a Drink”`. */
export function songRowLabel(label: string, songTitle: string): string {
  return `${label} for “${songTitle}”`
}

/** The name of every field and credits row of the comparison, by tick key. */
export function matchRowLabels(comparison: MatchComparison): ReadonlyMap<string, string> {
  const labels = new Map<string, string>()

  for (const row of comparison.fields) {
    labels.set(fieldRowKey(row), matchFieldLabel(row.field, comparison.type))
  }
  if (comparison.credits !== null) {
    labels.set(creditsRowKey(comparison.credits), CREDITS_LABEL)
  }
  for (const track of comparison.tracks.filter(isPairedTrack)) {
    const title = track.song?.title ?? ''

    for (const row of track.fields) {
      labels.set(fieldRowKey(row), songRowLabel(matchFieldLabel(row.field, 'song'), title))
    }
    if (track.credits !== null) {
      labels.set(creditsRowKey(track.credits), songRowLabel(CREDITS_LABEL, title))
    }
  }

  return labels
}

/** A length in milliseconds as mm:ss, or an em dash when unknown. */
export function formatMilliseconds(milliseconds: number | null): string {
  return milliseconds === null ? '—' : formatDuration(milliseconds / 1000)
}

/** A length in seconds as mm:ss, or an em dash when unknown. */
export function formatSeconds(seconds: number | null): string {
  return seconds === null ? '—' : formatDuration(seconds)
}

/** A count with its noun, such as "1 track" or "27 tracks". */
export function countOf(count: number, one: string, many: string): string {
  return `${count} ${count === 1 ? one : many}`
}

/**
 * An artist's active years, such as "1995–present" or "1970–1980"; null when MusicBrainz knows
 * neither when the artist began nor that it ended.
 */
export function formatLifeSpan(begin: string | null, end: string | null, ended: boolean): string | null {
  if (begin === null && end === null && !ended) {
    return null
  }

  const until = end === null ? (ended ? '?' : 'present') : end.slice(0, 4)

  return `${begin === null ? '?' : begin.slice(0, 4)}–${until}`
}
