import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface AlbumSummary {
  publicId: string
  title: string
  type?: string
  year?: number
  label?: string
  barcode?: string
  country?: string
  lockedFields: string[]
  createdAt: string
  coverImage?: {
    publicId: string
    blurhash?: string
  } | null
  artists?: Array<{ name: string; role?: string | null }>
}

export interface DuplicateGroup {
  albumIds: string[]
  confidence: number
  albumCount: number
  albums: AlbumSummary[]
}

export const albumDuplicatesApi = {
  getDuplicates: (libraryId: string, signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<{ data: DuplicateGroup[] }>('/api/admin/albums/duplicates', {
      signal,
      params: { libraryId },
    }).then((r) => r.data.data),

  getAlbumDuplicates: (albumPublicId: string, signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<{ data: DuplicateGroup[] }>(`/api/albums/${albumPublicId}/duplicates`, { signal }).then(
      (r) => r.data.data,
    ),
}

export function getCoverImageUrl(publicId: string): string {
  return `/api/images/${publicId}/file`
}
