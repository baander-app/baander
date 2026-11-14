import { formatDuration } from '@/shared/utils/format-duration'
import { isPairedTrack } from './match-ticks'
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

/** The name of every field and credits row of the comparison, by row key. */
export function matchRowLabels(comparison: MatchComparison): ReadonlyMap<string, string> {
  const labels = new Map<string, string>()

  for (const row of comparison.fields) {
    labels.set(row.key, matchFieldLabel(row.field, comparison.type))
  }
  if (comparison.credits !== null) {
    labels.set(comparison.credits.key, CREDITS_LABEL)
  }
  for (const track of comparison.tracks.filter(isPairedTrack)) {
    const title = track.song?.title ?? ''

    for (const row of track.fields) {
      labels.set(row.key, songRowLabel(matchFieldLabel(row.field, 'song'), title))
    }
    if (track.credits !== null) {
      labels.set(track.credits.key, songRowLabel(CREDITS_LABEL, title))
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

/** What an apply wrote, as its result names it. */
export interface AppliedMatch {
  readonly type: MatchItemType
  /** The item's own fields the match wrote, by field name. */
  readonly fields: readonly string[]
  readonly creditsChanged: boolean
  readonly songsChanged: number
  readonly mergedArtists: readonly string[]
  readonly removedArtists: readonly string[]
}

function isMatchField(field: string): field is MatchField {
  return Object.hasOwn(FIELD_LABELS, field)
}

/** The message naming what an apply changed, such as "Matched with MusicBrainz: Genres, artist credits, 26 songs, 14 artists merged." */
export function matchAppliedMessage(applied: AppliedMatch): string {
  const parts = [
    ...applied.fields.map((field) => (isMatchField(field) ? matchFieldLabel(field, applied.type) : field)),
    applied.creditsChanged ? 'artist credits' : null,
    applied.songsChanged > 0 ? countOf(applied.songsChanged, 'song', 'songs') : null,
    applied.mergedArtists.length > 0 ? `${countOf(applied.mergedArtists.length, 'artist', 'artists')} merged` : null,
    applied.removedArtists.length > 0 ? `${countOf(applied.removedArtists.length, 'artist', 'artists')} removed` : null,
  ].filter((part) => part !== null)

  return parts.length === 0 ? 'Matched with MusicBrainz; nothing needed to change.' : `Matched with MusicBrainz: ${parts.join(', ')}.`
}
