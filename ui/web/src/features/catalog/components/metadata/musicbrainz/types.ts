/**
 * View models of a MusicBrainz match: the candidates a search finds and the comparison of an item
 * with one candidate. They follow the API's match resources field for field, except that a song's
 * field rows name their song, and internal artist IDs are left out.
 */

/** The kind of library item being matched: an album matches a release, a song a recording, an artist an artist. */
export type MatchItemType = 'album' | 'song' | 'artist'

/** A field of an album, song or artist that a match can set. Where the item can lock it, this is the name it locks it by. */
export type MatchField =
  | 'mbid'
  | 'releaseMbid'
  | 'title'
  | 'year'
  | 'label'
  | 'catalogNumber'
  | 'barcode'
  | 'country'
  | 'disambiguation'
  | 'genres'
  | 'cover'
  | 'track'
  | 'disc'
  | 'name'
  | 'sortName'
  | 'type'
  | 'lifeSpanBegin'
  | 'lifeSpanEnd'

/** A field's value: text, a number such as a year, or an album's genre names. */
export type MatchFieldValue = string | number | readonly string[]

/** What a search asks MusicBrainz for: the title (an artist's name for an artist) and the artist, null when it names none. */
export interface MatchSearch {
  readonly title: string
  readonly artist: string | null
}

/** A MusicBrainz release (one edition of an album) an album may match. */
export interface ReleaseCandidate {
  /** The release's MusicBrainz ID, which the comparison takes. */
  readonly id: string
  /** How well the release fits the album, from 0 to 100. */
  readonly score: number
  readonly title: string
  /** The release's artist credit as printed, such as "Post Malone". */
  readonly artistCredit: string
  /** YYYY, YYYY-MM or YYYY-MM-DD. */
  readonly date: string | null
  /** An ISO 3166-1 code, or "XW" for worldwide. */
  readonly country: string | null
  /** Such as "Official" or "Promotion". */
  readonly status: string | null
  readonly disambiguation: string | null
  readonly barcode: string | null
  readonly labels: readonly string[]
  readonly catalogNumbers: readonly string[]
  /** The media formats, such as "CD" or "Digital Media", each once. */
  readonly formats: readonly string[]
  readonly trackCount: number
  readonly mediumCount: number
  readonly releaseGroupId: string | null
  /** Such as "Album" or "Single". */
  readonly releaseGroupType: string | null
  /** Whether the Cover Art Archive holds a front cover for the release. */
  readonly hasFrontCover: boolean
}

/** A release a recording candidate appears on. */
export interface RecordingCandidateRelease {
  readonly id: string
  readonly title: string
  readonly date: string | null
  readonly country: string | null
  readonly status: string | null
  /** The tracks on the whole release, when MusicBrainz lists them. */
  readonly trackCount: number | null
}

/** A MusicBrainz recording a song may match. */
export interface RecordingCandidate {
  readonly id: string
  readonly score: number
  readonly title: string
  readonly artistCredit: string
  /** Null when MusicBrainz does not know the length. */
  readonly lengthMs: number | null
  readonly disambiguation: string | null
  /** At most 25: MusicBrainz lists no more. */
  readonly releases: readonly RecordingCandidateRelease[]
}

/** A MusicBrainz artist a library artist may match. */
export interface ArtistCandidate {
  readonly id: string
  readonly score: number
  readonly name: string
  readonly sortName: string | null
  readonly disambiguation: string | null
  /** Such as "Person" or "Group". */
  readonly type: string | null
  readonly country: string | null
  /** When the artist was born or began: YYYY, YYYY-MM or YYYY-MM-DD. */
  readonly lifeSpanBegin: string | null
  readonly lifeSpanEnd: string | null
  /** Whether the artist has ended, even when the end date is unknown. */
  readonly ended: boolean
}

interface CandidatesOf<Type extends MatchItemType, Candidate> {
  readonly type: Type
  /** The item's public ID. */
  readonly publicId: string
  /** The title or artist name searched. */
  readonly title: string
  /** The artist searched; null when the search named none. */
  readonly artist: string | null
  /** At most five, best fit first. */
  readonly candidates: readonly Candidate[]
}

/** A search's answer: what was searched and the candidates found, best first. */
export type MatchCandidates =
  | CandidatesOf<'album', ReleaseCandidate>
  | CandidatesOf<'song', RecordingCandidate>
  | CandidatesOf<'artist', ArtistCandidate>

/**
 * One field of a comparison: the value the item holds, the value MusicBrainz gives, and whether
 * the row is ticked by default. A locked row is never ticked and cannot be.
 */
export interface MatchFieldRow {
  readonly field: MatchField
  /** The album's song the row belongs to; null for the item's own field. */
  readonly songPublicId: string | null
  /** The value the item holds now; null when empty. An apply rechecks it. */
  readonly current: MatchFieldValue | null
  readonly proposed: MatchFieldValue
  readonly ticked: boolean
  readonly locked: boolean
}

/** An artist credit a song or album holds now. */
export interface CatalogCredit {
  readonly artistPublicId: string
  readonly artistName: string
  /** An artist role such as "primary" or "featured"; null for a credit without a role. */
  readonly role: string | null
  /** The name the item is credited under, such as "Post Malone, Luke Combs". */
  readonly creditedName: string
}

/** The local artist a MusicBrainz credit would resolve to if the match were applied now. */
export interface PredictedArtist {
  /** Null for an artist the match would create. */
  readonly publicId: string | null
  /** The artist's name, or the name a new artist would get. */
  readonly name: string
  /** Whether a stored artist without a MusicBrainz ID would take on the credit's ID. */
  readonly takesId: boolean
}

/** An artist credit a match would give a song or album. */
export interface ProposedCredit {
  /** Primary for the first artist MusicBrainz credits, featured for the rest. */
  readonly role: 'primary' | 'featured'
  readonly creditedName: string
  readonly musicbrainzArtistId: string
  readonly artist: PredictedArtist
}

/** The primary and featured credits of a song or album now and as a match would set them. */
export interface MatchCreditsRow {
  /**
   * The song the credits belong to, as the API gives it: a song comparison's own row names its
   * song too, and the merges it causes require that song. Null only for an album's own credits.
   */
  readonly songPublicId: string | null
  /** The credits now; an apply rechecks them. */
  readonly current: readonly CatalogCredit[]
  /** In MusicBrainz's credit order. */
  readonly proposed: readonly ProposedCredit[]
  readonly ticked: boolean
}

/** A local song as an album comparison pairs it with a release track. */
export interface ComparedSong {
  readonly publicId: string
  readonly title: string
  readonly disc: number | null
  readonly track: number | null
  /** The length measured from the audio, in seconds. */
  readonly lengthSeconds: number | null
}

/** A track of the chosen release. */
export interface ComparedReleaseTrack {
  /** The position of the track's medium in the release, from 1. */
  readonly disc: number
  /** The track's position on its medium, from 1. */
  readonly position: number
  /** The track number as printed, such as "A2". */
  readonly number: string
  readonly title: string
  readonly lengthMs: number | null
  readonly artistCredit: string
  /** Null when the editions the candidate stands for disagree on it. */
  readonly recordingId: string | null
}

/**
 * One line of an album comparison: a song paired with a release track, with the song's rows, or a
 * track or song left unpaired on either side. An apply writes paired songs only.
 */
export interface MatchTrack {
  /** Null for a release track no local song pairs with. */
  readonly song: ComparedSong | null
  /** Null for a local song that pairs with no release track. */
  readonly release: ComparedReleaseTrack | null
  /** The song's rows; empty unless paired. */
  readonly fields: readonly MatchFieldRow[]
  /** The song's credits row; null unless paired. */
  readonly credits: MatchCreditsRow | null
}

export type ArtistActionKind = 'merge' | 'remove'

/**
 * An artist a match would merge into another or remove, ticked by default. It stands only while
 * every credits row it requires is ticked; unticking it keeps the artist as it is.
 */
export interface MatchArtistAction {
  readonly kind: ArtistActionKind
  readonly artistPublicId: string
  readonly artistName: string
  /** The artist a merge goes into, which may be one the match creates; null for a removal. */
  readonly target: PredictedArtist | null
  /** The public IDs of the songs whose credits rows must all be ticked. */
  readonly requiredSongs: readonly string[]
  /** Whether the album's own credits row must be ticked too. */
  readonly requiresAlbumCredits: boolean
  readonly ticked: boolean
}

/** Everything a match of the item with one candidate would set, row by row. */
export interface MatchComparison {
  readonly type: MatchItemType
  readonly publicId: string
  /** The MusicBrainz ID compared: a release, recording or artist. */
  readonly candidateId: string
  /** The item's own fields. */
  readonly fields: readonly MatchFieldRow[]
  /** The album's or song's own credits; null for an artist. */
  readonly credits: MatchCreditsRow | null
  /** An album's songs and the release's tracks in release order, then the songs that pair with no track. */
  readonly tracks: readonly MatchTrack[]
  readonly artistActions: readonly MatchArtistAction[]
}

/** Which rows and artist actions are ticked, by `fieldRowKey`, `creditsRowKey` and `artistActionKey`. */
export type MatchTicks = Readonly<Record<string, boolean>>

/** A ticked field row with the current value it showed. */
export interface SeenField {
  readonly field: MatchField
  readonly current: MatchFieldValue | null
  readonly songPublicId: string | null
}

/** One credit as the admin saw it. */
export interface SeenCredit {
  readonly artistPublicId: string
  readonly role: string | null
  readonly creditedName: string
}

/** A ticked credits row with the credits it showed. */
export interface SeenCredits {
  readonly songPublicId: string | null
  readonly current: readonly SeenCredit[]
}

/**
 * What an admin chose in a comparison, as an apply takes it: the ticked rows with the values they
 * showed, and every listed artist merge or removal, either ticked or kept.
 */
export interface MatchSelection {
  readonly fields: readonly SeenField[]
  readonly credits: readonly SeenCredits[]
  /** The public IDs of the artists whose merge or removal stays ticked. */
  readonly artistActions: readonly string[]
  /** The public IDs of the artists to keep as they are. */
  readonly keptArtists: readonly string[]
}
