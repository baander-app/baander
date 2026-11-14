import { describe, expect, it } from 'vitest'
import { toMatchCandidates } from '../use-musicbrainz-match'
import type { ArtistCandidate, RecordingCandidate } from '../../components/metadata/musicbrainz/types'

const recording: RecordingCandidate = {
  id: 'rec-pour-me-a-drink',
  score: 93,
  title: 'Pour Me a Drink',
  artistCredit: 'Post Malone feat. Blake Shelton',
  lengthMs: 213_000,
  disambiguation: null,
  releases: [],
}

const artist: ArtistCandidate = {
  id: 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19',
  score: 100,
  name: 'Post Malone',
  sortName: 'Malone, Post',
  disambiguation: null,
  type: 'Person',
  country: 'US',
  lifeSpanBegin: '1995-07-04',
  lifeSpanEnd: null,
  ended: false,
}

describe('toMatchCandidates', () => {
  it('types a song search’s candidates as recordings', () => {
    const candidates = toMatchCandidates({ type: 'song', publicId: 'SongDuet00001', title: 'Pour Me a Drink', artist: 'Post Malone', candidates: [recording] })

    expect(candidates).toEqual({ type: 'song', publicId: 'SongDuet00001', title: 'Pour Me a Drink', artist: 'Post Malone', candidates: [recording] })
  })

  it('types an artist search’s candidates as artists', () => {
    const candidates = toMatchCandidates({ type: 'artist', publicId: 'ArtistPostMal1', title: 'Post Malone', artist: null, candidates: [artist] })

    expect(candidates.type).toBe('artist')
    expect(candidates.candidates).toEqual([artist])
  })
})
