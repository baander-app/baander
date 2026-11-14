import type { MatchArtistAction, MatchComparison, MatchCreditsRow, MatchFieldRow, MatchSelection, MatchTicks, MatchTrack } from './types'

/** The tick key of an artist merge or removal; rows are ticked by their own key. */
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
    ...comparisonFieldRows(comparison).map((row) => [row.key, row.ticked] as const),
    ...comparisonCreditsRows(comparison).map((row) => [row.key, row.ticked] as const),
    ...comparison.artistActions.map((action) => [artistActionKey(action), action.ticked] as const),
  ])
}

/** Whether the admin may tick the field row: the field is unlocked and the row would change it. */
export function isSelectable(row: MatchFieldRow): boolean {
  return !row.locked && row.changes
}

/** The merges and removals that stand under the ticks: each needs every credits row it requires to be ticked. */
export function standingArtistActions(comparison: MatchComparison, ticks: MatchTicks): MatchArtistAction[] {
  return comparison.artistActions.filter((action) => action.requiredRows.every((key) => ticks[key] === true))
}

/**
 * What an apply takes from the ticks: each ticked row that would change something, as the admin
 * saw it, and every listed merge or removal, applied while it stands and stays ticked, else kept.
 */
export function buildMatchSelection(comparison: MatchComparison, ticks: MatchTicks): MatchSelection {
  const applied = standingArtistActions(comparison, ticks)
    .filter((action) => ticks[artistActionKey(action)] === true)
    .map((action) => action.artistPublicId)

  return {
    fields: comparisonFieldRows(comparison).filter((row) => ticks[row.key] === true && isSelectable(row)),
    credits: comparisonCreditsRows(comparison).filter((row) => ticks[row.key] === true && row.changes),
    artistActions: applied,
    keptArtists: comparison.artistActions
      .map((action) => action.artistPublicId)
      .filter((artist) => !applied.includes(artist)),
  }
}

/** The keys of the rows both comparisons list whose current value differs between them. */
export function changedRowKeys(previous: MatchComparison, next: MatchComparison): string[] {
  const before = new Map<string, string>(
    [...comparisonFieldRows(previous), ...comparisonCreditsRows(previous)].map((row) => [row.key, JSON.stringify(row.current)]),
  )

  return [...comparisonFieldRows(next), ...comparisonCreditsRows(next)]
    .filter((row) => before.has(row.key) && before.get(row.key) !== JSON.stringify(row.current))
    .map((row) => row.key)
}
