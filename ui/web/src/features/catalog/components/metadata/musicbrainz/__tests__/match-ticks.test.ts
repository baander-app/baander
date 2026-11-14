import { describe, expect, it } from 'vitest'
import { artistActionKey, buildMatchSelection, changedRowKeys, defaultTicks, standingArtistActions } from '../match-ticks'
import type { MatchComparison } from '../types'
import { DUET_ARTIST, DUET_CREDITS, DUET_ID, MERGE_DUET_ARTIST, albumComparison, fieldRow } from './match-fixtures'

const SONG_MBID = `${DUET_ID}:mbid`

describe('defaultTicks', () => {
  it('ticks the rows the comparison ticks, the year that differs not among them', () => {
    const ticks = defaultTicks(albumComparison())

    expect(ticks.releaseMbid).toBe(true)
    expect(ticks.genres).toBe(true)
    expect(ticks.year).toBe(false)
    expect(ticks.title).toBe(false)
    expect(ticks[SONG_MBID]).toBe(true)
    expect(ticks[DUET_CREDITS.key]).toBe(true)
    expect(ticks[artistActionKey(MERGE_DUET_ARTIST)]).toBe(true)
  })
})

describe('standingArtistActions', () => {
  it('keeps a merge while the credits row it requires is ticked', () => {
    const comparison = albumComparison()
    const ticks = defaultTicks(comparison)

    expect(standingArtistActions(comparison, ticks)).toEqual([MERGE_DUET_ARTIST])
    expect(standingArtistActions(comparison, { ...ticks, [DUET_CREDITS.key]: false })).toEqual([])
  })

  it('keeps the merge an artist comparison applies its ID by', () => {
    const merge = { ...MERGE_DUET_ARTIST, requiredRows: [] }
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
  it('takes the ticked rows that change something, and fills the genre while keeping the year', () => {
    const comparison = albumComparison()
    const selection = buildMatchSelection(comparison, defaultTicks(comparison))

    expect(selection.fields.map((row) => row.key)).toEqual(['releaseMbid', 'genres', SONG_MBID])
    expect(selection.credits).toEqual([DUET_CREDITS])
    expect(selection.artistActions).toEqual([DUET_ARTIST])
    expect(selection.keptArtists).toEqual([])
  })

  it('never takes a locked row or a row that changes nothing, whatever its tick', () => {
    const comparison = albumComparison()
    const ticks = { ...defaultTicks(comparison), title: true, label: true }
    const keys = buildMatchSelection(comparison, ticks).fields.map((row) => row.key)

    expect(keys).not.toContain('title')
    expect(keys).not.toContain('label')
  })

  it('keeps an artist whose merge is unticked', () => {
    const comparison = albumComparison()
    const selection = buildMatchSelection(comparison, { ...defaultTicks(comparison), [artistActionKey(MERGE_DUET_ARTIST)]: false })

    expect(selection.artistActions).toEqual([])
    expect(selection.keptArtists).toEqual([DUET_ARTIST])
  })

  it('keeps an artist whose merge went with its unticked credits row', () => {
    const comparison = albumComparison()
    const selection = buildMatchSelection(comparison, { ...defaultTicks(comparison), [DUET_CREDITS.key]: false })

    expect(selection.credits).toEqual([])
    expect(selection.artistActions).toEqual([])
    expect(selection.keptArtists).toEqual([DUET_ARTIST])
  })
})

describe('changedRowKeys', () => {
  it('lists the rows whose current value differs after a reload', () => {
    const before = albumComparison()
    const after = albumComparison({
      fields: before.fields.map((row) => (row.key === 'year' ? fieldRow({ field: 'year', current: 2022, proposed: 2024 }) : row)),
      tracks: before.tracks.map((track) =>
        track.credits === null ? track : { ...track, credits: { ...track.credits, current: [] } },
      ),
    })

    expect(changedRowKeys(before, after)).toEqual(['year', DUET_CREDITS.key])
    expect(changedRowKeys(before, albumComparison())).toEqual([])
  })
})
