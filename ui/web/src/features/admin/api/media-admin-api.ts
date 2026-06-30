import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface StorageStatsByType {
  type: string
  count: number
  size: number
}

export interface StorageStats {
  totalImages: number
  totalSize: number
  byType: StorageStatsByType[]
}

export interface MissingImage {
  id: string
  path: string
  type: string
}

export interface MissingCheckResult {
  totalImages: number
  missingCount: number
  missingImages: MissingImage[]
}

export interface PruneResult {
  dispatched: boolean
}

export const mediaAdminApi = {
  getStorageStats: () =>
    AXIOS_INSTANCE.get<StorageStats>('/api/admin/media/storage-stats').then(
      (r) => r.data,
    ),

  checkMissing: () =>
    AXIOS_INSTANCE.get<MissingCheckResult>('/api/admin/media/missing-check').then(
      (r) => r.data,
    ),

  pruneMissing: () =>
    AXIOS_INSTANCE.post<PruneResult>('/api/admin/media/prune-missing').then(
      (r) => r.data,
    ),
}
