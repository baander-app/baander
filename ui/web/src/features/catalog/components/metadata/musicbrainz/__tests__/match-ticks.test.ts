import { describe, expect, it } from 'vitest'
import {
  artistActionKey,
  buildMatchSelection,
  creditsRowKey,
  defaultTicks,
  fieldRowChanges,
  fieldRowKey,
  standingArtistActions,
} from '../match-ticks'
import type { MatchComparison } from '../types'
import {
  DUET_ARTIST,
  DUET_CREDITS,
  DUET_ID,
  MERGE_DUET_ARTIST,
  albumComparison,
  fieldRow,
} from './match-fixtures'

describe('match row keys', () => {
  it('tells an album field from the same field of one of its songs', () => {
    expect(fieldRowKey({ field: 'year', songPublicId: null })).not.toBe(fieldRowKey({ field: 'year', songPublicId: DUET_ID }))
    expect(creditsRowKey({ songPublicId: null })).not.toBe(creditsRowKey({ songPublicId: DUET_ID }))
    expect(artistActionKey(MERGE_DUET_ARTIST)).not.toBe(creditsRowKey({ songPublicId: DUET_ARTIST }))
  })
})

describe('defaultTicks', () => {
  it('ticks the rows the comparison ticks, the year that differs not among them', () => {
    const ticks = defaultTicks(albumComparison())

    expect(ticks[fieldRowKey({ field: 'releaseMbid', songPublicId: null })]).toBe(true)
    expect(ticks[fieldRowKey({ field: 'genres', songPublicId: null })]).toBe(true)
    expect(ticks[fieldRowKey({ field: 'year', songPublicId: null })]).toBe(false)
    expect(ticks[fieldRowKey({ field: 'title', songPublicId: null })]).toBe(false)
    expect(ticks[fieldRowKey({ field: 'mbid', songPublicId: DUET_ID })]).toBe(true)
    expect(ticks[creditsRowKey(DUET_CREDITS)]).toBe(true)
    expect(ticks[artistActionKey(MERGE_DUET_ARTIST)]).toBe(true)
  })
})

describe('fieldRowChanges', () => {
  it('compares genre names ignoring case and order', () => {
    expect(fieldRowChanges(fieldRow({ field: 'genres', current: ['country', 'Pop'], proposed: ['pop', 'Country'] }))).toBe(false)
    expect(fieldRowChanges(fieldRow({ field: 'genres', current: ['Pop'], proposed: ['Country'] }))).toBe(true)
  })

  it('counts an empty value as a change and an equal one as none', () => {
    expect(fieldRowChanges(fieldRow({ field: 'year', current: null, proposed: 2024 }))).toBe(true)
    expect(fieldRowChanges(fieldRow({ field: 'year', current: 2024, proposed: 2024 }))).toBe(false)
  })
})

describe('standingArtistActions', () => {
  it('keeps a merge while the credits row it requires is ticked', () => {
    const comparison = albumComparison()
    const ticks = defaultTicks(comparison)

    expect(standingArtistActions(comparison, ticks)).toEqual([MERGE_DUET_ARTIST])
    expect(standingArtistActions(comparison, { ...ticks, [creditsRowKey(DUET_CREDITS)]: false })).toEqual([])
  })

  it('drops a merge that requires the album credits once they are unticked', () => {
    const albumCredits = { ...DUET_CREDITS, songPublicId: null }
    const action = { ...MERGE_DUET_ARTIST, requiredSongs: [], requiresAlbumCredits: true }
    const comparison: MatchComparison = albumComparison({ credits: albumCredits, artistActions: [action] })
    const ticks = defaultTicks(comparison)

    expect(standingArtistActions(comparison, ticks)).toEqual([action])
    expect(standingArtistActions(comparison, { ...ticks, [creditsRowKey(albumCredits)]: false })).toEqual([])
  })

  it('keeps the merge an artist comparison applies its ID by', () => {
    const merge = { ...MERGE_DUET_ARTIST, requiredSongs: [] }
    const comparison: MatchComparison = {
      type: 'artist',
      publicId: DUET_ARTIST,
      candidateId: 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19',
      fields: [],
      credits: null,
      tracks: [],
      artistActions: [merge],
    }

    expect(standingArtistActions(comparison, defaultTicks(comparison))).toEqual([merge])
  })
})

describe('buildMatchSelection', () => {
  it('sends the ticked rows with the values they showed, and fills the genre while keeping the year', () => {
    const selection = buildMatchSelection(albumComparison(), defaultTicks(albumComparison()))

    expect(selection.fields).toEqual([
      { field: 'releaseMbid', current: null, songPublicId: null },
      { field: 'genres', current: null, songPublicId: null },
      { field: 'mbid', current: null, songPublicId: DUET_ID },
    ])
    expect(selection.credits).toEqual([
      {
        songPublicId: DUET_ID,
        current: [{ artistPublicId: DUET_ARTIST, role: 'primary', creditedName: 'Post Malone, Luke Combs' }],
      },
    ])
    expect(selection.artistActions).toEqual([DUET_ARTIST])
    expect(selection.keptArtists).toEqual([])
  })

  it('sends a ticked year with the year the album holds', () => {
    const comparison = albumComparison()
    const ticks = { ...defaultTicks(comparison), [fieldRowKey({ field: 'year', songPublicId: null })]: true }

    expect(buildMatchSelection(comparison, ticks).fields).toContainEqual({ field: 'year', current: 2023, songPublicId: null })
  })

  it('never sends a locked row or a row that changes nothing, whatever its tick', () => {
    const comparison = albumComparison()
    const ticks = {
      ...defaultTicks(comparison),
      [fieldRowKey({ field: 'title', songPublicId: null })]: true,
      [fieldRowKey({ field: 'label', songPublicId: null })]: true,
    }
    const fields = buildMatchSelection(comparison, ticks).fields.map((row) => row.field)

    expect(fields).not.toContain('title')
    expect(fields).not.toContain('label')
  })

  it('keeps an artist whose merge is unticked', () => {
    const comparison = albumComparison()
    const ticks = { ...defaultTicks(comparison), [artistActionKey(MERGE_DUET_ARTIST)]: false }
    const selection = buildMatchSelection(comparison, ticks)

    expect(selection.artistActions).toEqual([])
    expect(selection.keptArtists).toEqual([DUET_ARTIST])
  })

  it('keeps an artist whose merge went with its unticked credits row', () => {
    const comparison = albumComparison()
    const ticks = { ...defaultTicks(comparison), [creditsRowKey(DUET_CREDITS)]: false }
    const selection = buildMatchSelection(comparison, ticks)

    expect(selection.credits).toEqual([])
    expect(selection.artistActions).toEqual([])
    expect(selection.keptArtists).toEqual([DUET_ARTIST])
  })
})
