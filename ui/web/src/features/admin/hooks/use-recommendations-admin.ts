import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { recommendationsAdminApi } from '../api/recommendations-admin-api'
import type { RecommendationJob } from '../api/recommendations-admin-api'

export function useRecommendationCoverage() {
  return useQuery({
    queryKey: ['admin', 'recommendations', 'coverage'],
    queryFn: async () => {
      const { data } = await recommendationsAdminApi.getCoverage()
      return data.data
    },
  })
}

export function useSourceQuality() {
  return useQuery({
    queryKey: ['admin', 'recommendations', 'source-quality'],
    queryFn: async () => {
      const { data } = await recommendationsAdminApi.getSourceQuality()
      return data.data
    },
  })
}

export function useRecommendationFreshness() {
  return useQuery({
    queryKey: ['admin', 'recommendations', 'freshness'],
    queryFn: async () => {
      const { data } = await recommendationsAdminApi.getFreshness()
      return data.data
    },
  })
}

export function useRecommendationJobs(limit = 10) {
  return useQuery<RecommendationJob[]>({
    queryKey: ['admin', 'recommendations', 'jobs', limit],
    queryFn: async () => {
      const { data } = await recommendationsAdminApi.listJobs({ limit })
      return data.data
    },
    refetchInterval: (query) => {
      // Poll every 2 seconds if there are any active/in_progress jobs
      const hasActiveJobs = query.state.data?.some(
        (job) => job.status === 'pending' || job.status === 'in_progress'
      )
      return hasActiveJobs ? 2000 : false
    },
  })
}

export function useRecommendationJob(publicId: string) {
  return useQuery<RecommendationJob>({
    queryKey: ['admin', 'recommendations', 'job', publicId],
    queryFn: async () => {
      const { data } = await recommendationsAdminApi.getJob(publicId)
      return data.data
    },
    enabled: !!publicId,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return (status === 'pending' || status === 'in_progress') ? 2000 : false
    },
  })
}

export function useGenerateRecommendations() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (params: { mode: 'full' | 'incremental' }) =>
      recommendationsAdminApi.generate(params),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'recommendations', 'jobs'] })
    },
  })
}

export function useCancelRecommendationJob() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (publicId: string) =>
      recommendationsAdminApi.cancelJob(publicId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'recommendations', 'jobs'] })
    },
  })
}

export function useRequeueRecommendationJob() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (publicId: string) =>
      recommendationsAdminApi.requeueJob(publicId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'recommendations', 'jobs'] })
    },
  })
}
