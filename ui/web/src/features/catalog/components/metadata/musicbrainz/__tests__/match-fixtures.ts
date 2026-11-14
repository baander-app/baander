import type {
  CatalogCredit,
  MatchArtistAction,
  MatchCandidates,
  MatchComparison,
  MatchCreditsRow,
  MatchFieldRow,
  MatchTrack,
  ProposedCredit,
  ReleaseCandidate,
} from '../types'

export const ALBUM_ID = 'AlbumLongBed01'
export const DUET_ID = 'SongDuet00001'
export const SOLO_ID = 'SongSolo00001'
export const EXTRA_ID = 'SongExtra0001'

export const POST_MALONE = 'ArtistPostMal1'
export const DUET_ARTIST = 'ArtistPmLc001'

export function fieldRow(overrides: Partial<MatchFieldRow> & Pick<MatchFieldRow, 'field' | 'proposed'>): MatchFieldRow {
  return {
    songPublicId: null,
    current: null,
    ticked: false,
    locked: false,
    ...overrides,
  }
}

export function release(overrides: Partial<ReleaseCandidate> & Pick<ReleaseCandidate, 'id' | 'score' | 'title'>): ReleaseCandidate {
  return {
    artistCredit: 'Post Malone',
    date: '2024-08-16',
    country: 'XW',
    status: 'Official',
    disambiguation: null,
    barcode: null,
    labels: ['Mercury Records'],
    catalogNumbers: [],
    formats: ['Digital Media'],
    trackCount: 27,
    mediumCount: 1,
    releaseGroupId: 'b1a4c2a5-0000-4000-8000-000000000001',
    releaseGroupType: 'Album',
    hasFrontCover: true,
    ...overrides,
  }
}

export const ALBUM_CANDIDATES = {
  type: 'album',
  publicId: ALBUM_ID,
  title: 'F-1 Trillion: Long Bed',
  artist: 'Post Malone',
  candidates: [
    release({ id: 'release-digital', score: 96, title: 'F-1 Trillion: Long Bed', formats: ['Digital Media'], country: 'XW' }),
    release({
      id: 'release-cd',
      score: 81,
      title: 'F-1 Trillion: Long Bed',
      date: '2024-09-20',
      country: 'US',
      formats: ['CD'],
      mediumCount: 2,
      labels: ['Mercury Records', 'Republic Records'],
      disambiguation: 'clean',
    }),
  ],
} satisfies MatchCandidates

const duetCredit: CatalogCredit = {
  artistPublicId: DUET_ARTIST,
  artistName: 'Post Malone, Luke Combs',
  role: 'primary',
  creditedName: 'Post Malone, Luke Combs',
}

const proposedDuet: readonly ProposedCredit[] = [
  {
    role: 'primary',
    creditedName: 'Post Malone, Luke Combs',
    musicbrainzArtistId: 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19',
    artist: { publicId: POST_MALONE, name: 'Post Malone', takesId: true },
  },
  {
    role: 'featured',
    creditedName: 'Luke Combs',
    musicbrainzArtistId: '9d1ae2a5-4f5b-4cbb-a12a-2a1b8c08c8d1',
    artist: { publicId: null, name: 'Luke Combs', takesId: false },
  },
]

export const DUET_CREDITS: MatchCreditsRow = {
  songPublicId: DUET_ID,
  current: [duetCredit],
  proposed: proposedDuet,
  ticked: true,
}

export const MERGE_DUET_ARTIST: MatchArtistAction = {
  kind: 'merge',
  artistPublicId: DUET_ARTIST,
  artistName: 'Post Malone, Luke Combs',
  target: { publicId: POST_MALONE, name: 'Post Malone', takesId: true },
  requiredSongs: [DUET_ID],
  requiresAlbumCredits: false,
  ticked: true,
}

const pairedDuet: MatchTrack = {
  song: { publicId: DUET_ID, title: 'Pour Me a Drink', disc: 1, track: 1, lengthSeconds: 213.4 },
  release: {
    disc: 1,
    position: 1,
    number: '1',
    title: 'Pour Me a Drink',
    lengthMs: 213_000,
    artistCredit: 'Post Malone feat. Luke Combs',
    recordingId: 'rec-pour-me-a-drink',
  },
  fields: [
    fieldRow({ field: 'mbid', songPublicId: DUET_ID, proposed: 'rec-pour-me-a-drink', ticked: true }),
    fieldRow({ field: 'title', songPublicId: DUET_ID, current: 'Pour Me a Drink', proposed: 'Pour Me a Drink' }),
    fieldRow({ field: 'track', songPublicId: DUET_ID, current: 1, proposed: 1 }),
  ],
  credits: DUET_CREDITS,
}

const pairedSolo: MatchTrack = {
  song: { publicId: SOLO_ID, title: 'Wrong Ones', disc: 1, track: 2, lengthSeconds: 190 },
  release: {
    disc: 1,
    position: 2,
    number: '2',
    title: 'Wrong Ones',
    lengthMs: 189_500,
    artistCredit: 'Post Malone feat. Tim McGraw',
    recordingId: null,
  },
  fields: [fieldRow({ field: 'title', songPublicId: SOLO_ID, current: 'Wrong Ones', proposed: 'Wrong Ones' })],
  credits: null,
}

const unpairedRelease: MatchTrack = {
  song: null,
  release: {
    disc: 1,
    position: 3,
    number: '3',
    title: 'Finer Things',
    lengthMs: 201_000,
    artistCredit: 'Post Malone feat. Hank Williams Jr.',
    recordingId: 'rec-finer-things',
  },
  fields: [],
  credits: null,
}

const unpairedSong: MatchTrack = {
  song: { publicId: EXTRA_ID, title: 'Bonus Demo', disc: 1, track: 30, lengthSeconds: 95 },
  release: null,
  fields: [],
  credits: null,
}

/** An album whose year is 2023 and whose genre is empty, compared with a release of 2024 and genre "Country", its title locked. */
export function albumComparison(overrides: Partial<MatchComparison> = {}): MatchComparison {
  return {
    type: 'album',
    publicId: ALBUM_ID,
    candidateId: 'release-digital',
    fields: [
      fieldRow({ field: 'releaseMbid', proposed: 'release-digital', ticked: true }),
      fieldRow({ field: 'title', current: 'F-1 Trillion (Long Bed)', proposed: 'F-1 Trillion: Long Bed', locked: true }),
      fieldRow({ field: 'year', current: 2023, proposed: 2024 }),
      fieldRow({ field: 'genres', current: null, proposed: ['Country'], ticked: true }),
      fieldRow({ field: 'label', current: 'Mercury Records', proposed: 'Mercury Records' }),
    ],
    credits: null,
    tracks: [pairedDuet, pairedSolo, unpairedRelease, unpairedSong],
    artistActions: [MERGE_DUET_ARTIST],
    ...overrides,
  }
}

/** An album comparison with `count` paired tracks and nothing else. */
export function longAlbumComparison(count: number): MatchComparison {
  const tracks = Array.from({ length: count }, (_, index): MatchTrack => {
    const publicId = `SongLong${String(index + 1).padStart(5, '0')}`
    const title = `Track ${index + 1}`

    return {
      song: { publicId, title, disc: 1, track: index + 1, lengthSeconds: 180 },
      release: { disc: 1, position: index + 1, number: String(index + 1), title, lengthMs: 180_000, artistCredit: 'Post Malone', recordingId: `rec-${index + 1}` },
      fields: [fieldRow({ field: 'mbid', songPublicId: publicId, proposed: `rec-${index + 1}`, ticked: true })],
      credits: null,
    }
  })

  return albumComparison({ tracks, artistActions: [] })
}
