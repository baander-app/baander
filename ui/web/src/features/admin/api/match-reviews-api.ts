import { useInfiniteQuery } from '@tanstack/react-query'
import {
  getAdminMetadataMatchReviews,
  type GetAdminMetadataMatchReviews200Data,
  type GetAdminMetadataMatchReviews200DataItemsItem,
  type GetAdminMetadataMatchReviews200DataItemsItemReason,
} from '@/shared/api-client/gen/endpoints'

/** One page of albums the matcher could not match confidently, most recently checked first. */
export type MatchReviewPage = GetAdminMetadataMatchReviews200Data
export type MatchReview = GetAdminMetadataMatchReviews200DataItemsItem
export type MatchReviewReason = GetAdminMetadataMatchReviews200DataItemsItemReason

const PAGE_SIZE = 50

export const matchReviewsKey = ['admin-metadata-match-reviews'] as const

/** What each review reason means to an admin. */
export const MATCH_REVIEW_REASON_LABELS: Record<MatchReviewReason, string> = {
  no_candidates: 'No candidates found',
  low_score: 'Best score too low',
  close_runner_up: 'Runner-up scores too close',
  track_count_differs: 'Track count differs',
  track_lengths_differ: 'Track lengths differ',
}

export const matchReviewsApi = {
  /** Reads the page after `cursor`, or the first page without one. */
  list: async (cursor: string | undefined, signal?: AbortSignal): Promise<MatchReviewPage> => {
    const response = await getAdminMetadataMatchReviews({ limit: PAGE_SIZE, cursor }, { signal })
    if (!response.data) {
      throw new Error('The match review list response has no data.')
    }
    return response.data
  },
}

/** The albums that need a match review, one cursor page at a time. */
export function useMatchReviews() {
  return useInfiniteQuery({
    queryKey: matchReviewsKey,
    initialPageParam: undefined as string | undefined,
    queryFn: ({ pageParam, signal }) => matchReviewsApi.list(pageParam, signal),
    getNextPageParam: (lastPage) => (lastPage.has_next_page ? lastPage.next_cursor ?? undefined : undefined),
  })
}
