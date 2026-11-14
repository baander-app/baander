import type {
  MatchArtistActionResource,
  MatchArtistCandidateResource,
  MatchComparisonResource,
  MatchCreditsRowResource,
  MatchCreditsRowResourceCurrentItem,
  MatchCreditsRowResourceProposedItem,
  MatchFieldRowResource,
  MatchFieldRowResourceField,
  MatchPredictedArtistResource,
  MatchRecordingCandidateResource,
  MatchRecordingCandidateResourceReleasesItem,
  MatchReleaseCandidateResource,
  MatchTrackResource,
  MatchTrackResourceRelease,
  MatchTrackResourceSong,
} from '@/shared/api-client/gen/endpoints'

/**
 * The shapes a MusicBrainz match works with. A comparison is the API's comparison resource as it
 * comes; candidates are told apart by the kind of item searched, which the API's list leaves open.
 */

/** The kind of library item being matched: an album matches a release, a song a recording, an artist an artist. */
export type MatchItemType = 'album' | 'song' | 'artist'

/** A field of an album, song or artist that a match can set. */
export type MatchField = MatchFieldRowResourceField

/** A value MusicBrainz gives a field: text, a number such as a year, or an album's genre names. */
export type MatchFieldValue = MatchFieldRowResource['proposed']

/** What a search asks MusicBrainz for: the title (an artist's name for an artist) and the artist, null for an artist search. */
export interface MatchSearch {
  readonly title: string
  /** An empty artist searches by the title alone. */
  readonly artist: string | null
}

export type ReleaseCandidate = MatchReleaseCandidateResource
export type RecordingCandidate = MatchRecordingCandidateResource
export type RecordingCandidateRelease = MatchRecordingCandidateResourceReleasesItem
export type ArtistCandidate = MatchArtistCandidateResource

interface CandidatesOf<Type extends MatchItemType, Candidate> {
  readonly type: Type
  /** The item's public ID. */
  readonly publicId: string
  /** The title or artist name searched. */
  readonly title: string
  /** The artist searched; null for an artist search, or when the item has none and the search named none. */
  readonly artist: string | null
  /** At most five, best fit first. */
  readonly candidates: readonly Candidate[]
}

/** A search's answer: what was searched and the candidates found, best first. */
export type MatchCandidates =
  | CandidatesOf<'album', ReleaseCandidate>
  | CandidatesOf<'song', RecordingCandidate>
  | CandidatesOf<'artist', ArtistCandidate>

/** Everything a match of the item with one candidate would set, row by row, each row with its key, default tick and lock. */
export type MatchComparison = MatchComparisonResource
export type MatchFieldRow = MatchFieldRowResource
export type MatchCreditsRow = MatchCreditsRowResource
export type CatalogCredit = MatchCreditsRowResourceCurrentItem
export type ProposedCredit = MatchCreditsRowResourceProposedItem
export type PredictedArtist = MatchPredictedArtistResource
export type MatchTrack = MatchTrackResource
export type ComparedSong = NonNullable<MatchTrackResourceSong>
export type ComparedReleaseTrack = NonNullable<MatchTrackResourceRelease>
export type MatchArtistAction = MatchArtistActionResource

/** Which rows and artist actions are ticked: rows by their key, actions by `artistActionKey`. */
export type MatchTicks = Readonly<Record<string, boolean>>

/**
 * What an admin chose in a comparison: the ticked rows that would change something, as the admin
 * saw them, and every listed merge or removal, either applied or kept.
 */
export interface MatchSelection {
  readonly fields: readonly MatchFieldRow[]
  readonly credits: readonly MatchCreditsRow[]
  /** The public IDs of the artists whose merge or removal stands and stays ticked. */
  readonly artistActions: readonly string[]
  /** The public IDs of the other listed artists, which stay as they are. */
  readonly keptArtists: readonly string[]
}
