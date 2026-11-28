import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  getAdminVideoMatchesCandidates,
  getAdminVideoMatchesReviews,
  postAdminVideoMatchesApply,
  putAdminVideoMatchesAssignment,
  putAdminVideoMatchesOrdering,
  type ApplyVideoMatchRequest,
  type AssignVideoFileRequest,
  type GetAdminVideoMatchesReviewsParams,
  type VideoMatchCandidatesResource,
  type VideoMatchChoicesResource,
} from '@/shared/api-client/gen/endpoints'
import { useAdminMutationTools } from './use-admin-mutation'

export const videoMatchKeys = {
  all: ['admin-video-matches'] as const,
  reviews: (filters: VideoMatchReviewFilters) => ['admin-video-matches', 'reviews', filters] as const,
  candidates: (kind: string, publicId: string, query: string) =>
    ['admin-video-matches', 'candidates', kind, publicId, query] as const,
  choices: (kind: string, publicId: string, collection: VideoMatchChoicesCollection, page: number) =>
    ['admin-video-matches', 'choices', kind, publicId, collection, page] as const,
}

export type VideoMatchReviewFilters = Pick<GetAdminVideoMatchesReviewsParams, 'kind' | 'library' | 'reason'>
export type VideoMatchChoicesCollection = 'orderings' | 'episodes' | 'files'

export function useVideoMatchReviews(filters: VideoMatchReviewFilters) {
  return useInfiniteQuery({
    queryKey: videoMatchKeys.reviews(filters),
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam, signal }) => {
      const response = await getAdminVideoMatchesReviews({ ...filters, limit: 50, cursor: pageParam }, { signal })
      if (!response.data) throw new Error('The video review response has no data.')
      return response.data
    },
    getNextPageParam: page => page.hasNextPage ? page.nextCursor ?? undefined : undefined,
    retry: false,
  })
}

export function useVideoMatchCandidates(kind: string, publicId: string, query: string, enabled = true) {
  return useQuery({
    queryKey: videoMatchKeys.candidates(kind, publicId, query),
    queryFn: async ({ signal }): Promise<VideoMatchCandidatesResource> => {
      const response = await getAdminVideoMatchesCandidates(kind, publicId, { q: query, view: 'search' }, { signal })
      if (!response.data || !('providers' in response.data)) throw new Error('The video candidate response has no candidates.')
      return response.data
    },
    enabled,
    retry: false,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useVideoMatchChoices(
  kind: string,
  publicId: string,
  collection: VideoMatchChoicesCollection,
  page = 1,
  enabled = true,
) {
  return useQuery({
    queryKey: videoMatchKeys.choices(kind, publicId, collection, page),
    queryFn: async ({ signal }): Promise<VideoMatchChoicesResource> => {
      const response = await getAdminVideoMatchesCandidates(
        kind,
        publicId,
        { view: 'choices', collection, page, limit: 50 },
        { signal },
      )
      if (!response.data || !('view' in response.data)) throw new Error('The video edit response has no choices.')
      return response.data
    },
    enabled,
    retry: false,
    gcTime: collection === 'orderings' ? 0 : undefined,
    refetchOnWindowFocus: collection !== 'orderings',
    refetchOnReconnect: collection !== 'orderings',
  })
}

function useVideoMatchMutationTools() {
  const client = useQueryClient()
  const { run } = useAdminMutationTools(['admin-metadata', 'catalog', 'activity', 'libraries'])
  const refresh = async () => {
    await client.invalidateQueries({
      predicate: query => (query.queryKey[0] === videoMatchKeys.all[0] && query.queryKey[1] !== 'candidates')
        || query.queryKey[0] === 'catalog'
        || (typeof query.queryKey[0] === 'string' && /^\/api\/(tv|movies|videos)(\/|$)/.test(query.queryKey[0])),
      refetchType: 'active',
    })
  }
  return { run, refresh }
}

type ApplyVideoMatchVariables = ApplyVideoMatchRequest & {
  kind: string
  publicId: string
}

export function useApplyVideoMatch() {
  const { run, refresh } = useVideoMatchMutationTools()
  return useMutation({
    mutationFn: ({ kind, publicId, provider, candidateId }: ApplyVideoMatchVariables) =>
      run(() => postAdminVideoMatchesApply(kind, publicId, { provider, candidateId })),
    onSuccess: refresh,
  })
}

export function useSetVideoOrdering() {
  const { run, refresh } = useVideoMatchMutationTools()
  return useMutation({
    mutationFn: ({ publicId, ordering }: { publicId: string; ordering: string }) =>
      run(() => putAdminVideoMatchesOrdering(publicId, { ordering })),
    onSuccess: refresh,
  })
}

export function useAssignVideoFile() {
  const { run, refresh } = useVideoMatchMutationTools()
  return useMutation({
    mutationFn: ({ publicId, ...assignment }: AssignVideoFileRequest & { publicId: string }) =>
      run(() => putAdminVideoMatchesAssignment(publicId, assignment)),
    onSuccess: refresh,
  })
}
