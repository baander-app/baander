import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
import { recommendationsAdminApi } from '../api/recommendations-admin-api'
import type { RecommendationJob } from '../api/recommendations-admin-api'

export function useRecommendationCoverage() {
  return useQuery({
    queryKey: ['admin', 'recommendations', 'coverage'],
    queryFn: async ({ signal }) => {
      const { data } = await recommendationsAdminApi.getCoverage(signal)
      return data.data
    },
  })
}

export function useSourceQuality() {
  return useQuery({
    queryKey: ['admin', 'recommendations', 'source-quality'],
    queryFn: async ({ signal }) => {
      const { data } = await recommendationsAdminApi.getSourceQuality(signal)
      return data.data
    },
  })
}

export function useRecommendationFreshness() {
  return useQuery({
    queryKey: ['admin', 'recommendations', 'freshness'],
    queryFn: async ({ signal }) => {
      const { data } = await recommendationsAdminApi.getFreshness(signal)
      return data.data
    },
  })
}

export function useRecommendationJobs(limit = 10) {
  return useQuery<RecommendationJob[]>({
    queryKey: ['admin', 'recommendations', 'jobs', limit],
    queryFn: async ({ signal }) => {
      const { data } = await recommendationsAdminApi.listJobs({ limit }, signal)
      return data.data
    },

  })
}

export function useRecommendationJob(publicId: string) {
  return useQuery<RecommendationJob>({
    queryKey: ['admin', 'recommendations', 'job', publicId],
    queryFn: async ({ signal }) => {
      const { data } = await recommendationsAdminApi.getJob(publicId, signal)
      return data.data
    },
    enabled: !!publicId,

  })
}

export function useGenerateRecommendations() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'admin-jobs', 'recommendations'])

  return useMutation({
    mutationFn: (params: { mode: 'full' | 'incremental' }) =>
      run(() => recommendationsAdminApi.generate(params)),
    onSuccess: () => {
      invalidate(['admin', 'recommendations', 'jobs'])
    },
  })
}

export function useCancelRecommendationJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'admin-jobs', 'recommendations'])

  return useMutation({
    mutationFn: (publicId: string) =>
      run(() => recommendationsAdminApi.cancelJob(publicId)),
    onSuccess: () => {
      invalidate(['admin', 'recommendations', 'jobs'])
    },
  })
}

export function useRequeueRecommendationJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'admin-jobs', 'recommendations'])

  return useMutation({
    mutationFn: (publicId: string) =>
      run(() => recommendationsAdminApi.requeueJob(publicId)),
    onSuccess: () => {
      invalidate(['admin', 'recommendations', 'jobs'])
    },
  })
}
