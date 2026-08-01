import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface RecommendationCoverage {
  total_tracks: number
  tracks_with_recommendations: number
  tracks_without_recommendations: number
  coverage_percentage: number
}

export interface SourceQuality {
  by_source_type: Record<string, number>
  avg_confidence_score: number
}

export interface RecommendationFreshness {
  avg_age_seconds: number
  last_generated_at: string | null
}

export interface GenerateRecommendationsRequest {
  mode: 'full' | 'incremental'
}

export interface RecommendationJob {
  id: string
  public_id: string
  status: 'pending' | 'in_progress' | 'completed' | 'failed' | 'cancelled'
  is_full: boolean
  total_songs: number
  completed_songs: number
  current_strategy: string | null
  strategy_counts: Record<string, number> | null
  progress_percentage: number
  created_at: string
  started_at: string | null
  completed_at: string | null
  fail_reason: string | null
  metadata: Record<string, unknown>
  original_job_id: string | null
}

export interface AsyncJobResponse {
  job_id: string
  public_id: string
  mode: 'full' | 'incremental'
  status: string
  execution: 'async'
}

export interface SyncJobResponse {
  counts: Record<string, number>
  execution: 'sync'
}

export type GenerateRecommendationsResponse = AsyncJobResponse | SyncJobResponse

export const recommendationsAdminApi = {
  getCoverage: () =>
    AXIOS_INSTANCE.get<{ data: RecommendationCoverage }>('/api/admin/recommendations/coverage'),
  getSourceQuality: () =>
    AXIOS_INSTANCE.get<{ data: SourceQuality }>('/api/admin/recommendations/source-quality'),
  getFreshness: () =>
    AXIOS_INSTANCE.get<{ data: RecommendationFreshness }>('/api/admin/recommendations/freshness'),
  generate: (request: GenerateRecommendationsRequest) =>
    AXIOS_INSTANCE.post<{ data: GenerateRecommendationsResponse }>('/api/admin/recommendations/generate', request),
  getJob: (publicId: string) =>
    AXIOS_INSTANCE.get<{ data: RecommendationJob }>(`/api/admin/recommendations/jobs/${publicId}`),
  listJobs: (params?: { limit?: number; status?: string }) =>
    AXIOS_INSTANCE.get<{ data: RecommendationJob[] }>('/api/admin/recommendations/jobs', { params }),
  cancelJob: (publicId: string) =>
    AXIOS_INSTANCE.delete(`/api/admin/recommendations/jobs/${publicId}`),
  requeueJob: (publicId: string) =>
    AXIOS_INSTANCE.post<{ data: { job_id: string; public_id: string; mode: 'full' | 'incremental'; status: string } }>(
      `/api/admin/recommendations/jobs/${publicId}/requeue`
    ),
}
