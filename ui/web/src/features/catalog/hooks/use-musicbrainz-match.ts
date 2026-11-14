import { keepPreviousData, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  postAdminMetadataMatchApply,
  useGetAdminMetadataMatchCandidates,
  useGetAdminMetadataMatchComparison,
  usePostAdminMetadataMatchApply,
  type ApplyMatchRequest,
  type GetAdminMetadataMatchCandidatesParams,
  type MatchArtistCandidateResource,
  type MatchCandidatesResource,
  type MatchRecordingCandidateResource,
  type MatchReleaseCandidateResource,
} from '@/shared/api-client/gen/endpoints'
import { useLiveCoordinator } from '@/shared/live/context'
import { matchReviewsKey } from '@/features/admin/api/match-reviews-api'
import { isMatchStale, matchErrorMessage } from '../components/metadata/musicbrainz/match-error-message'
import { matchAppliedMessage } from '../components/metadata/musicbrainz/match-format'
import type { MatchCandidates, MatchComparison, MatchItemType, MatchSearch, MatchSelection } from '../components/metadata/musicbrainz/types'

type Candidate = MatchCandidatesResource['candidates'][number]

/**
 * Searches and comparisons ask MusicBrainz, which answers one request per second for the whole
 * server: each runs once when opened, is never retried or refreshed on focus, and is dropped once
 * the dialog closes, so the next opening reads the item as it is then.
 */
const MUSICBRAINZ_READ = {
  retry: false,
  staleTime: 0,
  gcTime: 0,
  refetchOnWindowFocus: false,
} as const

/** The parameters of a search: none for the item's own title and artist; an empty artist searches by the title alone. */
function searchParams(search: MatchSearch | null): GetAdminMetadataMatchCandidatesParams | undefined {
  if (search === null) {
    return undefined
  }

  return search.artist === null ? { title: search.title } : { title: search.title, artist: search.artist }
}

function dataOf<Data>(response: { data?: Data }): Data {
  if (response.data === undefined) {
    throw new Error('The MusicBrainz match response has no data.')
  }

  return response.data
}

function isRelease(candidate: Candidate): candidate is MatchReleaseCandidateResource {
  return 'mediumCount' in candidate
}

function isRecording(candidate: Candidate): candidate is MatchRecordingCandidateResource {
  return 'releases' in candidate
}

function isArtist(candidate: Candidate): candidate is MatchArtistCandidateResource {
  return 'lifeSpanBegin' in candidate
}

/** The candidates of a search, typed by the kind of item searched. */
export function toMatchCandidates(resource: MatchCandidatesResource): MatchCandidates {
  const searched = { publicId: resource.publicId, title: resource.title, artist: resource.artist }

  switch (resource.type) {
    case 'album':
      return { ...searched, type: 'album', candidates: resource.candidates.filter(isRelease) }
    case 'song':
      return { ...searched, type: 'song', candidates: resource.candidates.filter(isRecording) }
    case 'artist':
      return { ...searched, type: 'artist', candidates: resource.candidates.filter(isArtist) }
  }
}

/**
 * The MusicBrainz candidates for an item: with no search, for its own title and artist. A new
 * search keeps the previous candidates as placeholder data until it answers.
 */
export function useMatchCandidates(type: MatchItemType, publicId: string, search: MatchSearch | null) {
  return useGetAdminMetadataMatchCandidates(type, publicId, searchParams(search), {
    query: {
      ...MUSICBRAINZ_READ,
      placeholderData: keepPreviousData,
      select: (response) => toMatchCandidates(dataOf(response)),
    },
  })
}

/** The comparison of an item with a candidate; nothing is read until a candidate is picked. */
export function useMatchComparison(type: MatchItemType, publicId: string, candidateId: string | null) {
  return useGetAdminMetadataMatchComparison(type, publicId, { candidate: candidateId ?? '' }, {
    query: {
      ...MUSICBRAINZ_READ,
      enabled: candidateId !== null,
      select: (response): MatchComparison => dataOf(response),
    },
  })
}

/** The body of an apply: the candidate, each ticked row by its key with the current value it showed, and every listed merge or removal. */
export function toApplyRequest(candidate: string, selection: MatchSelection): ApplyMatchRequest {
  return {
    candidate,
    fields: selection.fields.map(({ key, current }) => ({ key, current })),
    credits: selection.credits.map(({ key, current }) => ({
      key,
      current: current.map(({ artistPublicId, role, creditedName }) => ({ artistPublicId, role, creditedName })),
    })),
    artistActions: [...selection.artistActions],
    keptArtists: [...selection.keptArtists],
  }
}

/** Catalog reads an apply can change: the item, its album's songs, and the artists it merges or removes. */
function isCatalogKey(key: readonly unknown[]): boolean {
  return typeof key[0] === 'string' && /^\/api\/(?:albums|songs|artists)\//.test(key[0])
}

/**
 * Applies the ticked rows of a comparison. A success marks the catalog and the match review list
 * stale and names what changed; a failure other than a stale comparison, which the dialog offers
 * to reload, shows its message.
 */
export function useApplyMatch() {
  const queryClient = useQueryClient()
  const coordinator = useLiveCoordinator()

  return usePostAdminMetadataMatchApply({
    mutation: {
      mutationFn: async ({ type, publicId, data }) => {
        const release = coordinator?.beginMutation(['catalog', 'lyrics', 'recommendations', 'admin-metadata'])
        try {
          return await postAdminMetadataMatchApply(type, publicId, data)
        } finally {
          release?.()
        }
      },
      onSuccess: async (response) => {
        const refetchType = coordinator ? 'none' : 'active'

        await Promise.all([
          queryClient.invalidateQueries({ predicate: (query) => isCatalogKey(query.queryKey), refetchType }),
          queryClient.invalidateQueries({ queryKey: matchReviewsKey, refetchType }),
        ])
        if (response.data !== undefined) {
          toast.success(matchAppliedMessage(response.data))
        }
      },
      onError: (error) => {
        if (!isMatchStale(error)) {
          toast.error(matchErrorMessage(error, 'The match could not be applied.'))
        }
      },
    },
  })
}
