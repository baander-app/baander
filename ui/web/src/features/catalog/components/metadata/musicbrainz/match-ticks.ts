import type {
  MatchArtistAction,
  MatchComparison,
  MatchCreditsRow,
  MatchFieldRow,
  MatchFieldValue,
  MatchSelection,
  MatchTicks,
  MatchTrack,
} from './types'

/** The tick key of a field row; the same field of the album and of one of its songs get different keys. */
export function fieldRowKey(row: Pick<MatchFieldRow, 'field' | 'songPublicId'>): string {
  return `field:${row.songPublicId ?? ''}:${row.field}`
}

/** The tick key of a credits row: the item's own, or one song's. */
export function creditsRowKey(row: Pick<MatchCreditsRow, 'songPublicId'>): string {
  return `credits:${row.songPublicId ?? ''}`
}

/** The tick key of an artist merge or removal. */
export function artistActionKey(action: Pick<MatchArtistAction, 'artistPublicId'>): string {
  return `artist:${action.artistPublicId}`
}

/** A track whose local song pairs with a release track; only those are written. */
export function isPairedTrack(track: MatchTrack): boolean {
  return track.song !== null && track.release !== null
}

/** The item's own field rows, then those of its paired songs in release order. */
export function comparisonFieldRows(comparison: MatchComparison): MatchFieldRow[] {
  return [...comparison.fields, ...comparison.tracks.filter(isPairedTrack).flatMap((track) => track.fields)]
}

/** The item's own credits row, then those of its paired songs in release order. */
export function comparisonCreditsRows(comparison: MatchComparison): MatchCreditsRow[] {
  const songRows = comparison.tracks.filter(isPairedTrack).map((track) => track.credits)

  return [comparison.credits, ...songRows].filter((row): row is MatchCreditsRow => row !== null)
}

/** The ticks the comparison sets by default. */
export function defaultTicks(comparison: MatchComparison): MatchTicks {
  return Object.fromEntries([
    ...comparisonFieldRows(comparison).map((row) => [fieldRowKey(row), row.ticked] as const),
    ...comparisonCreditsRows(comparison).map((row) => [creditsRowKey(row), row.ticked] as const),
    ...comparison.artistActions.map((action) => [artistActionKey(action), action.ticked] as const),
  ])
}

/** Whether applying the row would change the field. Genre names compare ignoring case and order. */
export function fieldRowChanges(row: Pick<MatchFieldRow, 'current' | 'proposed'>): boolean {
  const { current, proposed } = row

  if (isList(current) && isList(proposed)) {
    return foldedNames(current) !== foldedNames(proposed)
  }

  return current !== proposed
}

/** Whether the admin may tick the row: the field is unlocked and the row would change it. */
export function isFieldRowSelectable(row: MatchFieldRow): boolean {
  return !row.locked && fieldRowChanges(row)
}

/**
 * Whether applying the row would change the credits: other artists, roles or credited names, a
 * new artist, or a stored artist taking on its MusicBrainz ID.
 */
export function creditsRowChanges(row: MatchCreditsRow): boolean {
  if (row.proposed.some((credit) => credit.artist.publicId === null || credit.artist.takesId)) {
    return true
  }

  const now = row.current.map((credit) => creditKey(credit.artistPublicId, credit.role ?? '', credit.creditedName)).sort()
  const then = row.proposed.map((credit) => creditKey(credit.artist.publicId ?? '', credit.role, credit.creditedName)).sort()

  return now.join('\n') !== then.join('\n')
}

/**
 * The merges and removals that stand under the ticks: each needs every credits row it requires to
 * be ticked. The others leave their artist as it is and are not shown.
 */
export function standingArtistActions(comparison: MatchComparison, ticks: MatchTicks): MatchArtistAction[] {
  const written = comparisonCreditsRows(comparison).filter((row) => isWritten(row, ticks))
  const songs = new Set(written.map((row) => row.songPublicId).filter((song): song is string => song !== null))
  const albumCredits = written.some((row) => row.songPublicId === null)

  return comparison.artistActions.filter(
    (action) => (!action.requiresAlbumCredits || albumCredits) && action.requiredSongs.every((song) => songs.has(song)),
  )
}

/**
 * What an apply takes from the ticks: each ticked row that would change something, with the value it
 * showed, and every listed merge or removal, applied while it stands and stays ticked, else kept.
 */
export function buildMatchSelection(comparison: MatchComparison, ticks: MatchTicks): MatchSelection {
  const applied = standingArtistActions(comparison, ticks)
    .filter((action) => ticks[artistActionKey(action)] === true)
    .map((action) => action.artistPublicId)

  return {
    fields: comparisonFieldRows(comparison)
      .filter((row) => ticks[fieldRowKey(row)] === true && isFieldRowSelectable(row))
      .map((row) => ({ field: row.field, current: row.current, songPublicId: row.songPublicId })),
    credits: comparisonCreditsRows(comparison)
      .filter((row) => isWritten(row, ticks))
      .map((row) => ({
        songPublicId: row.songPublicId,
        current: row.current.map(({ artistPublicId, role, creditedName }) => ({ artistPublicId, role, creditedName })),
      })),
    artistActions: applied,
    keptArtists: comparison.artistActions
      .map((action) => action.artistPublicId)
      .filter((artist) => !applied.includes(artist)),
  }
}

function isWritten(row: MatchCreditsRow, ticks: MatchTicks): boolean {
  return ticks[creditsRowKey(row)] === true && creditsRowChanges(row)
}

function isList(value: MatchFieldValue | null): value is readonly string[] {
  return Array.isArray(value)
}

function foldedNames(names: readonly string[]): string {
  return [...new Set(names.map((name) => name.toLowerCase()))].sort().join('\n')
}

function creditKey(artistPublicId: string, role: string, creditedName: string): string {
  return [artistPublicId, role, creditedName].join('\u0000')
}
